"""Décodage des images reçues, en mémoire uniquement.

Seuls JPEG et PNG sont acceptés, identifiés par leur signature (jamais par un nom ou un type déclaré).
Les dimensions sont lues dans l'en-tête AVANT tout décodage (bombe de décompression), puis bornées.
"""
from __future__ import annotations

import base64
import binascii
import struct

import cv2
import numpy as np

JPEG_MAGIC = b"\xff\xd8\xff"
PNG_MAGIC = b"\x89PNG\r\n\x1a\n"


class ImageError(ValueError):
    def __init__(self, reason: str) -> None:
        super().__init__(reason)
        self.reason = reason


def b64decode(value: object, max_bytes: int) -> bytes:
    """Base64 strict (alphabet standard, remplissage exact), taille bornée avant décodage."""
    if not isinstance(value, str) or value == "":
        raise ImageError("image_missing")
    if len(value) > (max_bytes * 4) // 3 + 4:
        raise ImageError("image_too_large")
    try:
        return base64.b64decode(value, validate=True)
    except (binascii.Error, ValueError):
        raise ImageError("image_invalid") from None


def _png_size(data: bytes) -> tuple[int, int]:
    if len(data) < 24 or data[12:16] != b"IHDR":
        raise ImageError("image_invalid")
    width, height = struct.unpack(">II", data[16:24])
    return width, height


def _jpeg_size(data: bytes) -> tuple[int, int]:
    """Parcourt les segments jusqu'au premier SOFn (dimensions de l'image)."""
    i = 2
    length = len(data)
    while i + 4 <= length:
        if data[i] != 0xFF:
            raise ImageError("image_invalid")
        marker = data[i + 1]
        if marker == 0xFF:  # remplissage
            i += 1
            continue
        if marker in (0xD8, 0x01) or 0xD0 <= marker <= 0xD7:
            i += 2
            continue
        segment = struct.unpack(">H", data[i + 2:i + 4])[0]
        if segment < 2:
            raise ImageError("image_invalid")
        if marker in (0xC0, 0xC1, 0xC2, 0xC3, 0xC5, 0xC6, 0xC7, 0xC9, 0xCA, 0xCB, 0xCD, 0xCE, 0xCF):
            if i + 9 > length:
                raise ImageError("image_invalid")
            height, width = struct.unpack(">HH", data[i + 5:i + 9])
            return width, height
        i += 2 + segment
    raise ImageError("image_invalid")


def image_size(data: bytes) -> tuple[str, int, int]:
    if data.startswith(JPEG_MAGIC):
        return ("jpeg", *_jpeg_size(data))
    if data.startswith(PNG_MAGIC):
        return ("png", *_png_size(data))
    raise ImageError("image_invalid_type")


def decode(data: bytes, max_side: int, min_side: int = 64) -> np.ndarray:
    """Image BGR décodée en mémoire. Dimensions contrôlées avant décodage."""
    _, width, height = image_size(data)
    if width > max_side or height > max_side:
        raise ImageError("image_too_large")
    if width < min_side or height < min_side:
        raise ImageError("image_too_small")
    image = cv2.imdecode(np.frombuffer(data, dtype=np.uint8), cv2.IMREAD_COLOR)
    if image is None or image.ndim != 3:
        raise ImageError("image_invalid")
    return image


def resize_max(image: np.ndarray, max_side: int) -> tuple[np.ndarray, float]:
    """Réduit l'image si son plus grand côté dépasse max_side ; renvoie aussi le facteur appliqué."""
    height, width = image.shape[:2]
    scale = min(1.0, max_side / max(height, width))
    if scale >= 1.0:
        return image, 1.0
    return cv2.resize(image, (round(width * scale), round(height * scale)), interpolation=cv2.INTER_AREA), scale
