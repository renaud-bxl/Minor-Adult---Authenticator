"""Détection BASIQUE du rejeu sur écran (moiré). Volontairement modeste.

Principe : filmer un écran superpose au visage une trame périodique (pixels de l'écran, battement avec
le capteur) qui se traduit par des pics isolés dans le spectre de Fourier, loin des basses fréquences.
Le score est le rapport entre le plus fort pic de la couronne des hautes fréquences et le niveau médian
de cette couronne. Les fréquences multiples de 1/8 (blocs JPEG) sont ignorées.

Limites (documentées dans docs/licences.md et docs/rgpd.md) : un écran de très haute définition filmé
de loin, un flou de mise au point ou une recompression forte effacent le moiré ; un visage imprimé sur
papier mat n'en produit pas. Ce signal ne remplace ni les défis aléatoires (qui résistent aux photos),
ni une détection d'attaque certifiée (ISO/IEC 30107-3), hors du périmètre de cette phase.
"""
from __future__ import annotations

import cv2
import numpy as np

from .faces import Face


def moire_score(image: np.ndarray, face: Face, size: int = 128) -> float | None:
    x, y, w, h = face.box
    # Zone centrale du visage (joues, nez, front), alignée sur la grille JPEG de 8 pixels.
    side = int(min(w, h) * 0.7) // 8 * 8
    if side < 64:
        return None
    x0 = int(x + (w - side) / 2) // 8 * 8
    y0 = int(y + (h - side) / 2) // 8 * 8
    roi = image[y0:y0 + side, x0:x0 + side]
    if roi.shape[0] != side or roi.shape[1] != side:
        return None
    gray = cv2.cvtColor(roi, cv2.COLOR_BGR2GRAY).astype(np.float32)
    gray -= cv2.GaussianBlur(gray, (0, 0), 3)  # on ne garde que la texture fine
    window = np.outer(np.hanning(side), np.hanning(side)).astype(np.float32)
    spectrum = np.abs(np.fft.fftshift(np.fft.fft2(gray * window)))

    freq = np.fft.fftshift(np.fft.fftfreq(side))
    fx, fy = np.meshgrid(freq, freq)
    radius = np.hypot(fx, fy)
    ring = (radius >= 0.12) & (radius <= 0.45)
    # Harmoniques des blocs JPEG (multiples de 1/8 cycle par pixel) écartées.
    tolerance = 1.5 / side
    for axis in (fx, fy):
        ring &= np.abs(axis * 8 - np.round(axis * 8)) * (1 / 8) > tolerance
    values = spectrum[ring]
    if values.size < 32:
        return None
    return float(values.max() / (np.median(values) + 1e-6))
