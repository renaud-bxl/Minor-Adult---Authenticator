"""Fixtures partagées. Les tests qui exigent les modèles (scripts/fetch_models.sh), Tesseract ou les
photos de test (scripts/fetch_test_assets.sh) sont SAUTÉS, avec la raison, quand ils manquent : les
tests de logique pure (MRZ, HMAC, liveness simulé) tournent partout."""
from __future__ import annotations

import datetime as dt
import shutil
from pathlib import Path

import pytest

ROOT = Path(__file__).resolve().parent.parent
MODELS = ROOT / "models"
ASSETS = Path(__file__).resolve().parent / "assets"
TODAY = dt.date(2026, 9, 27)


def _require(path: Path, hint: str) -> None:
    if not path.exists():
        pytest.skip(f"{path.name} absent : {hint}")


@pytest.fixture(scope="session")
def models_dir() -> Path:
    for name in ("face_detection_yunet_2023mar.onnx", "face_recognition_sface_2021dec.onnx", "face_landmarker.task"):
        _require(MODELS / name, "lancez biometrics/scripts/fetch_models.sh")
    return MODELS


@pytest.fixture(scope="session")
def face_engine(models_dir):
    from veriage_biometrics.faces import FaceEngine
    return FaceEngine(models_dir)


@pytest.fixture(scope="session")
def landmarker(models_dir):
    from veriage_biometrics.liveness import Landmarker
    instance = Landmarker(models_dir)
    yield instance
    instance.close()


@pytest.fixture(scope="session")
def mrz_reader():
    if shutil.which("tesseract") is None:
        pytest.skip("tesseract absent : apt install tesseract-ocr")
    from veriage_biometrics.mrz_ocr import MrzReader
    return MrzReader(MODELS / "tessdata")


@pytest.fixture(scope="session")
def services(face_engine, landmarker, mrz_reader):
    from veriage_biometrics.analysis import Services
    return Services(faces=face_engine, mrz=mrz_reader, landmarker=landmarker)


@pytest.fixture(scope="session")
def assets() -> Path:
    for name in ("astronaut.png", "obama.jpg", "biden.jpg"):
        _require(ASSETS / name, "lancez biometrics/scripts/fetch_test_assets.sh")
    return ASSETS
