"""Liaison recto / verso / portrait (exigence E1 de l'audit) et attaques de non-régression A, B, C.

Attaquants (fictifs) : le « mineur » a sa propre carte (portrait obama2) et se filme (obama : une AUTRE photo
de la même personne) ; le « parent » a une carte d'adulte né en 1975 (portrait biden, ou obama pour C).
"""
import datetime as dt

import cv2
import numpy as np
import pytest

import synth
from veriage_biometrics import analysis, document, mrz

TODAY = dt.date(2026, 9, 27)
PARENT = dict(number="UT1111111", birth=dt.date(1975, 5, 5), expiry=dt.date(2030, 1, 1))
MINOR = dict(number="UT2222222", birth=dt.date(2011, 6, 2), expiry=dt.date(2031, 1, 1))


def bgr(image):
    return cv2.cvtColor(np.asarray(image.convert("RGB")), cv2.COLOR_RGB2BGR)


# -- Concordance VIZ / MRZ (logique pure) -------------------------------------------------------------

@pytest.mark.parametrize("text", [
    "DATE OF BIRTH 05.05.1975  N° 592-1111111-11  EXPIRY 01.01.2030",
    "Date de naissance / Geboortedatum 05 MAI 1975 ... Kaartnr UT1111111",
    "GEBURTSTAG 05 MAI 1975  GÜLTIG BIS 01 JAN 2030",
    "05 MEI 1975 / 01 JAN 2030",
    "born 05/05/1975 no UTIIII1II",          # confusions d'OCR I/1 sur le numéro
    "1975-05-05 2030-01-01",
])
def test_printed_fields_match_the_mrz_in_european_formats(text):
    number = "UT1111111" if "592" not in text else "592111111111"
    matches = document.field_matches(text, PARENT["birth"], PARENT["expiry"], number)
    assert document.consistent(matches), matches


def test_rule_requires_birth_date_and_a_second_field():
    assert not document.consistent({"birth_date": True, "document_number": False, "expiry_date": False})
    assert not document.consistent({"birth_date": False, "document_number": True, "expiry_date": True})
    assert document.consistent({"birth_date": True, "document_number": False, "expiry_date": True})
    # Date de naissance d'un autre (le mineur) : aucune concordance avec la MRZ du parent.
    other = document.field_matches("02.06.2011 UT2222222 01.01.2031", PARENT["birth"], PARENT["expiry"], PARENT["number"])
    assert not any(other.values())


def test_old_french_identity_card_is_unsupported():
    lines = ["IDFRASPECIMEN<<<<<<<<<<<<<<<<<<<<<<<", "8806923102858CORINNE<<<<<<<6512068F6"]  # spécimen public (2 × 36)
    with pytest.raises(mrz.MrzError) as error:
        mrz.parse(lines, TODAY)
    assert error.value.reason == "document_unsupported"


# -- Détection du document et du portrait -------------------------------------------------------------

@pytest.mark.parametrize("variant", ["flat", "tilted", "small", "blurred", "upside_down", "perspective"])
def test_genuine_card_front_is_detected_with_its_portrait(face_engine, assets, variant):
    card = synth.card_front(synth.load_asset("obama2.jpg"), **MINOR)
    image = {
        "flat": lambda: bgr(card),
        "tilted": lambda: bgr(synth.scene(card, angle=-4)),
        "small": lambda: bgr(synth.scene(card, angle=6, fill=0.55)),
        "blurred": lambda: bgr(synth.scene(card, angle=2, blur=1.2)),
        "upside_down": lambda: cv2.rotate(bgr(synth.scene(card, angle=2)), cv2.ROTATE_180),
        "perspective": lambda: _perspective(bgr(synth.scene(card, angle=1))),
    }[variant]()
    region = document.detect(image, "id_card")
    assert region is not None
    assert document.portrait(region, face_engine, "id_card") is not None


def _perspective(image):
    h, w = image.shape[:2]
    src = np.float32([[0, 0], [w, 0], [w, h], [0, h]])
    dst = np.float32([[w * 0.06, h * 0.04], [w * 0.97, 0], [w, h], [0, h * 0.95]])
    return cv2.warpPerspective(image, cv2.getPerspectiveTransform(src, dst), (w, h), borderMode=cv2.BORDER_REPLICATE)


def test_a_selfie_is_not_a_document(face_engine, assets):
    selfie = synth.selfie_frame(synth.load_asset("obama.jpg"))
    region = document.detect(selfie, "id_card")
    assert region is None or document.portrait(region, face_engine, "id_card") is None


# -- Analyse complète : parcours légitimes et attaques ----------------------------------------------

def analyze(services, kind, front, back, selfie_face="obama.jpg"):
    return analysis.analyze(synth.request(services, kind, front, back, synth.selfie_frame(synth.load_asset(selfie_face)), TODAY), services, TODAY)


@pytest.mark.parametrize("style", ["dots", "month", "slash"])
def test_genuine_card_passes(services, assets, style):
    front, back = synth.identity_card(synth.load_asset("obama2.jpg"), style=style, **MINOR)
    result = analyze(services, "id_card", front, back)
    assert result["reasons"] == [] and result["age"] == 15 and result["liveness_passed"] and result["face_match_score"] > 0.6


def test_genuine_passport_passes(services, assets):
    result = analyze(services, "passport", synth.passport(synth.load_asset("obama2.jpg"), **PARENT), None)
    assert result["reasons"] == [] and result["age"] == 51 and result["face_match_score"] > 0.6


def test_attack_a_selfie_as_front_with_parent_back_fails(services, assets):
    """A : recto = simple selfie du mineur, verso = carte du parent."""
    _, parent_back = synth.identity_card(synth.load_asset("biden.jpg"), **PARENT)
    selfie = synth.Image.fromarray(cv2.cvtColor(synth.selfie_frame(synth.load_asset("obama.jpg")), cv2.COLOR_BGR2RGB))
    result = analyze(services, "id_card", selfie, parent_back)
    assert result["face_match_score"] is None
    assert {"document_not_detected", "document_portrait_not_found"} & set(result["reasons"])


def test_attack_b_minor_front_with_parent_back_fails(services, assets):
    """B : vraie carte du mineur au recto, verso de la carte du parent."""
    minor_front, _ = synth.identity_card(synth.load_asset("obama2.jpg"), **MINOR)
    _, parent_back = synth.identity_card(synth.load_asset("biden.jpg"), **PARENT)
    result = analyze(services, "id_card", minor_front, parent_back)
    assert result["age"] == 51  # la MRZ lue est bien celle du parent…
    assert result["face_match_score"] is None and "document_sides_mismatch" in result["reasons"]  # …mais refusée


def test_attack_c_animated_document_portrait_fails(services, assets):
    """C : carte du parent seule ; le « selfie » est le portrait de la carte, recentré puis animé en 2D."""
    card = synth.card_front(synth.load_asset("obama.jpg"), **PARENT)
    portrait = cv2.resize(bgr(card)[200:546, 45:315], (360, 460))
    fake = np.full((480, 640, 3), 120, np.uint8)
    fake[10:470, 140:500] = portrait
    front, back = synth.scene(card, angle=-2), synth.scene(synth.card_back(synth.td1(**PARENT)), angle=2)
    payload = synth.request(services, "id_card", front, back, fake, TODAY)
    result = analysis.analyze(payload, services, TODAY)
    assert "face_identical_to_document" in result["reasons"] and result["liveness_passed"] is False


@pytest.mark.xfail(strict=True, reason="LIMITE CONNUE (documentée) : une AUTRE photo de la personne, animée en 2D "
                   "et injectée, passe le contrôle du vivant ; aucune détection d'injection ni de deepfake en V1")
def test_known_limit_other_photo_animated_is_refused(services, assets):
    front, back = synth.identity_card(synth.load_asset("obama2.jpg"), **PARENT)
    result = analyze(services, "id_card", front, back, selfie_face="obama.jpg")  # photo animée ≈ vrai selfie simulé
    assert result["liveness_passed"] is False


def test_document_type_must_match_the_mrz(services, assets):
    card_front, _ = synth.identity_card(synth.load_asset("obama2.jpg"), **PARENT)
    page = synth.passport(synth.load_asset("obama2.jpg"), **PARENT)
    result = analyze(services, "id_card", card_front, page)  # « carte » dont le verso est un passeport
    assert "document_type_mismatch" in result["reasons"] and result["mrz_valid"] is False


def test_unreadable_front_fields_fail(services, assets):
    """Recto sans champs imprimés lisibles (flouté par un fraudeur) : échec, jamais un succès par défaut."""
    front = synth.scene(synth.card_front(synth.load_asset("obama2.jpg")), angle=-3)  # aucun champ imprimé
    _, back = synth.identity_card(synth.load_asset("obama2.jpg"), **MINOR)
    result = analyze(services, "id_card", front, back)
    assert result["face_match_score"] is None
    assert {"document_front_unreadable", "document_sides_mismatch"} & set(result["reasons"])
