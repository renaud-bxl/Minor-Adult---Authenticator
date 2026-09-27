"""MRZ : chiffres de contrôle 7-3-1, formats TD1/TD2/TD3, âge exact, expiration. La MRZ ne sort jamais du
service : seule la règle d'âge est partagée avec PHP (tests/Unit/AgeCalculatorTest.php lit ces vecteurs)."""
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
    # Le numéro est gardé en mémoire pour lier les faces de la pièce, mais n'apparaît dans aucune trace.
    assert set(data.__dataclass_fields__) == {"format", "document_code", "birth_date", "expiry_date", "checks", "document_number"}
    assert data.document_number == "D23145890" and "D23145890" not in repr(data)


def test_document_number_ambiguity_resolved_by_check_digit():
    lines = ["I<UTOD23I458907<<<<<<<<<<<<<<<", "7408122F1204159UTO<<<<<<<<<<<6", "ERIKSSON<<ANNA<MARIA<<<<<<<<<<"]
    assert mrz.parse(lines, dt.date(2026, 9, 27)).valid  # « I » lu à la place de « 1 »


def test_candidate_lines_from_noisy_ocr_text():
    text = "garbage\nI<UTOD231458907<<<<<<<<<<<<<\n7408122F1204159UTO<<<<<<<<<<<6\nERIKSSON<<ANNA<MARIA<<<<<<<<<<\n"
    candidates = mrz.candidate_lines(text)
    assert candidates and all(len(line) == 30 for line in candidates[0])
    assert mrz.parse(candidates[0], dt.date(2026, 9, 27)).valid


def test_real_layout_german_id_with_filler_sex_and_short_state():
    """Carte d'identité allemande (spécimen public « Mustermann ») : État « D<< », sexe « < »."""
    lines = ["IDD<<T220001293<<<<<<<<<<<<<<<", "6408125<2010315D<<<<<<<<<<<<<4", "MUSTERMANN<<ERIKA<<<<<<<<<<<<<"]
    data = mrz.parse(lines, dt.date(2026, 9, 27))
    assert data.valid and data.format == "TD1"
    assert data.birth_date == dt.date(1964, 8, 12) and data.expiry_date == dt.date(2020, 10, 31)


def test_td1_long_document_number_overflows_into_the_optional_field():
    """ICAO 9303-5 : numéro de plus de 9 caractères, « < » en position 15, suite et chiffre de contrôle
    dans la zone facultative ; le composite porte sur la ligne telle qu'imprimée."""
    number, overflow = "D23145890", "734"
    digit = mrz.check_digit(number + overflow)
    line1 = ("I<UTO" + number + "<" + overflow + digit + "<" * 30)[:30]
    line2 = "7408122F260415" + mrz.check_digit("260415") + "UTO<<<<<<<<<<<"
    line2 += mrz.check_digit(line1[5:30] + line2[0:7] + line2[8:15] + line2[18:29])
    data = mrz.parse([line1, line2, "ERIKSSON<<ANNA<MARIA<<<<<<<<<<"], dt.date(2026, 9, 27))
    assert data.valid and dict(data.checks)["document_number"] is True


def test_expiry_century_and_unknown_birth_parts_are_prudent():
    today = dt.date(2026, 9, 27)
    assert mrz.expiry_date("760101", today) == dt.date(2076, 1, 1)
    assert mrz.expiry_date("770101", today) == dt.date(1977, 1, 1)  # au-delà de 50 ans : siècle précédent
    # Mois et jour inconnus : date la plus tardive possible (âge le plus bas).
    assert mrz.birth_date("08<<<<", today) == dt.date(2008, 12, 31)
    assert mrz.age_on(mrz.birth_date("08<<<<", today), today) == 17
