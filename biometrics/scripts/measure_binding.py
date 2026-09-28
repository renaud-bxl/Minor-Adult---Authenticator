#!/usr/bin/env python3
"""Mesure de la liaison recto / verso sur un jeu SYNTHÉTIQUE : taux de faux rejets (vraies pièces refusées)
et taux de détection des pièces combinées (attaque B). Outil de mesure, jamais utilisé en production.

    .venv/bin/python scripts/measure_binding.py

Limite : jeu synthétique (police, fond, éclairage maîtrisés). Les taux réels, sur de vraies photos de
téléphone, seront plus mauvais et doivent être mesurés avant la production (docs/rgpd.md, AIPD).
"""
from __future__ import annotations

import datetime as dt
import itertools
import sys
import time
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
sys.path[:0] = [str(ROOT), str(ROOT / "tests")]

import cv2  # noqa: E402
import numpy as np  # noqa: E402

import synth  # noqa: E402
from veriage_biometrics import document  # noqa: E402
from veriage_biometrics.faces import FaceEngine  # noqa: E402
from veriage_biometrics.mrz_ocr import MrzReader  # noqa: E402

TODAY = dt.date(2026, 9, 27)


def bgr(image):
    return cv2.cvtColor(np.asarray(image.convert("RGB")), cv2.COLOR_RGB2BGR)


def bound(faces, mrz_reader, viz, kind, front, mrz_image) -> bool:
    reading = mrz_reader.read(mrz_image, TODAY, time.monotonic() + 20)
    if reading.data is None:
        return False
    region = document.detect(front, kind)
    located = document.portrait(region, faces, kind) if region is not None else None
    if located is None:
        return False
    data = reading.data
    return document.check_sides(located[0], located[1], kind, viz, data.birth_date, data.expiry_date,
                                data.document_number, time.monotonic() + 15) is None


def main() -> int:
    models = ROOT / "models"
    faces, mrz_reader, viz = FaceEngine(models), MrzReader(models / "tessdata"), document.VizReader(models / "tessdata")
    people = [("obama2.jpg", "UT1234567", dt.date(2000, 3, 14), dt.date(2031, 5, 20)),
              ("biden.jpg", "AB7654321", dt.date(1975, 5, 5), dt.date(2030, 1, 1)),
              ("astronaut.png", "ZZ0011223", dt.date(2009, 12, 31), dt.date(2029, 7, 9))]
    variants = list(itertools.product(["dots", "month", "slash"], [(-6, 0.8, 0), (3, 0.6, 0), (-2, 0.85, 1.2), (5, 0.7, 0.8)]))
    genuine = rejected = attacks = caught = 0
    for (face, number, birth, expiry), (style, (angle, fill, blur)) in itertools.product(people, variants):
        card = synth.card_front(synth.load_asset(face), number, birth, expiry, style)
        front = bgr(synth.scene(card, angle=angle, fill=fill, blur=blur))
        back = bgr(synth.scene(synth.card_back(synth.td1(number, birth, expiry)), angle=-angle / 2))
        genuine += 1
        rejected += not bound(faces, mrz_reader, viz, "id_card", front, back)
        page = synth.passport_page(synth.td3(number, birth, expiry), synth.load_asset(face), number=number, birth=birth,
                                   expiry=expiry, style=style)
        page_img = bgr(synth.scene(page, angle=angle / 2, fill=max(fill, 0.75), blur=blur))
        genuine += 1
        rejected += not bound(faces, mrz_reader, viz, "passport", page_img, page_img)
        # Attaque B : recto de cette personne, verso d'une autre.
        other = people[(people.index((face, number, birth, expiry)) + 1) % len(people)]
        other_back = bgr(synth.scene(synth.card_back(synth.td1(other[1], other[2], other[3])), angle=2))
        attacks += 1
        caught += not bound(faces, mrz_reader, viz, "id_card", front, other_back)
    print(f"Pièces authentiques : {genuine}, faux rejets : {rejected} ({100 * rejected / genuine:.1f} %)")
    print(f"Pièces combinées (attaque B) : {attacks}, refusées : {caught} ({100 * caught / attacks:.1f} %)")
    return 0


if __name__ == "__main__":
    sys.exit(main())
