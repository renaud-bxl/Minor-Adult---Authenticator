"""Détection (OpenCV Zoo YuNet, MIT) et comparaison de visages (OpenCV Zoo SFace, Apache-2.0), via
cv2.FaceDetectorYN et cv2.FaceRecognizerSF. Les empreintes (vecteurs de 128 valeurs) ne quittent jamais
la mémoire du processus et ne sont jamais conservées d'une requête à l'autre.
"""
from __future__ import annotations

import threading
from dataclasses import dataclass
from pathlib import Path

import cv2
import numpy as np

from .config import PACKAGE_ROOT
from .imaging import resize_max

YUNET = "face_detection_yunet_2023mar.onnx"
SFACE = "face_recognition_sface_2021dec.onnx"
# Seuil de même identité recommandé par OpenCV pour SFace (similarité cosinus) : 0,363.
SFACE_COSINE_SAME = 0.363


def default_model_dir() -> Path:
    return PACKAGE_ROOT / "models"


@dataclass(frozen=True)
class Face:
    box: tuple[float, float, float, float]  # x, y, largeur, hauteur (pixels de l'image d'origine)
    landmarks: np.ndarray  # 5 points : œil droit, œil gauche, nez, coin droit, coin gauche de la bouche
    score: float
    raw: np.ndarray  # ligne YuNet (15 valeurs), nécessaire à l'alignement SFace

    @property
    def area(self) -> float:
        return self.box[2] * self.box[3]


class FaceEngine:
    """Détecteur et comparateur. Les objets cv2.dnn ne sont pas sûrs entre fils : verrou."""

    def __init__(self, model_dir: Path, score_threshold: float = 0.75, detect_max_side: int = 1280) -> None:
        detector_path, recognizer_path = model_dir / YUNET, model_dir / SFACE
        if not detector_path.is_file() or not recognizer_path.is_file():
            raise FileNotFoundError("Modèles YuNet/SFace absents : lancez scripts/fetch_models.sh")
        self._detector = cv2.FaceDetectorYN.create(str(detector_path), "", (320, 320), score_threshold, 0.3, 50)
        self._recognizer = cv2.FaceRecognizerSF.create(str(recognizer_path), "")
        self._lock = threading.Lock()
        self._detect_max_side = detect_max_side

    def detect(self, image: np.ndarray) -> list[Face]:
        small, scale = resize_max(image, self._detect_max_side)
        with self._lock:
            self._detector.setInputSize((small.shape[1], small.shape[0]))
            _, rows = self._detector.detect(small)
        if rows is None:
            return []
        faces = []
        for row in rows:
            full = row.copy()
            full[:14] /= scale
            faces.append(Face(box=tuple(float(v) for v in full[:4]), landmarks=full[4:14].reshape(5, 2),
                              score=float(full[14]), raw=full))
        faces.sort(key=lambda f: f.area, reverse=True)
        return faces

    def embed(self, image: np.ndarray, face: Face) -> np.ndarray:
        with self._lock:
            aligned = self._recognizer.alignCrop(image, face.raw)
            feature = self._recognizer.feature(aligned)
        vector = feature.flatten().astype(np.float32)
        return vector / (np.linalg.norm(vector) or 1.0)

    @staticmethod
    def similarity(a: np.ndarray, b: np.ndarray) -> float:
        """Similarité cosinus (vecteurs normalisés), dans [-1, 1]."""
        return float(np.clip(np.dot(a, b), -1.0, 1.0))

    def document_face(self, image: np.ndarray) -> Face | None:
        """Portrait d'un document : le plus grand visage (la carte belge porte aussi une petite image
        fantôme, plus pâle, qu'il faut ignorer)."""
        faces = self.detect(image)
        return faces[0] if faces else None
