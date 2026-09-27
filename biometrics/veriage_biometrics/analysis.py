"""Analyse d'une demande : document (MRZ, portrait) + séquence de selfie (contrôle du vivant, comparaison).

Réponse, et RIEN d'autre : { age, doc_expired, face_match_score, liveness_passed, mrz_valid, reasons[] }.
Jamais d'image, de nom, de numéro ni de date de naissance. Les images décodées, les points du visage et
les empreintes restent des variables locales, libérées à la fin de la requête ; rien n'est écrit sur
disque ni journalisé.
"""
from __future__ import annotations

import datetime as dt
import logging
import re
import time
from dataclasses import dataclass

import numpy as np

from . import imaging, liveness
from .faces import FaceEngine
from .liveness import Landmarker, Measure
from .mrz_ocr import MrzReader, TesseractError
from .replay import moire_score

LOG = logging.getLogger("veriage.biometrics")

DOC_MAX_BYTES = 5 * 1024 * 1024
DOC_MAX_SIDE = 4096
FRAME_MAX_BYTES = 512 * 1024
FRAME_MAX_SIDE = 1920
MAX_CHALLENGES = 4
MRZ_BUDGET_S = 20.0
DATE = re.compile(r"^\d{4}-\d{2}-\d{2}$")
TOP_LEVEL = {"reference_date", "document", "selfie", "liveness"}

RESPONSE_KEYS = ("age", "doc_expired", "face_match_score", "liveness_passed", "mrz_valid", "reasons")


class RequestError(ValueError):
    """Demande mal formée (422) ; « code » est stable et ne contient jamais de donnée reçue."""

    def __init__(self, code: str) -> None:
        super().__init__(code)
        self.code = code


@dataclass
class Services:
    faces: FaceEngine
    mrz: MrzReader
    landmarker: Landmarker | None  # None : MediaPipe indisponible (repli YuNet, pas de clignement)


def _reference_date(value: object, today: dt.date) -> dt.date:
    if not isinstance(value, str) or not DATE.match(value):
        raise RequestError("reference_date_invalid")
    try:
        date = dt.date.fromisoformat(value)
    except ValueError:
        raise RequestError("reference_date_invalid") from None
    # PHP envoie la date du jour à Bruxelles ; un écart de plus d'un jour trahit une horloge fausse.
    if abs((date - today).days) > 1:
        raise RequestError("reference_date_invalid")
    return date


def _image(value: object, max_bytes: int, max_side: int) -> np.ndarray:
    try:
        return imaging.decode(imaging.b64decode(value, max_bytes), max_side)
    except imaging.ImageError as exc:
        raise RequestError(exc.reason) from None


def analyze(payload: object, services: Services, today: dt.date | None = None) -> dict:
    today = today or dt.date.today()
    if not isinstance(payload, dict) or set(payload) - TOP_LEVEL:
        raise RequestError("payload_invalid")
    reference = _reference_date(payload.get("reference_date"), today)
    document, selfie = payload.get("document"), payload.get("selfie")
    if not isinstance(document, dict) or set(document) - {"front", "back"} or not isinstance(selfie, dict) \
            or set(selfie) - {"challenge", "frames"}:
        raise RequestError("payload_invalid")
    challenge = selfie.get("challenge")
    if not isinstance(challenge, list) or not 1 <= len(challenge) <= MAX_CHALLENGES \
            or any(step not in liveness.CHALLENGES for step in challenge):
        raise RequestError("challenge_invalid")
    try:
        params = liveness.Params.from_request(payload.get("liveness"))
    except ValueError as exc:
        raise RequestError(str(exc)) from None
    frames = selfie.get("frames")
    if not isinstance(frames, list) or len(frames) > params.max_frames:
        raise RequestError("frames_invalid")

    front = _image(document.get("front"), DOC_MAX_BYTES, DOC_MAX_SIDE)
    back = _image(document["back"], DOC_MAX_BYTES, DOC_MAX_SIDE) if document.get("back") is not None else None
    reasons: list[str] = []

    # 1. MRZ : au verso (carte d'identité) ; sinon au recto (page du passeport, ou faces inversées).
    # Budget global de l'OCR (les deux faces), bien en deçà du délai de PHP (BIOMETRICS_TIMEOUT).
    deadline = time.monotonic() + MRZ_BUDGET_S
    try:
        reading = services.mrz.read(back if back is not None else front, reference, deadline)
        if reading.data is None and back is not None:
            second = services.mrz.read(front, reference, deadline)
            reading = second if second.data is not None else reading
    except TesseractError:
        LOG.error("tesseract indisponible")
        raise
    data = reading.data if reading.data is not None and reading.data.valid else None
    age = data.age_on(reference) if data is not None else None
    doc_expired = data.expired_on(reference) if data is not None else None
    if data is None:
        reasons.append(reading.reason or "mrz_not_found")
    elif doc_expired:
        reasons.append("document_expired")

    # 2. Portrait du document (recto ; verso si les faces ont été inversées).
    doc_face_image, doc_face = front, services.faces.document_face(front)
    if doc_face is None and back is not None:
        doc_face_image, doc_face = back, services.faces.document_face(back)
    doc_embedding = services.faces.embed(doc_face_image, doc_face) if doc_face is not None else None
    if doc_embedding is None:
        reasons.append("face_not_found_document")
    del front, back, doc_face_image

    # 3. Séquence du selfie : mesures image par image.
    measures, embeddings, kept = [], [], {}
    for index, frame in enumerate(frames):
        if not isinstance(frame, dict) or set(frame) != {"t", "step", "image"} \
                or not isinstance(frame["t"], int) or isinstance(frame["t"], bool) \
                or not isinstance(frame["step"], int) or isinstance(frame["step"], bool) \
                or not 0 <= frame["t"] <= 600_000 or not 0 <= frame["step"] <= MAX_CHALLENGES:
            raise RequestError("frames_invalid")
        image = _image(frame["image"], FRAME_MAX_BYTES, FRAME_MAX_SIDE)
        faces = services.faces.detect(image)
        yaw = ear = None
        embedding = None
        if len(faces) == 1:
            embedding = services.faces.embed(image, faces[0])
            if services.landmarker is not None:
                count, yaw, ear = services.landmarker.measure(image)
                if count != 1:
                    yaw = ear = None
            else:
                yaw = liveness.yunet_yaw(faces[0])
            if frame["step"] == 0:
                kept[index] = (image, faces[0])
        measures.append(Measure(t_ms=frame["t"], step=frame["step"], faces=len(faces),
                                score=faces[0].score if faces else 0.0, yaw=yaw, ear=ear))
        embeddings.append(embedding)

    ref_index = liveness.choose_reference(measures, params) if measures else None
    replay = None
    face_match = None
    if ref_index is not None and embeddings[ref_index] is not None:
        ref_embedding = embeddings[ref_index]
        measures = [Measure(m.t_ms, m.step, m.faces, m.score, m.yaw, m.ear,
                            FaceEngine.similarity(e, ref_embedding) if e is not None else None)
                    for m, e in zip(measures, embeddings)]
        ref_image, ref_face = kept[ref_index]
        replay = moire_score(ref_image, ref_face)
        if doc_embedding is not None:
            face_match = round(FaceEngine.similarity(doc_embedding, ref_embedding), 4)
    kept.clear()

    result = liveness.evaluate(measures, [str(c) for c in challenge], params, replay)
    reasons.extend(result.reasons)
    if not result.passed and not result.reasons:
        reasons.append("liveness_challenge_failed")

    return {
        "age": age,
        "doc_expired": doc_expired,
        "face_match_score": face_match,
        "liveness_passed": result.passed,
        "mrz_valid": data is not None,
        "reasons": list(dict.fromkeys(reasons)),
    }
