"""Visages (YuNet + SFace) et détection basique de rejeu d'écran (moiré), sur photos du domaine public."""
import cv2
import numpy as np

import synth
from veriage_biometrics.faces import SFACE_COSINE_SAME
from veriage_biometrics.replay import moire_score


def bgr(name):
    return cv2.cvtColor(np.asarray(synth.load_asset(name)), cv2.COLOR_RGB2BGR)


def embedding(engine, image):
    face = engine.document_face(image)
    assert face is not None
    return engine.embed(image, face)


def test_document_portrait_matches_the_same_person_only(face_engine, assets):
    selfie = synth.selfie_frame(synth.load_asset("astronaut.png"))
    same_card = cv2.cvtColor(np.asarray(synth.scene(synth.card_front(synth.load_asset("astronaut.png")), angle=-4)), cv2.COLOR_RGB2BGR)
    other_card = cv2.cvtColor(np.asarray(synth.scene(synth.card_front(synth.load_asset("obama.jpg")), angle=3)), cv2.COLOR_RGB2BGR)
    reference = embedding(face_engine, selfie)
    assert face_engine.similarity(reference, embedding(face_engine, same_card)) > 0.6
    assert face_engine.similarity(reference, embedding(face_engine, other_card)) < SFACE_COSINE_SAME
    assert face_engine.similarity(embedding(face_engine, bgr("obama.jpg")), embedding(face_engine, bgr("biden.jpg"))) < SFACE_COSINE_SAME


def test_largest_face_is_the_document_portrait(face_engine, assets):
    """Carte belge : une petite image fantôme accompagne le portrait ; on retient le plus grand visage."""
    card = synth.card_front(synth.load_asset("astronaut.png"))
    ghost = synth.portrait(synth.load_asset("obama.jpg"), (90, 116))
    card.paste(ghost, (880, 480))
    image = cv2.cvtColor(np.asarray(card), cv2.COLOR_RGB2BGR)
    faces = face_engine.detect(image)
    assert len(faces) == 2
    main = face_engine.document_face(image)
    assert main.box[0] < 400  # le portrait de gauche, pas l'image fantôme


def test_no_face_on_the_back_of_the_card(face_engine):
    import datetime as dt
    back = synth.card_back(synth.td1("UT1234567", dt.date(2000, 1, 1), dt.date(2030, 1, 1)))
    assert face_engine.detect(cv2.cvtColor(np.asarray(back), cv2.COLOR_RGB2BGR)) == []


def _encode(image, quality=85):
    return cv2.imdecode(cv2.imencode(".jpg", image, [cv2.IMWRITE_JPEG_QUALITY, quality])[1], cv2.IMREAD_COLOR)


def test_moire_score_separates_natural_faces_from_screen_patterns(face_engine, assets):
    for name in ("astronaut.png", "obama.jpg", "biden.jpg"):
        image = bgr(name)
        if image.shape[1] < 600:
            image = cv2.resize(image, None, fx=2, fy=2, interpolation=cv2.INTER_CUBIC)
        face = face_engine.detect(image)[0]
        natural = moire_score(_encode(image), face)
        yy, xx = np.mgrid[0:image.shape[0], 0:image.shape[1]]
        grating = 6 * np.sin(2 * np.pi * (xx * np.cos(0.3) + yy * np.sin(0.3)) / 3.3)
        screen = _encode(np.clip(image.astype(np.float32) + grating[..., None], 0, 255).astype(np.uint8))
        assert natural < 30 < moire_score(screen, face), name
