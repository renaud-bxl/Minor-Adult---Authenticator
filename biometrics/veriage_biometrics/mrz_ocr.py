"""Lecture de la MRZ sur une photo de document : localisation de la bande, redressement, binarisation,
OCR Tesseract, puis contrôle (mrz.parse).

Tesseract est appelé en sous-processus, image sur l'entrée standard et texte sur la sortie standard :
aucun fichier n'est écrit (contrairement à pytesseract, qui passe par des fichiers temporaires).
L'image est transmise au format BMP, que Leptonica lit directement en mémoire.

Modèle : « mrz » (DoubangoTelecom/tesseractMRZ, BSD-3-Clause, entraîné pour la police OCR-B) s'il est
installé (scripts/fetch_models.sh), sinon « eng » avec une liste blanche de caractères.
"""
from __future__ import annotations

import datetime as dt
import subprocess
import time
from dataclasses import dataclass
from pathlib import Path

import cv2
import numpy as np

from . import mrz

WHITELIST = "ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789<"
OCR_WIDTH = 1400


@dataclass(frozen=True)
class MrzReading:
    data: mrz.MrzData | None
    reason: str | None  # code stable si data est None ou invalide


class TesseractError(RuntimeError):
    pass


class MrzReader:
    def __init__(self, tessdata_dir: Path | None, binary: str = "tesseract", timeout: float = 15.0,
                 max_ocr_calls: int = 24) -> None:
        self.binary = binary
        self.timeout = timeout
        self.max_ocr_calls = max_ocr_calls
        if tessdata_dir is not None and (tessdata_dir / "mrz.traineddata").is_file():
            self.language, self.tessdata_dir = "mrz", tessdata_dir
        else:
            self.language, self.tessdata_dir = "eng", None

    # -- OCR -----------------------------------------------------------------------------------

    def command(self, psm: int = 6) -> list[str]:
        """Ligne de commande : image lue sur l'entrée standard, texte écrit sur la sortie standard."""
        command = [self.binary, "stdin", "stdout"]
        if self.tessdata_dir is not None:
            command += ["--tessdata-dir", str(self.tessdata_dir)]
        return command + ["--oem", "1", "--psm", str(psm), "-l", self.language,
                          "-c", "tessedit_char_whitelist=" + WHITELIST,
                          "-c", "load_system_dawg=0", "-c", "load_freq_dawg=0"]

    def ocr(self, image: np.ndarray, psm: int = 6, timeout: float | None = None) -> str:
        ok, encoded = cv2.imencode(".bmp", image)
        if not ok:
            raise TesseractError("encodage impossible")
        try:
            result = subprocess.run(self.command(psm), input=encoded.tobytes(), capture_output=True,
                                    timeout=self.timeout if timeout is None else min(self.timeout, timeout), check=False)
        except (OSError, subprocess.TimeoutExpired) as exc:
            raise TesseractError(type(exc).__name__) from None
        if result.returncode != 0:
            raise TesseractError("code retour " + str(result.returncode))
        return result.stdout.decode("ascii", "ignore")

    def available(self) -> bool:
        try:
            result = subprocess.run([self.binary, "--version"], capture_output=True, timeout=5, check=False)
        except (OSError, subprocess.TimeoutExpired):
            return False
        return result.returncode == 0

    # -- Lecture ------------------------------------------------------------------------------

    def read(self, image: np.ndarray, today: dt.date, deadline: float | None = None) -> MrzReading:
        """Meilleure lecture : la première MRZ entièrement valide ; à défaut, la raison la plus précise.
        « deadline » (time.monotonic()) borne la durée totale : sans elle, une image hostile pourrait
        occuper le service bien au-delà du délai de PHP (24 appels × 15 s)."""
        reason = "mrz_not_found"
        calls = 0
        for oriented in (image, cv2.rotate(image, cv2.ROTATE_180)):
            for roi in [*locate_bands(oriented), bottom_strip(oriented)]:
                for variant in preprocess(roi):
                    remaining = None if deadline is None else deadline - time.monotonic()
                    if calls >= self.max_ocr_calls or (remaining is not None and remaining <= 0.5):
                        return MrzReading(None, reason)
                    calls += 1
                    try:
                        text = self.ocr(variant, timeout=remaining)
                    except TesseractError:
                        if remaining is not None and deadline - time.monotonic() <= 0.5:
                            return MrzReading(None, reason)  # délai global épuisé pendant l'appel
                        raise
                    for lines in mrz.candidate_lines(text):
                        try:
                            data = mrz.parse(lines, today)
                        except mrz.MrzError as exc:
                            if exc.reason == "document_unsupported":
                                return MrzReading(None, exc.reason)  # inutile d'insister
                            reason = _more_specific(reason, exc.reason)
                            continue
                        if data.valid:
                            return MrzReading(data, None)
                        reason = _more_specific(reason, "mrz_checksum_failed")
        return MrzReading(None, reason)


_REASON_RANK = {"mrz_not_found": 0, "mrz_unknown_format": 1, "mrz_invalid_character": 1, "mrz_invalid_date": 2,
                "mrz_birth_date_in_future": 2, "mrz_unsupported_document": 3, "mrz_checksum_failed": 3,
                "document_unsupported": 4}


def _more_specific(current: str, new: str) -> str:
    return new if _REASON_RANK.get(new, 1) > _REASON_RANK.get(current, 0) else current


def locate_bands(image: np.ndarray, limit: int = 3) -> list[np.ndarray]:
    """Bandes de texte dense, larges et plates (la MRZ) : chapeau noir, gradient horizontal,
    fermetures morphologiques, puis rectangles orientés redressés. Les plus basses d'abord (la MRZ
    est en bas du verso de la carte et de la page du passeport)."""
    height, width = image.shape[:2]
    scale = 1000.0 / width
    small = cv2.resize(image, (1000, max(1, round(height * scale))), interpolation=cv2.INTER_AREA)
    gray = cv2.GaussianBlur(cv2.cvtColor(small, cv2.COLOR_BGR2GRAY), (3, 3), 0)
    rect_kernel = cv2.getStructuringElement(cv2.MORPH_RECT, (17, 5))
    square_kernel = cv2.getStructuringElement(cv2.MORPH_RECT, (25, 25))
    blackhat = cv2.morphologyEx(gray, cv2.MORPH_BLACKHAT, rect_kernel)
    grad = np.absolute(cv2.Sobel(blackhat, cv2.CV_32F, 1, 0, ksize=-1))
    grad = cv2.normalize(grad, None, 0, 255, cv2.NORM_MINMAX).astype(np.uint8)
    grad = cv2.morphologyEx(grad, cv2.MORPH_CLOSE, rect_kernel)
    _, thresh = cv2.threshold(grad, 0, 255, cv2.THRESH_BINARY | cv2.THRESH_OTSU)
    thresh = cv2.morphologyEx(thresh, cv2.MORPH_CLOSE, square_kernel)
    thresh = cv2.erode(thresh, None, iterations=3)
    contours, _ = cv2.findContours(thresh, cv2.RETR_EXTERNAL, cv2.CHAIN_APPROX_SIMPLE)

    candidates = []
    for contour in contours:
        (cx, cy), (w, h), angle = cv2.minAreaRect(contour)
        if w < h:
            w, h, angle = h, w, angle + 90
        if angle > 45:
            angle -= 180
        if h < 8 or w / h < 3.5 or w < 0.3 * small.shape[1]:
            continue
        candidates.append((cy, (cx, cy), (w, h), angle))
    candidates.sort(key=lambda c: -c[0])

    bands = []
    for _, (cx, cy), (w, h), angle in candidates[:limit]:
        # Retour à l'échelle d'origine, marges (les lignes extrêmes ne doivent pas être rognées).
        cx, cy, w, h = cx / scale, cy / scale, w / scale * 1.06, h / scale * 1.35
        matrix = cv2.getRotationMatrix2D((cx, cy), angle, 1.0)
        rotated = cv2.warpAffine(image, matrix, (width, height), flags=cv2.INTER_CUBIC, borderMode=cv2.BORDER_REPLICATE)
        x0, y0 = max(0, int(cx - w / 2)), max(0, int(cy - h / 2))
        x1, y1 = min(width, int(cx + w / 2)), min(height, int(cy + h / 2))
        if x1 - x0 > 20 and y1 - y0 > 10:
            bands.append(rotated[y0:y1, x0:x1])
    return bands


def bottom_strip(image: np.ndarray) -> np.ndarray:
    """Repli : tiers inférieur de l'image (document bien cadré mais bande non isolée)."""
    height = image.shape[0]
    return image[int(height * 0.62):, :]


def preprocess(roi: np.ndarray) -> list[np.ndarray]:
    """Variantes présentées à l'OCR : niveaux de gris, Otsu, seuil adaptatif (éclairage inégal)."""
    gray = cv2.cvtColor(roi, cv2.COLOR_BGR2GRAY) if roi.ndim == 3 else roi
    scale = OCR_WIDTH / gray.shape[1]
    gray = cv2.resize(gray, (OCR_WIDTH, max(1, round(gray.shape[0] * scale))), interpolation=cv2.INTER_CUBIC)
    gray = cv2.createCLAHE(clipLimit=2.0, tileGridSize=(8, 8)).apply(gray)
    blurred = cv2.GaussianBlur(gray, (3, 3), 0)
    _, otsu = cv2.threshold(blurred, 0, 255, cv2.THRESH_BINARY | cv2.THRESH_OTSU)
    adaptive = cv2.adaptiveThreshold(blurred, 255, cv2.ADAPTIVE_THRESH_GAUSSIAN_C, cv2.THRESH_BINARY, 41, 15)
    pad = lambda img: cv2.copyMakeBorder(img, 24, 24, 24, 24, cv2.BORDER_CONSTANT, value=255)  # noqa: E731
    return [pad(otsu), pad(adaptive), pad(gray)]
