"""Contrôle du vivant : logique d'évaluation sur des séquences SIMULÉES (mesures), puis mesures réelles
MediaPipe sur des images synthétiques (rotation simulée, paupières fermées)."""
import numpy as np
import pytest

import synth
from veriage_biometrics.liveness import Measure, Params, evaluate, eye_aspect_ratio, landmark_yaw, EYE_A

P = Params()


def sequence(challenge, *, step_frames=8, interval=150, neutral=4, yaw_peak=0.45, ear_open=0.30, ear_closed=0.10,
             similarity=0.9, faces=1, overrides=None, mar_closed=0.05, mar_open=0.6):
    """Séquence simulée : fenêtre neutre puis une fenêtre par défi (mouvement en cloche)."""
    frames, t = [], 0
    for _ in range(neutral):
        frames.append(Measure(t, 0, faces, 0.9, 0.02, ear_open, similarity, mar_closed)); t += interval
    for step, action in enumerate(challenge, start=1):
        for i in range(step_frames):
            phase = float(np.sin(np.pi * i / (step_frames - 1)))
            yaw, ear, mar = 0.0, ear_open, mar_closed
            if action == "turn_left":
                yaw = yaw_peak * phase
            elif action == "turn_right":
                yaw = -yaw_peak * phase
            elif action == "blink" and step_frames // 3 <= i < step_frames // 3 + 2:
                ear = ear_closed
            elif action == "open_mouth" and step_frames // 3 <= i < step_frames // 3 + 3:
                mar = mar_open
            frames.append(Measure(t, step, faces, 0.9, yaw, ear, similarity, mar)); t += interval
    for index, change in (overrides or {}).items():
        m = frames[index]
        frames[index] = Measure(**{**m.__dict__, **change})
    return frames


@pytest.mark.parametrize("challenge", [["turn_left", "blink", "turn_right"], ["blink", "turn_right"], ["turn_right", "turn_left", "blink"],
                                       ["open_mouth", "turn_left", "open_mouth", "blink"]])
def test_challenges_performed_in_order_pass(challenge):
    result = evaluate(sequence(challenge), challenge, P)
    assert result.passed, result.reasons
    assert result.reference is not None and result.reference < 4


def test_wrong_order_fails():
    performed = sequence(["turn_right", "blink", "turn_left"])
    assert evaluate(performed, ["turn_left", "blink", "turn_right"], P).reasons == ["liveness_challenge_failed"]


def test_opposite_movement_in_a_window_fails():
    """Une vidéo en boucle qui enchaîne gauche et droite dans la même fenêtre échoue."""
    frames = sequence(["turn_left"], overrides={6: {"yaw": -0.5}})
    assert "liveness_challenge_failed" in evaluate(frames, ["turn_left"], P).reasons


def test_photo_rotated_flat_does_not_move_the_nose():
    """Photo plane pivotée : le lacet mesuré reste faible, le défi de rotation échoue."""
    frames = sequence(["turn_left"], yaw_peak=0.12)
    assert not evaluate(frames, ["turn_left"], P).passed


def test_blink_requires_closed_then_reopened_eyes():
    assert evaluate(sequence(["blink"]), ["blink"], P).passed
    no_blink = sequence(["blink"], ear_closed=0.29)
    assert evaluate(no_blink, ["blink"], P).reasons == ["liveness_challenge_failed"]
    never_reopened = sequence(["blink"], overrides={i: {"ear": 0.1} for i in range(8, 12)})
    assert not evaluate(never_reopened, ["blink"], P).passed


def test_blink_without_eye_measure_is_unsupported():
    frames = [Measure(m.t_ms, m.step, m.faces, m.score, m.yaw, None, m.similarity) for m in sequence(["blink"])]
    assert "liveness_blink_unsupported" in evaluate(frames, ["blink"], P).reasons


@pytest.mark.parametrize("kwargs,reason", [
    ({"faces": 2}, "liveness_multiple_faces"),
    ({"similarity": 0.1}, "liveness_face_changed"),
    ({"interval": 40}, "liveness_too_fast"),
])
def test_temporal_consistency(kwargs, reason):
    assert reason in evaluate(sequence(["turn_left", "blink"], **kwargs), ["turn_left", "blink"], P).reasons


def test_face_swap_mid_sequence_is_detected():
    frames = sequence(["turn_left", "blink"], overrides={7: {"similarity": 0.05}})
    assert "liveness_face_changed" in evaluate(frames, ["turn_left", "blink"], P).reasons


def test_lost_face_and_missing_neutral_frame():
    lost = sequence(["turn_left"], overrides={i: {"faces": 0, "yaw": None, "ear": None, "similarity": None} for i in range(4, 12)})
    assert "liveness_face_lost" in evaluate(lost, ["turn_left"], P).reasons
    turned = sequence(["turn_left"], overrides={i: {"yaw": 0.4} for i in range(4)})
    assert "liveness_no_neutral_frame" in evaluate(turned, ["turn_left"], P).reasons


@pytest.mark.parametrize("mutate", [
    lambda f: f[:3],                                        # trop peu d'images
    lambda f: [f[1], f[0], *f[2:]],                         # horodatages non croissants
    lambda f: [Measure(m.t_ms, 1, *list(m.__dict__.values())[2:]) if i == 0 else m for i, m in enumerate(f)],
    lambda f: [m for m in f if m.step != 1],                # fenêtre d'un défi absente
])
def test_malformed_sequences(mutate):
    assert not evaluate(mutate(sequence(["turn_left", "blink"])), ["turn_left", "blink"], P).passed


def test_replay_score_above_threshold_fails():
    frames = sequence(["blink"])
    assert evaluate(frames, ["blink"], P, replay_score=5.0).passed
    assert "liveness_replay_suspected" in evaluate(frames, ["blink"], P, replay_score=80.0).reasons


def test_params_from_request_are_bounded():
    assert Params.from_request({"yaw_threshold": 0.3, "min_frames": 10}).min_frames == 10
    for bad in ({"yaw_threshold": 5}, {"min_frames": "10"}, {"unknown": 1}, {"blink_ratio": True}, []):
        with pytest.raises(ValueError):
            Params.from_request(bad)


# -- Mesures réelles (MediaPipe) sur images synthétiques ------------------------------------------

def _points(landmarker, image):
    import mediapipe as mp
    res = landmarker._landmarker.detect(mp.Image(image_format=mp.ImageFormat.SRGB, data=np.ascontiguousarray(image[:, :, ::-1])))
    h, w = image.shape[:2]
    return np.array([[p.x * w, p.y * h] for p in res.face_landmarks[0]], dtype=np.float32)


def test_mediapipe_measures_yaw_sign_and_eye_closure(landmarker, face_engine, assets):
    base = synth.selfie_frame(synth.load_asset("astronaut.png"))
    count, yaw, ear, mar = landmarker.measure(base)
    assert count == 1 and abs(yaw) < 0.15 and ear > 0.2
    box = tuple(int(v) for v in face_engine.detect(base)[0].box)
    _, yaw_left, _, _ = landmarker.measure(synth.yaw_warp(base, box, 1.3))
    _, yaw_right, _, _ = landmarker.measure(synth.yaw_warp(base, box, -1.3))
    assert yaw_left > 0.28 > -0.28 > yaw_right  # nez vers la droite de l'image = tête tournée vers SA gauche
    _, _, ear_closed, _ = landmarker.measure(synth.close_eyes(base, _points(landmarker, base)))
    _, _, _, mar_open = landmarker.measure(synth.open_mouth(base, _points(landmarker, base)))
    assert mar_open >= max(0.35, mar + 0.15)
    assert ear_closed < 0.65 * ear
    assert landmark_yaw(_points(landmarker, base)) == pytest.approx(yaw, abs=1e-3)
    assert eye_aspect_ratio(_points(landmarker, base), EYE_A) > 0.2


def test_flat_photo_rotated_in_3d_keeps_a_small_yaw(landmarker, assets):
    """Photo imprimée que l'on fait pivoter : perspective simulée par homographie (compression d'un côté)."""
    import cv2
    base = synth.selfie_frame(synth.load_asset("astronaut.png"))
    h, w = base.shape[:2]
    src = np.float32([[0, 0], [w, 0], [w, h], [0, h]])
    dst = np.float32([[0, 0], [w * 0.8, h * 0.08], [w * 0.8, h * 0.92], [0, h]])
    rotated_photo = cv2.warpPerspective(base, cv2.getPerspectiveTransform(src, dst), (w, h), borderMode=cv2.BORDER_REPLICATE)
    _, yaw, _, _ = landmarker.measure(rotated_photo)
    assert yaw is not None and abs(yaw) < 0.28


def test_open_mouth_requires_a_clear_opening_above_the_reference():
    assert evaluate(sequence(["open_mouth"]), ["open_mouth"], P).passed
    assert evaluate(sequence(["open_mouth"], mar_open=0.25), ["open_mouth"], P).reasons == ["liveness_challenge_failed"]
    # Bouche déjà ouverte sur l'image de référence : l'ouverture doit dépasser la référence de 0,15.
    assert not evaluate(sequence(["open_mouth"], mar_closed=0.5, mar_open=0.6), ["open_mouth"], P).passed
    no_mar = [Measure(m.t_ms, m.step, m.faces, m.score, m.yaw, m.ear, m.similarity, None) for m in sequence(["open_mouth"])]
    assert "liveness_mouth_unsupported" in evaluate(no_mar, ["open_mouth"], P).reasons
