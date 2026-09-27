"""Lecture OCR de la MRZ (Tesseract) sur des documents SYNTHÉTIQUES photographiés (OCR-B libre)."""
import datetime as dt
import os
import shutil
import subprocess

import cv2
import numpy as np
import pytest

import synth

TODAY = dt.date(2026, 9, 27)


def bgr(image):
    return cv2.cvtColor(np.asarray(image.convert("RGB")), cv2.COLOR_RGB2BGR)


@pytest.mark.parametrize("angle,fill,blur", [(0, 0.85, 0), (4, 0.8, 0), (-7, 0.55, 0), (2, 0.75, 1.2)])
def test_td1_card_back_photographed(mrz_reader, angle, fill, blur):
    lines = synth.td1("AB1234567", dt.date(2008, 9, 27), dt.date(2031, 5, 20))
    image = bgr(synth.scene(synth.card_back(lines), angle=angle, fill=fill, blur=blur))
    reading = mrz_reader.read(image, TODAY)
    assert reading.data is not None and reading.data.valid, reading.reason
    assert reading.data.format == "TD1"
    assert reading.data.age_on(TODAY) == 18  # anniversaire le jour même
    assert not reading.data.expired_on(TODAY)


def test_td3_passport_page(mrz_reader, assets):
    lines = synth.td3("L898902C3", dt.date(1990, 2, 28), dt.date(2025, 1, 1))
    page = synth.passport_page(lines, synth.load_asset("astronaut.png"))
    reading = mrz_reader.read(bgr(synth.scene(page, angle=-3, fill=0.85)), TODAY)
    assert reading.data is not None and reading.data.valid and reading.data.format == "TD3"
    assert reading.data.expired_on(TODAY)  # expiré le 1er janvier 2025


def test_upside_down_card_is_read(mrz_reader):
    lines = synth.td1("ZZ9876543", dt.date(1985, 6, 1), dt.date(2029, 1, 1))
    image = cv2.rotate(bgr(synth.scene(synth.card_back(lines), angle=2)), cv2.ROTATE_180)
    reading = mrz_reader.read(image, TODAY)
    assert reading.data is not None and reading.data.valid


def test_front_without_mrz_is_not_found(mrz_reader, assets):
    reading = mrz_reader.read(bgr(synth.scene(synth.card_front(synth.load_asset("astronaut.png")))), TODAY)
    assert reading.data is None and reading.reason.startswith("mrz_")


def test_falsified_check_digit_is_refused(mrz_reader):
    lines = synth.td1("AB1234567", dt.date(2008, 9, 27), dt.date(2031, 5, 20))
    # Date de naissance modifiée (2007 au lieu de 2008) sans recalcul des chiffres de contrôle.
    forged = [lines[0], "07" + lines[1][2:], lines[2]]
    reading = mrz_reader.read(bgr(synth.scene(synth.card_back(forged), angle=1)), TODAY)
    assert reading.data is None and reading.reason == "mrz_checksum_failed"


@pytest.mark.skipif(shutil.which("strace") is None, reason="strace absent")
def test_tesseract_writes_no_file(mrz_reader, tmp_path):
    """Tesseract lit l'image sur son entrée standard et n'ouvre aucun fichier en écriture."""
    lines = synth.td1("AB1234567", dt.date(2000, 1, 1), dt.date(2031, 1, 1))
    image = bgr(synth.card_back(lines))
    ok, bmp = cv2.imencode(".bmp", cv2.cvtColor(image, cv2.COLOR_BGR2GRAY))
    trace = tmp_path / "trace.txt"
    cmd = ["strace", "-f", "-e", "trace=open,openat,creat", "-o", str(trace), *mrz_reader.command()]
    result = subprocess.run(cmd, cwd=tmp_path, input=bmp.tobytes(), capture_output=True, timeout=30, env={**os.environ, "OMP_THREAD_LIMIT": "1"})
    assert result.returncode == 0
    writes = [line for line in trace.read_text().splitlines()
              if ("O_WRONLY" in line or "O_RDWR" in line or "O_CREAT" in line or "creat(" in line) and "= -1" not in line
              and "/dev/" not in line]
    assert writes == [], writes
    assert sorted(p.name for p in tmp_path.iterdir()) == ["trace.txt"]  # rien d'autre créé dans le dossier courant
    assert "AB1234567" in result.stdout.decode()
