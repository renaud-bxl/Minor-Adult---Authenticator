"""Images SYNTHÉTIQUES de test : documents fictifs de l'« Utopie » (code UTO, celui des spécimens de
l'ICAO 9303), MRZ rendues en police OCR-B libre (domaine public, Matthew Skala, paquet fonts-ocr-b),
photos de scène (document posé sur une table, incliné, compressé).

Aucune vraie pièce d'identité. Les visages viennent de photos du domaine public téléchargées par
scripts/fetch_test_assets.sh (sommes SHA-256 vérifiées), jamais versionnées dans le dépôt.
"""
from __future__ import annotations

import datetime as dt
import io
import math
from pathlib import Path

import cv2
import numpy as np
from PIL import Image, ImageDraw, ImageFont

from veriage_biometrics import mrz

ASSETS = Path(__file__).resolve().parent / "assets"
FONT_CANDIDATES = [
    Path("/usr/share/fonts/opentype/ocr-b/OCRB.otf"),
    Path("/usr/share/fonts/truetype/ocr-b/OCRB.ttf"),
    Path("/Library/Fonts/OCRB.otf"),
]
PX_PER_MM = 12
CARD_MM = (85.6, 53.98)


def ocrb_font(size: int) -> ImageFont.FreeTypeFont:
    for path in FONT_CANDIDATES:
        if path.is_file():
            return ImageFont.truetype(str(path), size)
    raise FileNotFoundError("Police OCR-B absente (apt install fonts-ocr-b)")


def _field(value: str, width: int) -> str:
    value = value.upper().replace(" ", "<")
    return (value + "<" * width)[:width]


def _yymmdd(date: dt.date) -> str:
    return date.strftime("%y%m%d")


def td1(number: str, birth: dt.date, expiry: dt.date, surname: str = "SPECIMEN", given: str = "ANNA",
        nationality: str = "UTO", sex: str = "F", optional1: str = "", optional2: str = "", code: str = "ID") -> list[str]:
    number = _field(number, 9)
    line1 = _field(code, 2) + "UTO" + number + mrz.check_digit(number) + _field(optional1, 15)
    birth_s, expiry_s = _yymmdd(birth), _yymmdd(expiry)
    line2 = birth_s + mrz.check_digit(birth_s) + sex + expiry_s + mrz.check_digit(expiry_s) + _field(nationality, 3) + _field(optional2, 11)
    composite = line1[5:30] + line2[0:7] + line2[8:15] + line2[18:29]
    line2 += mrz.check_digit(composite)
    line3 = _field(surname + "<<" + given.replace(" ", "<"), 30)
    return [line1, line2, line3]


def td3(number: str, birth: dt.date, expiry: dt.date, surname: str = "SPECIMEN", given: str = "ANNA",
        nationality: str = "UTO", sex: str = "F", personal: str = "") -> list[str]:
    number = _field(number, 9)
    line1 = _field("P<UTO" + surname + "<<" + given.replace(" ", "<"), 44)
    birth_s, expiry_s, personal = _yymmdd(birth), _yymmdd(expiry), _field(personal, 14)
    personal_digit = "<" if set(personal) == {"<"} else mrz.check_digit(personal)
    line2 = number + mrz.check_digit(number) + _field(nationality, 3) + birth_s + mrz.check_digit(birth_s) + sex \
        + expiry_s + mrz.check_digit(expiry_s) + personal + personal_digit
    composite = line2[0:10] + line2[13:20] + line2[21:43]
    return [line1, line2 + mrz.check_digit(composite)]


def _guilloche(draw: ImageDraw.ImageDraw, size: tuple[int, int], color: tuple[int, int, int], seed: int) -> None:
    rng = np.random.default_rng(seed)
    width, height = size
    for k in range(18):
        amp, freq, phase = rng.uniform(8, 30), rng.uniform(0.004, 0.02), rng.uniform(0, 6.3)
        y0 = rng.uniform(0, height)
        points = [(x, y0 + amp * math.sin(freq * x + phase)) for x in range(0, width, 6)]
        draw.line(points, fill=color, width=1)


def card_back(lines: list[str], seed: int = 1) -> Image.Image:
    """Verso d'une carte ID-1 fictive, MRZ en bas (TD1)."""
    width, height = round(CARD_MM[0] * PX_PER_MM), round(CARD_MM[1] * PX_PER_MM)
    card = Image.new("RGB", (width, height), (236, 240, 232))
    draw = ImageDraw.Draw(card)
    _guilloche(draw, (width, height), (205, 214, 222), seed)
    label = ImageFont.load_default(size=26)
    draw.text((40, 40), "UTOPIA  -  SPECIMEN  -  NOT A REAL DOCUMENT", fill=(80, 90, 110), font=label)
    draw.text((40, 90), "Lieu de naissance / Place of birth : UTOPIA", fill=(60, 60, 70), font=label)
    # MRZ : 30 caractères sur ~76 mm (pas de 2,54 mm), en bas de la carte.
    font = _fit_font(30, 76.2 * PX_PER_MM)
    y = height - 3 * 1.4 * font.size - 30
    for line in lines:
        draw.text((round((width - font.getlength(line)) / 2), round(y)), line, fill=(20, 20, 25), font=font)
        y += 1.4 * font.size
    return card


def passport_page(lines: list[str], face: Image.Image | None = None, seed: int = 3) -> Image.Image:
    """Page de données d'un passeport fictif (TD3, 125 × 88 mm), photo et MRZ sur la même page."""
    width, height = round(125 * PX_PER_MM), round(88 * PX_PER_MM)
    page = Image.new("RGB", (width, height), (232, 236, 244))
    draw = ImageDraw.Draw(page)
    _guilloche(draw, (width, height), (200, 208, 226), seed)
    label = ImageFont.load_default(size=30)
    draw.text((60, 40), "UTOPIA  PASSPORT  -  SPECIMEN", fill=(60, 70, 110), font=label)
    if face is not None:
        page.paste(portrait(face, (340, 440)), (70, 120))
    font = _fit_font(44, 114 * PX_PER_MM)
    y = height - 2 * 1.45 * font.size - 40
    for line in lines:
        draw.text((round((width - font.getlength(line)) / 2), round(y)), line, fill=(20, 20, 25), font=font)
        y += 1.45 * font.size
    return page


def _fit_font(chars: int, target_width: float) -> ImageFont.FreeTypeFont:
    size = 20
    while ocrb_font(size + 1).getlength("<" * chars) <= target_width:
        size += 1
    return ocrb_font(size)


def portrait(face: Image.Image, size: tuple[int, int]) -> Image.Image:
    """Portrait recadré sur le visage (détection OpenCV pour centrer), désaturé comme une impression."""
    img = face.convert("RGB")
    w, h = img.size
    box = face_box(img)
    if box is None:
        cx, cy, side = w / 2, h / 2, min(w, h) / 2
    else:
        x, y, bw, bh = box
        cx, cy, side = x + bw / 2, y + bh / 2, max(bw, bh) * 1.1
    ratio = size[0] / size[1]
    ph = side * 2.0
    pw = ph * ratio
    crop = img.crop((round(cx - pw / 2), round(cy - ph * 0.45), round(cx + pw / 2), round(cy + ph * 0.55))).resize(size)
    arr = np.asarray(crop).astype(np.float32)
    gray = arr.mean(axis=2, keepdims=True)
    arr = 0.75 * arr + 0.25 * gray  # impression : couleurs légèrement désaturées
    return Image.fromarray(np.clip(arr, 0, 255).astype(np.uint8))


def face_box(img: Image.Image) -> tuple[int, int, int, int] | None:
    from veriage_biometrics.faces import default_model_dir
    path = default_model_dir() / "face_detection_yunet_2023mar.onnx"
    if not path.is_file():
        return None
    bgr = cv2.cvtColor(np.asarray(img.convert("RGB")), cv2.COLOR_RGB2BGR)
    detector = cv2.FaceDetectorYN.create(str(path), "", (bgr.shape[1], bgr.shape[0]), 0.7, 0.3, 50)
    _, faces = detector.detect(bgr)
    if faces is None or len(faces) == 0:
        return None
    x, y, bw, bh = faces[int(np.argmax(faces[:, 2] * faces[:, 3]))][:4]
    return int(x), int(y), int(bw), int(bh)


def card_front(face: Image.Image, seed: int = 2) -> Image.Image:
    """Recto d'une carte ID-1 fictive : portrait à gauche, champs fictifs à droite."""
    width, height = round(CARD_MM[0] * PX_PER_MM), round(CARD_MM[1] * PX_PER_MM)
    card = Image.new("RGB", (width, height), (230, 238, 246))
    draw = ImageDraw.Draw(card)
    _guilloche(draw, (width, height), (196, 210, 228), seed)
    card.paste(portrait(face, (270, 346)), (45, 200))
    title = ImageFont.load_default(size=34)
    label = ImageFont.load_default(size=24)
    draw.text((45, 40), "UTOPIA  -  IDENTITY CARD  -  SPECIMEN", fill=(40, 60, 120), font=title)
    for i, text in enumerate(["NOM / SURNAME", "SPECIMEN", "PRENOMS / GIVEN NAMES", "ANNA", "NOT A REAL DOCUMENT"]):
        draw.text((360, 210 + 50 * i), text, fill=(50, 50, 60), font=label)
    return card


def scene(document: Image.Image, angle: float = 3.0, size: tuple[int, int] = (1280, 960), fill: float = 0.8,
          seed: int = 7, blur: float = 0.0) -> Image.Image:
    """Document photographié : posé sur un fond texturé, légèrement tourné, flou et bruit éventuels."""
    rng = np.random.default_rng(seed)
    bg = rng.normal(120, 18, (size[1], size[0], 3)).astype(np.float32)
    bg = cv2.GaussianBlur(bg, (0, 0), 6) + np.array([10, 30, 60], np.float32)  # bois
    doc = np.asarray(document.convert("RGB"))[:, :, ::-1].astype(np.float32)
    scale = fill * size[0] / doc.shape[1]
    doc = cv2.resize(doc, (round(doc.shape[1] * scale), round(doc.shape[0] * scale)), interpolation=cv2.INTER_AREA)
    h, w = doc.shape[:2]
    matrix = cv2.getRotationMatrix2D((w / 2, h / 2), angle, 1.0)
    cos, sin = abs(matrix[0, 0]), abs(matrix[0, 1])
    nw, nh = int(h * sin + w * cos), int(h * cos + w * sin)
    matrix[0, 2] += nw / 2 - w / 2
    matrix[1, 2] += nh / 2 - h / 2
    rotated = cv2.warpAffine(doc, matrix, (nw, nh), borderValue=(0, 0, 0))
    mask = cv2.warpAffine(np.ones((h, w), np.float32), matrix, (nw, nh))
    x0, y0 = (size[0] - nw) // 2, (size[1] - nh) // 2
    region = bg[y0:y0 + nh, x0:x0 + nw]
    region[:] = region * (1 - mask[..., None]) + rotated * mask[..., None]
    out = np.clip(bg + rng.normal(0, 3, bg.shape), 0, 255).astype(np.uint8)
    if blur > 0:
        out = cv2.GaussianBlur(out, (0, 0), blur)
    return Image.fromarray(out[:, :, ::-1])


def jpeg(image: Image.Image, quality: int = 88) -> bytes:
    buffer = io.BytesIO()
    image.convert("RGB").save(buffer, "JPEG", quality=quality)
    return buffer.getvalue()


def png(image: Image.Image) -> bytes:
    buffer = io.BytesIO()
    image.save(buffer, "PNG")
    return buffer.getvalue()


def load_asset(name: str) -> Image.Image:
    path = ASSETS / name
    if not path.is_file():
        raise FileNotFoundError(f"{path} absent : lancez biometrics/scripts/fetch_test_assets.sh")
    return Image.open(path).convert("RGB")


# -- Visages : séquences simulées pour le contrôle du vivant -----------------------------------

def yaw_warp(image: np.ndarray, box: tuple[int, int, int, int], strength: float) -> np.ndarray:
    """Simule une rotation de la tête (lacet) en 2D : déplacement horizontal lisse, maximal au centre
    du visage (le nez se décale par rapport aux joues, comme lors d'une vraie rotation).
    strength > 0 : le nez part vers la DROITE de l'image (la personne tourne la tête vers SA gauche)."""
    h, w = image.shape[:2]
    x, y, bw, bh = box
    cx, cy = x + bw / 2, y + bh / 2
    xs, ys = np.meshgrid(np.arange(w, dtype=np.float32), np.arange(h, dtype=np.float32))
    u = np.clip((xs - x) / bw, 0, 1)
    v = np.clip(1 - np.abs(ys - cy) / (bh * 0.9), 0, 1)
    shift = strength * bw * 0.22 * np.sin(np.pi * u) * v
    return cv2.remap(image, (xs - shift).astype(np.float32), ys, cv2.INTER_LINEAR, borderMode=cv2.BORDER_REPLICATE)


def close_eyes(image: np.ndarray, landmarks: np.ndarray) -> np.ndarray:
    """Simule des paupières fermées : chaque œil (contour MediaPipe) est recouvert de la couleur de la
    peau voisine (sous le sourcil), avec un trait de cils."""
    out = image.copy()
    for eye in (LEFT_EYE_RING, RIGHT_EYE_RING):
        pts = landmarks[eye].astype(np.int32)
        x, y, w, h = cv2.boundingRect(pts)
        skin = image[max(0, y - h - 6):max(1, y - 4), x:x + w].reshape(-1, 3)
        color = tuple(int(c) for c in np.median(skin, axis=0)) if len(skin) else (150, 120, 110)
        cv2.fillConvexPoly(out, cv2.convexHull(pts), color)
        mid = y + h // 2
        cv2.line(out, (x, mid), (x + w, mid), (40, 30, 30), 2)
    return out


def selfie_frame(face: Image.Image, size: tuple[int, int] = (640, 480)) -> np.ndarray:
    """Cadre de webcam (640 × 480) centré sur le visage d'une photo."""
    img = face.convert("RGB")
    box = face_box(img)
    w, h = img.size
    cx, cy, side = (w / 2, h / 2, min(w, h) / 3) if box is None else (box[0] + box[2] / 2, box[1] + box[3] / 2, max(box[2], box[3]))
    crop_h = side * 3.0
    crop_w = crop_h * size[0] / size[1]
    crop = img.crop((round(cx - crop_w / 2), round(cy - crop_h / 2), round(cx + crop_w / 2), round(cy + crop_h / 2))).resize(size, Image.BICUBIC)
    return cv2.cvtColor(np.asarray(crop), cv2.COLOR_RGB2BGR)


def selfie_sequence(base: np.ndarray, challenge: list[str], landmarks: np.ndarray, box: tuple[int, int, int, int],
                    step_ms: int = 1500, interval_ms: int = 125, neutral_frames: int = 6, seed: int = 11,
                    quality: int = 85) -> list[dict]:
    """Séquence simulée : fenêtre neutre (step 0), puis une fenêtre par défi, dans l'ordre demandé.
    Chaque image : légère variation d'exposition et bruit (capteur), JPEG."""
    rng = np.random.default_rng(seed)
    frames, t = [], 0

    def add(img: np.ndarray, step: int) -> None:
        nonlocal t
        noisy = np.clip(img.astype(np.float32) * rng.uniform(0.97, 1.03) + rng.normal(0, 2, img.shape), 0, 255).astype(np.uint8)
        ok, buf = cv2.imencode(".jpg", noisy, [cv2.IMWRITE_JPEG_QUALITY, quality])
        frames.append({"t": t, "step": step, "jpeg": buf.tobytes()})
        t += interval_ms

    for _ in range(neutral_frames):
        add(base, 0)
    closed = close_eyes(base, landmarks)
    count = max(4, step_ms // interval_ms)
    for step, action in enumerate(challenge, start=1):
        for i in range(count):
            phase = np.sin(np.pi * i / (count - 1))  # 0 → 1 → 0
            if action == "turn_left":
                add(yaw_warp(base, box, 1.3 * phase), step)
            elif action == "turn_right":
                add(yaw_warp(base, box, -1.3 * phase), step)
            elif action == "blink":
                add(closed if count // 3 <= i < count // 3 + 2 else base, step)
            else:
                add(base, step)
    return frames


# Contours des yeux (indices MediaPipe Face Mesh).
LEFT_EYE_RING = [33, 7, 163, 144, 145, 153, 154, 155, 133, 173, 157, 158, 159, 160, 161, 246]
RIGHT_EYE_RING = [362, 382, 381, 380, 374, 373, 390, 249, 263, 466, 388, 387, 386, 385, 384, 398]
