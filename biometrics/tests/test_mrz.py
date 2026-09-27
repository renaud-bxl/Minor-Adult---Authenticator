"""MRZ : chiffres de contrôle 7-3-1, formats TD1/TD2/TD3, âge exact, expiration (vecteurs partagés avec PHP)."""
import datetime as dt
import json
from pathlib import Path

import pytest

from veriage_biometrics import mrz

VECTORS = json.loads((Path(__file__).parent / "fixtures" / "mrz_vectors.json").read_text())["vectors"]


@pytest.mark.parametrize("vector", VECTORS, ids=[v["name"] for v in VECTORS])
def test_shared_vectors(vector):
    today = dt.date.fromisoformat(vector["reference_date"])
    expected = vector["expected"]
    if "error" in expected:
        with pytest.raises(mrz.MrzError) as error:
            mrz.parse(vector["lines"], today)
        assert error.value.reason == expected["error"]
        return
    data = mrz.parse(vector["lines"], today)
    assert data.format == expected["format"]
    assert data.valid is expected["valid"]
    if expected["valid"]:
        assert data.age_on(today) == expected["age"]
        assert data.expired_on(today) is expected["expired"]
        assert data.birth_date.isoformat() == expected["birth_date"]
        assert data.expiry_date.isoformat() == expected["expiry_date"]


@pytest.mark.parametrize("field,digit", [("D23145890", "7"), ("740812", "2"), ("120415", "9"), ("L898902C3", "6"),
                                         ("ZE184226B<<<<<", "1"), ("<<<<<<<<<", "0")])
def test_check_digit_icao_examples(field, digit):
    assert mrz.check_digit(field) == digit


def test_empty_optional_field_accepts_filler_check_digit():
    assert mrz.check_ok("<<<<<<<<<<<<<<", "<")
    assert not mrz.check_ok("AB<<<<<<<<<<<<", "<")


@pytest.mark.parametrize("birth,today,age", [
    (dt.date(2008, 9, 27), dt.date(2026, 9, 27), 18),  # anniversaire le jour même
    (dt.date(2008, 9, 28), dt.date(2026, 9, 27), 17),  # veille de l'anniversaire
    (dt.date(2008, 2, 29), dt.date(2026, 2, 28), 17),  # né un 29 février, année non bissextile
    (dt.date(2008, 2, 29), dt.date(2026, 3, 1), 18),
    (dt.date(2008, 2, 29), dt.date(2028, 2, 29), 20),  # année bissextile
    (dt.date(2000, 1, 1), dt.date(2000, 1, 1), 0),
])
def test_exact_age(birth, today, age):
    assert mrz.age_on(birth, today) == age


def test_birth_date_in_future_is_rejected():
    with pytest.raises(mrz.MrzError):
        mrz.age_on(dt.date(2030, 1, 1), dt.date(2026, 1, 1))


def test_century_is_the_latest_past_date():
    today = dt.date(2026, 9, 27)
    assert mrz.birth_date("260927", today).year == 2026
    assert mrz.birth_date("260928", today).year == 1926  # demain en 2026 : forcément 1926
    assert mrz.birth_date("991231", today).year == 1999
    assert mrz.expiry_date("310520", today).year == 2031
    assert mrz.expiry_date("990101", today).year == 1999  # plus de 50 ans dans le futur : 1999


def test_invalid_dates():
    today = dt.date(2026, 9, 27)
    for field in ("001332", "000230", "0A0101", "000000"):
        with pytest.raises(mrz.MrzError):
            mrz.birth_date(field, today)
    with pytest.raises(mrz.MrzError):
        mrz.expiry_date("31<<<<", today)  # expiration incomplète : refus


def test_parsed_data_holds_no_identity():
    """Ni nom, ni numéro, ni zone facultative (numéro de registre national belge) dans l'objet gardé."""
    data = mrz.parse(VECTORS[0]["lines"], dt.date(2026, 9, 27))
    kept = repr(data)
    for secret in ("ERIKSSON", "ANNA", "D23145890"):
        assert secret not in kept
    assert set(data.__dataclass_fields__) == {"format", "document_code", "birth_date", "expiry_date", "checks"}


def test_document_number_ambiguity_resolved_by_check_digit():
    lines = ["I<UTOD23I458907<<<<<<<<<<<<<<<", "7408122F1204159UTO<<<<<<<<<<<6", "ERIKSSON<<ANNA<MARIA<<<<<<<<<<"]
    assert mrz.parse(lines, dt.date(2026, 9, 27)).valid  # « I » lu à la place de « 1 »


def test_candidate_lines_from_noisy_ocr_text():
    text = "garbage\nI<UTOD231458907<<<<<<<<<<<<<\n7408122F1204159UTO<<<<<<<<<<<6\nERIKSSON<<ANNA<MARIA<<<<<<<<<<\n"
    candidates = mrz.candidate_lines(text)
    assert candidates and all(len(line) == 30 for line in candidates[0])
    assert mrz.parse(candidates[0], dt.date(2026, 9, 27)).valid
