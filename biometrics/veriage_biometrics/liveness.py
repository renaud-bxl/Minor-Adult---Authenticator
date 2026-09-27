"""Contrôle du vivant (liveness) sur une courte séquence d'images, avec des défis tirés au hasard par le
serveur PHP (tourner la tête à gauche, à droite, fermer les yeux, ouvrir la bouche), dans un ordre imposé :
4 défis, jamais deux identiques de suite, soit 4 × 3 × 3 × 3 = 108 suites possibles.

NIVEAU D'ASSURANCE : FAIBLE À MODÉRÉ, sans certification (ISO/IEC 30107-3). Ce contrôle arrête une photo
fixe ou pivotée, une vidéo rejouée qui ne suit pas les défis, un visage qui change en cours de séquence, et le
portrait du document lui-même animé (voir analysis.py, « face_identical_to_document »). Il N'ARRÊTE PAS une
autre photo de la personne animée en 2D et injectée (caméra virtuelle, remplacement de getUserMedia), ni un
échange de visage en temps réel (deepfake) : aucune détection d'injection ni de deepfake.

Mesures par image : MediaPipe Face Landmarker (Apache-2.0), 478 points du visage :
- lacet (« yaw ») : décalage horizontal de la pointe du nez par rapport au milieu des joues, rapporté à
  la demi-largeur du visage (≈ 0 de face, positif quand le nez part vers la droite de l'image, c'est-à-dire
  quand la personne tourne la tête vers SA gauche : l'image de la caméra n'est pas en miroir). Une photo
  plane que l'on fait pivoter ne déplace PAS le nez par rapport aux joues : ce défi résiste à une photo plane
  PIVOTÉE, mais pas à une photo DÉFORMÉE (animation 2D qui décale le nez), ce que l'E2E démontre ;
- ouverture des yeux : rapport d'aspect de l'œil (EAR, Soukupová et Čech, 2016), moyenne des deux yeux ;
- ouverture de la bouche : écart des lèvres intérieures (points 13 et 14) rapporté à la largeur de la bouche
  (points 78 et 308), comparé à la valeur de référence de la fenêtre initiale.
Repli si MediaPipe est indisponible : les 5 points de YuNet donnent un lacet approché, mais pas de
mesure des paupières ; un défi « cligner » échoue alors avec le motif liveness_blink_unsupported.

Cohérence temporelle : un seul visage par image, le même (similarité SFace avec l'image de référence),
horodatages croissants, chaque défi dans sa propre fenêtre et dans l'ordre, durée minimale par défi,
aucun mouvement contraire dans la fenêtre d'un défi (une vidéo en boucle qui enchaîne tous les
mouvements échoue).
"""
from __future__ import annotations

import statistics
import threading
from dataclasses import dataclass, field
from pathlib import Path

import numpy as np

from .faces import Face

CHALLENGES = ("turn_left", "turn_right", "blink", "open_mouth")
LANDMARKER = "face_landmarker.task"

NOSE_TIP, CHEEK_A, CHEEK_B = 1, 234, 454
EYE_A = (33, 160, 158, 133, 153, 144)
EYE_B = (362, 385, 387, 263, 373, 380)
LIP_TOP, LIP_BOTTOM, MOUTH_A, MOUTH_B = 13, 14, 78, 308


@dataclass(frozen=True)
class Params:
    """Seuils du contrôle, envoyés par PHP (configurables dans .env) et bornés ici."""

    yaw_threshold: float = 0.28
    neutral_max_yaw: float = 0.15
    blink_ratio: float = 0.65
    same_face_min: float = 0.30
    min_frames: int = 8
    max_frames: int = 90
    min_step_ms: int = 600
    max_missing_ratio: float = 0.34
    replay_threshold: float = 30.0
    mouth_open_min: float = 0.35
    mouth_open_delta: float = 0.15
    # Selfie « identique » au portrait du document (même photo animée) : au-delà, refus.
    identical_max: float = 0.92

    BOUNDS = {
        "yaw_threshold": (0.1, 0.8), "neutral_max_yaw": (0.05, 0.4), "blink_ratio": (0.3, 0.9),
        "same_face_min": (0.1, 0.9), "min_frames": (3, 60), "max_frames": (10, 120), "min_step_ms": (0, 5000),
        "max_missing_ratio": (0.0, 0.8), "replay_threshold": (1.0, 1000.0),
        "mouth_open_min": (0.15, 0.9), "mouth_open_delta": (0.05, 0.6), "identical_max": (0.8, 1.0),
    }

    @classmethod
    def from_request(cls, data: object) -> "Params":
        if data is None:
            return cls()
        if not isinstance(data, dict):
            raise ValueError("liveness_params_invalid")
        values = {}
        for name, (low, high) in cls.BOUNDS.items():
            if name not in data:
                continue
            value = data[name]
            if isinstance(value, bool) or not isinstance(value, (int, float)) or not low <= value <= high:
                raise ValueError("liveness_params_invalid")
            values[name] = int(value) if isinstance(getattr(cls, name), int) else float(value)
        if set(data) - set(cls.BOUNDS):
            raise ValueError("liveness_params_invalid")
        return cls(**values)


@dataclass(frozen=True)
class Measure:
    """Ce que l'on retient d'une image de la séquence (aucune image, aucun point du visage)."""

    t_ms: int
    step: int
    faces: int
    score: float = 0.0
    yaw: float | None = None
    ear: float | None = None
    similarity: float | None = None  # à l'image de référence (renseignée après coup)
    mar: float | None = None  # ouverture de la bouche


@dataclass
class Result:
    passed: bool
    reasons: list[str] = field(default_factory=list)
    reference: int | None = None  # indice de l'image de référence (de face, yeux ouverts)


# -- Mesures -------------------------------------------------------------------------------------

class Landmarker:
    """MediaPipe Face Landmarker en mode IMAGE (une image à la fois, sans état entre requêtes)."""

    def __init__(self, model_dir: Path) -> None:
        from mediapipe.tasks.python import BaseOptions, vision  # import tardif : paquet lourd

        path = model_dir / LANDMARKER
        if not path.is_file():
            raise FileNotFoundError("Modèle MediaPipe absent : lancez scripts/fetch_models.sh")
        options = vision.FaceLandmarkerOptions(
            base_options=BaseOptions(model_asset_path=str(path)),
            running_mode=vision.RunningMode.IMAGE,
            num_faces=2,
            min_face_detection_confidence=0.5,
            min_face_presence_confidence=0.5,
        )
        self._landmarker = vision.FaceLandmarker.create_from_options(options)
        self._lock = threading.Lock()

    def measure(self, bgr: np.ndarray) -> tuple[int, float | None, float | None, float | None]:
        import mediapipe as mp

        rgb = np.ascontiguousarray(bgr[:, :, ::-1])
        with self._lock:
            result = self._landmarker.detect(mp.Image(image_format=mp.ImageFormat.SRGB, data=rgb))
        if not result.face_landmarks:
            return 0, None, None, None
        height, width = bgr.shape[:2]
        points = np.array([[p.x * width, p.y * height] for p in result.face_landmarks[0]], dtype=np.float32)
        return (len(result.face_landmarks), landmark_yaw(points),
                (eye_aspect_ratio(points, EYE_A) + eye_aspect_ratio(points, EYE_B)) / 2, mouth_aspect_ratio(points))

    def close(self) -> None:
        self._landmarker.close()


def landmark_yaw(points: np.ndarray) -> float:
    mid = (points[CHEEK_A, 0] + points[CHEEK_B, 0]) / 2
    half = abs(points[CHEEK_B, 0] - points[CHEEK_A, 0]) / 2
    return float((points[NOSE_TIP, 0] - mid) / half) if half > 1e-3 else 0.0


def mouth_aspect_ratio(points: np.ndarray) -> float:
    width = np.linalg.norm(points[MOUTH_A] - points[MOUTH_B])
    return float(np.linalg.norm(points[LIP_TOP] - points[LIP_BOTTOM]) / width) if width > 1e-3 else 0.0


def eye_aspect_ratio(points: np.ndarray, idx: tuple[int, ...]) -> float:
    p1, p2, p3, p4, p5, p6 = (points[i] for i in idx)
    width = np.linalg.norm(p1 - p4)
    return float((np.linalg.norm(p2 - p6) + np.linalg.norm(p3 - p5)) / (2 * width)) if width > 1e-3 else 0.0


def yunet_yaw(face: Face) -> float:
    """Repli sans MediaPipe : nez par rapport au milieu des yeux, rapporté à l'écart des yeux (échelle
    ramenée à celle du lacet MediaPipe, ≈ 1,6)."""
    eye_a, eye_b, nose = face.landmarks[0], face.landmarks[1], face.landmarks[2]
    span = abs(eye_b[0] - eye_a[0])
    return float((nose[0] - (eye_a[0] + eye_b[0]) / 2) / span * 1.6) if span > 1e-3 else 0.0


# -- Évaluation (logique pure, testable sans modèle) -----------------------------------------------

def evaluate(measures: list[Measure], challenge: list[str], params: Params, replay_score: float | None = None) -> Result:
    reasons: list[str] = []
    if not params.min_frames <= len(measures) <= params.max_frames:
        return Result(False, ["liveness_too_few_frames" if len(measures) < params.min_frames else "liveness_too_many_frames"])
    if any(b.t_ms <= a.t_ms for a, b in zip(measures, measures[1:])) \
            or any(b.step < a.step for a, b in zip(measures, measures[1:])) \
            or measures[0].step != 0 or measures[-1].step != len(challenge) \
            or {m.step for m in measures} != set(range(len(challenge) + 1)):
        return Result(False, ["liveness_sequence_invalid"])

    windows = {step: [m for m in measures if m.step == step] for step in range(len(challenge) + 1)}
    if any(w[-1].t_ms - w[0].t_ms < params.min_step_ms for step, w in windows.items() if step > 0):
        reasons.append("liveness_too_fast")
    if any(m.faces > 1 for m in measures):
        reasons.append("liveness_multiple_faces")
    missing = sum(1 for m in measures if m.faces == 0)
    if missing > params.max_missing_ratio * len(measures):
        reasons.append("liveness_face_lost")

    neutral = neutral_frames(measures, params)
    if not neutral:
        return Result(False, [*reasons, "liveness_no_neutral_frame"])
    reference = choose_reference(measures, params)

    if any(m.faces == 1 and (m.similarity is None or m.similarity < params.same_face_min) for m in measures):
        reasons.append("liveness_face_changed")

    open_ears = [measures[i].ear for i in neutral if measures[i].ear is not None]
    baseline = statistics.median(open_ears) if open_ears else None
    closed_mouths = [measures[i].mar for i in neutral if measures[i].mar is not None]
    mouth_baseline = statistics.median(closed_mouths) if closed_mouths else None
    for step, action in enumerate(challenge, start=1):
        window = [m for m in windows[step] if m.faces == 1 and m.yaw is not None]
        if not window:
            reasons.append("liveness_challenge_failed")
            continue
        yaws = [m.yaw for m in window]
        if action == "turn_left":
            ok = max(yaws) >= params.yaw_threshold and min(yaws) > -params.yaw_threshold
        elif action == "turn_right":
            ok = min(yaws) <= -params.yaw_threshold and max(yaws) < params.yaw_threshold
        elif action == "blink":
            if baseline is None or any(m.ear is None for m in window):
                reasons.append("liveness_blink_unsupported")
                continue
            ok = _blinked([m.ear for m in window], baseline, params.blink_ratio) \
                and max(abs(y) for y in yaws) < params.yaw_threshold
        elif action == "open_mouth":
            if mouth_baseline is None or any(m.mar is None for m in window):
                reasons.append("liveness_mouth_unsupported")
                continue
            peak = max(m.mar for m in window)
            ok = peak >= max(params.mouth_open_min, mouth_baseline + params.mouth_open_delta) \
                and max(abs(y) for y in yaws) < params.yaw_threshold
        else:
            ok = False
        if not ok:
            reasons.append("liveness_challenge_failed")

    if replay_score is not None and replay_score >= params.replay_threshold:
        reasons.append("liveness_replay_suspected")
    unique = list(dict.fromkeys(reasons))
    return Result(not unique, unique, reference)


def neutral_frames(measures: list[Measure], params: Params) -> list[int]:
    """Images de la fenêtre initiale (regarder la caméra) : un visage, de face."""
    return [i for i, m in enumerate(measures) if m.step == 0 and m.faces == 1 and m.yaw is not None
            and abs(m.yaw) <= params.neutral_max_yaw]


def choose_reference(measures: list[Measure], params: Params) -> int | None:
    """Image de référence : la plus nette détection parmi les images de face de la fenêtre initiale.
    Sert à la comparaison avec le document et au contrôle de cohérence de l'identité."""
    neutral = neutral_frames(measures, params)
    return max(neutral, key=lambda i: measures[i].score) if neutral else None


def _blinked(ears: list[float], baseline: float, ratio: float) -> bool:
    """Yeux fermés puis rouverts dans la fenêtre du défi (ils étaient ouverts sur l'image de référence)."""
    closed, reopen = ratio * baseline, 0.85 * baseline
    state = "want_closed"
    for ear in ears:
        if state == "want_closed" and ear <= closed:
            state = "want_reopen"
        elif state == "want_reopen" and ear >= reopen:
            return True
    return False
