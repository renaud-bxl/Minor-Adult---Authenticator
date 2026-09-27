"""Liaison des faces d'une même pièce (exigence E1 de l'audit de la phase 3), entièrement dans le service :
rien de ce qui est lu ici ne sort (seuls des codes de motif).

1. Détection du document sur la face qui porte le portrait : quadrilatère au format attendu (carte ID-1 :
   85,6 × 54 mm, rapport 1,586 ; page de données d'un passeport TD3 : 125 × 88 mm, rapport 1,42 ; ± 15 %),
   puis redressement (perspective). Repli : l'image entière si elle a elle-même ce format (scan recadré).
2. Portrait À L'INTÉRIEUR du document, à la position attendue : moitié gauche, taille plausible, et, pour un
   passeport, au-dessus de la MRZ. Un selfie envoyé à la place du recto est refusé (attaque A).
3. Cohérence entre les champs imprimés de cette face (zone VIZ) et la MRZ : voir `viz_consistency`.
   La carte d'un mineur combinée au verso de celle d'un parent est refusée (attaque B).

Règle de concordance VIZ/MRZ, et pourquoi :
- la date de naissance DOIT être retrouvée : c'est la donnée d'où l'on tire l'âge, donc celle qu'un fraudeur
  remplace en combinant deux pièces ;
- ET au moins un autre champ (numéro du document ou date d'expiration) : la date de naissance seule ne lie pas
  les deux faces à UNE pièce (deux pièces d'une même personne, ou de jumeaux, la partagent) ;
- deux champs sur trois, et non trois : l'OCR d'un champ imprimé en petits caractères, sur fond guilloché,
  sous un hologramme, échoue souvent ; exiger les trois ferait échouer trop de vraies pièces.
L'OCR utilise le modèle générique « eng » de Tesseract (tessdata_fast, Apache-2.0), sur l'entrée standard.
Les formats de date recherchés sont ceux des documents européens : JJ.MM.AAAA, JJ/MM/AAAA, JJ MM AAAA,
JJ MMM AAAA (mois abrégés FR, NL, DE, EN), AAAA-MM-JJ ; le numéro est comparé sans séparateurs (format belge
« 592-1234567-89 ») et après correction des confusions O/0, I/1, S/5, B/8, Z/2.
"""
from __future__ import annotations

import datetime as dt
import re
import subprocess
import time
from dataclasses import dataclass
from pathlib import Path

import cv2
import numpy as np

from .faces import Face, FaceEngine

RATIOS = {"id_card": 85.6 / 53.98, "passport": 125.0 / 88.0}
RATIO_TOLERANCE = 0.15
CANONICAL_WIDTH = 1000

MONTHS = {
    1: ["JAN", "JANV", "JANVIER", "JANUARY", "JANUARI", "JANUAR", "JAN."],
    2: ["FEB", "FEV", "FÉV", "FEVR", "FÉVR", "FEBR", "FEBRUARY", "FEBRUARI", "FEBRUAR", "FEVRIER"],
    3: ["MAR", "MARS", "MRT", "MÄR", "MAER", "MAERZ", "MARCH", "MAART", "MÄRZ"],
    4: ["APR", "AVR", "AVRIL", "APRIL"],
    5: ["MAY", "MAI", "MEI"],
    6: ["JUN", "JUIN", "JUNE", "JUNI"],
    7: ["JUL", "JUIL", "JUILLET", "JULY", "JULI"],
    8: ["AUG", "AOU", "AOÛ", "AOUT", "AOÛT", "AUGUST", "AUGUSTUS"],
    9: ["SEP", "SEPT", "SEPTEMBER", "SEPTEMBRE"],
    10: ["OCT", "OKT", "OCTOBER", "OKTOBER", "OCTOBRE"],
    11: ["NOV", "NOVEMBER", "NOVEMBRE"],
    12: ["DEC", "DÉC", "DEZ", "DECEMBER", "DEZEMBER", "DÉCEMBRE", "DECEMBRE"],
}
TO_DIGITS = str.maketrans({"O": "0", "Q": "0", "D": "0", "I": "1", "L": "1", "|": "1", "S": "5", "B": "8", "Z": "2", "G": "6"})


@dataclass
class Region:
    image: np.ndarray  # document redressé (orientation portrait à gauche)
    detected: bool     # quadrilatère trouvé (sinon : image entière au bon format)


def _order(points: np.ndarray) -> np.ndarray:
    s, d = points.sum(axis=1), np.diff(points, axis=1).ravel()
    return np.float32([points[np.argmin(s)], points[np.argmin(d)], points[np.argmax(s)], points[np.argmax(d)]])


def _ratio_ok(width: float, height: float, kind: str) -> bool:
    if min(width, height) < 1:
        return False
    ratio = max(width, height) / min(width, height)
    return abs(ratio - RATIOS[kind]) <= RATIO_TOLERANCE * RATIOS[kind]


def _warp(image: np.ndarray, quad: np.ndarray, kind: str) -> np.ndarray:
    tl, tr, br, bl = quad
    width = max(np.linalg.norm(tr - tl), np.linalg.norm(br - bl))
    height = max(np.linalg.norm(bl - tl), np.linalg.norm(br - tr))
    out_w = CANONICAL_WIDTH
    out_h = round(CANONICAL_WIDTH / RATIOS[kind])
    if height > width:  # document photographié en « portrait » : on le couche
        quad = np.float32([bl, tl, tr, br])
    matrix = cv2.getPerspectiveTransform(quad, np.float32([[0, 0], [out_w, 0], [out_w, out_h], [0, out_h]]))
    return cv2.warpPerspective(image, matrix, (out_w, out_h), flags=cv2.INTER_CUBIC)


def detect(image: np.ndarray, kind: str) -> Region | None:
    """Plus grand quadrilatère convexe au format du document (≥ 12 % de l'image), redressé."""
    height, width = image.shape[:2]
    scale = min(1.0, 1000.0 / max(height, width))
    small = cv2.resize(image, (round(width * scale), round(height * scale)), interpolation=cv2.INTER_AREA) if scale < 1 else image
    gray = cv2.GaussianBlur(cv2.cvtColor(small, cv2.COLOR_BGR2GRAY), (5, 5), 0)
    area_min = 0.12 * small.shape[0] * small.shape[1]
    best = None
    for low, high in ((30, 90), (60, 160), (15, 50)):
        edges = cv2.dilate(cv2.Canny(gray, low, high), np.ones((3, 3), np.uint8), iterations=2)
        contours, _ = cv2.findContours(edges, cv2.RETR_EXTERNAL, cv2.CHAIN_APPROX_SIMPLE)
        for contour in sorted(contours, key=cv2.contourArea, reverse=True)[:8]:
            area = cv2.contourArea(contour)
            if area < area_min:
                break
            approx = cv2.approxPolyDP(contour, 0.02 * cv2.arcLength(contour, True), True)
            if len(approx) != 4 or not cv2.isContourConvex(approx):
                continue
            quad = _order(approx.reshape(4, 2).astype(np.float32))
            tl, tr, br, bl = quad
            w = (np.linalg.norm(tr - tl) + np.linalg.norm(br - bl)) / 2
            h = (np.linalg.norm(bl - tl) + np.linalg.norm(br - tr)) / 2
            # Le cadre de l'image elle-même n'est pas un document détecté.
            if area > 0.97 * small.shape[0] * small.shape[1]:
                continue
            if _ratio_ok(w, h, kind) and (best is None or area > best[0]):
                best = (area, quad / scale)
        if best is not None:
            break
    if best is not None:
        return Region(_warp(image, best[1], kind), True)
    if _ratio_ok(width, height, kind):
        full = np.float32([[0, 0], [width, 0], [width, height], [0, height]])
        return Region(_warp(image, full, kind), False)
    return None


def portrait(region: Region, faces: FaceEngine, kind: str) -> tuple[np.ndarray, Face] | None:
    """Portrait à la position attendue du document redressé (essaie aussi le document retourné à 180°).
    Renvoie l'image redressée orientée et le visage, ou None."""
    for image in (region.image, cv2.rotate(region.image, cv2.ROTATE_180)):
        found = faces.detect(image)
        if not found:
            continue
        face = found[0]
        x, y, w, h = face.box
        height, width = image.shape[:2]
        cx, cy, rh = (x + w / 2) / width, (y + h / 2) / height, h / height
        if kind == "id_card":
            ok = 0.03 <= cx <= 0.50 and 0.15 <= cy <= 0.95 and 0.15 <= rh <= 0.70
        else:  # page de passeport : portrait à gauche, au-dessus de la MRZ (tiers inférieur)
            ok = 0.02 <= cx <= 0.45 and 0.10 <= cy <= 0.70 and 0.12 <= rh <= 0.60
        if ok:
            return image, face
    return None


# -- Cohérence VIZ / MRZ -------------------------------------------------------------------------------

def _date_forms(date: dt.date) -> tuple[list[str], list[str]]:
    """Formes compactes (sans séparateurs) d'une date : numériques (comparées après correction des confusions
    d'OCR) et avec le mois en lettres."""
    d, m, y = f"{date.day:02d}", f"{date.month:02d}", f"{date.year:04d}"
    numeric = [d + m + y, y + m + d]
    lettered = [d + name.replace(".", "") + y for name in MONTHS[date.month]]
    return numeric, lettered


def _compact(text: str) -> str:
    return re.sub(r"[^A-Z0-9ÄÉÛÔ]", "", text.upper())


def field_matches(text: str, birth: dt.date, expiry: dt.date, number: str) -> dict[str, bool]:
    """Champs de la MRZ retrouvés dans le texte OCR de la face imprimée."""
    raw = _compact(text)
    digits = raw.translate(TO_DIGITS)

    def date_found(date: dt.date) -> bool:
        numeric, lettered = _date_forms(date)
        return any(form in digits for form in numeric) or any(form in raw for form in lettered)

    clean_number = number.replace("<", "").upper()
    number_found = len(clean_number) >= 6 and clean_number.translate(TO_DIGITS) in digits
    return {"birth_date": date_found(birth), "document_number": number_found, "expiry_date": date_found(expiry)}


def consistent(matches: dict[str, bool]) -> bool:
    """Règle documentée en tête du module : date de naissance ET au moins un autre champ."""
    return matches["birth_date"] and (matches["document_number"] or matches["expiry_date"])


class VizReader:
    """OCR générique de la face imprimée (Tesseract « eng », image sur l'entrée standard)."""

    def __init__(self, tessdata_dir: Path | None, binary: str = "tesseract") -> None:
        self.binary = binary
        self.tessdata_dir = tessdata_dir if tessdata_dir is not None and (tessdata_dir / "eng.traineddata").is_file() else None

    def command(self, psm: int) -> list[str]:
        command = [self.binary, "stdin", "stdout"]
        if self.tessdata_dir is not None:
            command += ["--tessdata-dir", str(self.tessdata_dir)]
        return command + ["--oem", "1", "--psm", str(psm), "-l", "eng"]

    def texts(self, zone: np.ndarray, deadline: float):
        """Textes successifs (variantes de prétraitement), tant que le budget de temps le permet."""
        gray = cv2.cvtColor(zone, cv2.COLOR_BGR2GRAY)
        big = cv2.resize(gray, None, fx=2.4, fy=2.4, interpolation=cv2.INTER_CUBIC)
        clahe = cv2.createCLAHE(clipLimit=2.0, tileGridSize=(8, 8)).apply(big)
        _, otsu = cv2.threshold(cv2.GaussianBlur(clahe, (3, 3), 0), 0, 255, cv2.THRESH_BINARY | cv2.THRESH_OTSU)
        for variant, psm in ((clahe, 11), (otsu, 11), (clahe, 6)):
            remaining = deadline - time.monotonic()
            if remaining <= 0.5:
                return
            ok, encoded = cv2.imencode(".bmp", variant)
            try:
                result = subprocess.run(self.command(psm), input=encoded.tobytes(), capture_output=True,
                                        timeout=min(10.0, remaining), check=False)
            except (OSError, subprocess.TimeoutExpired):
                return
            if result.returncode == 0:
                yield result.stdout.decode("utf-8", "ignore")


def viz_zone(document: np.ndarray, face: Face, kind: str) -> np.ndarray:
    """Zone des champs imprimés : à droite du portrait ; pour un passeport, au-dessus de la MRZ (exclue : ses
    caractères ne doivent jamais servir à « retrouver » les champs qu'on compare à elle)."""
    height, width = document.shape[:2]
    x0 = min(width - 50, int(face.box[0] + face.box[2] * 1.15))
    y1 = int(height * (0.72 if kind == "passport" else 1.0))
    return document[:y1, x0:]


def check_sides(document: np.ndarray, face: Face, kind: str, reader: VizReader, birth: dt.date, expiry: dt.date,
                number: str, deadline: float) -> str | None:
    """None si la face imprimée concorde avec la MRZ ; sinon un code de motif."""
    seen = ""
    best = {"birth_date": False, "document_number": False, "expiry_date": False}
    for text in reader.texts(viz_zone(document, face, kind), deadline):
        seen += text
        matches = field_matches(seen, birth, expiry, number)
        best = {key: best[key] or matches[key] for key in best}
        if consistent(best):
            return None
    return "document_front_unreadable" if len(_compact(seen)) < 12 else "document_sides_mismatch"
