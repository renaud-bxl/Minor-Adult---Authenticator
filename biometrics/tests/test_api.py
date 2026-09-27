"""API locale : authentification HMAC, limites, contrat de réponse strict, journaux sans donnée personnelle.
Analyse complète sur des images synthétiques (document fictif + séquence de selfie simulée)."""
import base64
import datetime as dt
import hashlib
import json
import logging
import time

import pytest
from fastapi.testclient import TestClient

import synth
from veriage_biometrics.analysis import RESPONSE_KEYS
from veriage_biometrics.api import create_app
from veriage_biometrics.config import Settings
from veriage_biometrics.security import request_base, response_base, sign

SECRET = "t" * 48
NONCE_COUNTER = iter(range(10**6))


def signed(method, path, body=b""):
    ts, nonce = str(int(time.time())), f"nonce-{next(NONCE_COUNTER):010d}-abcdef"
    return {"X-VeriAge-Timestamp": ts, "X-VeriAge-Nonce": nonce,
            "X-VeriAge-Signature": sign(SECRET.encode(), request_base(ts, nonce, method, path, body)),
            "Content-Type": "application/json"}


def check_response_signature(response, nonce):
    expected = sign(SECRET.encode(), response_base(nonce, response.status_code, response.content))
    assert response.headers["x-veriage-signature"] == expected


@pytest.fixture(scope="module")
def client(services):
    settings = Settings(secret=SECRET.encode(), max_body_bytes=8 * 1024 * 1024)
    with TestClient(create_app(settings, services)) as c:
        yield c


@pytest.fixture(scope="module")
def payload(services, assets):
    """Carte fictive cohérente (recto : portrait obama2 + champs imprimés ; verso : MRZ TD1) et séquence de
    selfie d'une AUTRE photo de la même personne (obama), conforme aux 4 défis."""
    front, back = synth.identity_card(synth.load_asset("obama2.jpg"), "AB1234567", eighteen_tomorrow(), dt.date(2031, 5, 20))
    return synth.request(services, "id_card", front, back, synth.selfie_frame(synth.load_asset("obama.jpg")), dt.date.today())


def eighteen_tomorrow() -> dt.date:
    """Date de naissance d'une personne qui aura 18 ans demain (âge attendu : 17)."""
    tomorrow = dt.date.today() + dt.timedelta(days=1)
    try:
        return tomorrow.replace(year=tomorrow.year - 18)
    except ValueError:  # 29 février
        return dt.date(tomorrow.year - 18, 3, 1)


def post(client, data, headers=None):
    body = json.dumps(data).encode() if not isinstance(data, bytes) else data
    headers = headers or signed("POST", "/v1/analyze", body)
    return client.post("/v1/analyze", content=body, headers=headers), headers


def test_unsigned_or_forged_requests_are_rejected(client):
    for headers in ({}, {**signed("POST", "/v1/analyze", b"{}"), "X-VeriAge-Signature": "v1=" + "0" * 64}):
        response = client.post("/v1/analyze", content=b"{}", headers=headers)
        assert response.status_code == 401 and response.json() == {"error": "unauthorized"}
    assert client.get("/v1/health").status_code == 401


def test_health_is_signed_both_ways(client):
    headers = signed("GET", "/v1/health")
    response = client.get("/v1/health", headers=headers)
    assert response.status_code == 200
    assert response.json()["landmarks"] == "mediapipe" and response.json()["tesseract"] is True
    check_response_signature(response, headers["X-VeriAge-Nonce"])


def test_replayed_request_is_rejected(client):
    body = b"{}"
    headers = signed("POST", "/v1/analyze", body)
    assert client.post("/v1/analyze", content=body, headers=headers).status_code == 422
    assert client.post("/v1/analyze", content=body, headers=headers).status_code == 401


def test_limits_and_errors_never_echo_the_input(client):
    too_big = b"{" + b" " * (8 * 1024 * 1024) + b"}"
    assert client.post("/v1/analyze", content=too_big, headers=signed("POST", "/v1/analyze", too_big)).status_code == 413
    response, _ = post(client, b"not json")
    assert response.status_code == 400 and response.json() == {"error": "invalid_json"}
    secret_value = "SPECIMEN<<ANNA"
    response, _ = post(client, {"reference_date": secret_value})
    assert response.status_code == 422 and secret_value not in response.text
    response, _ = post(client, {"reference_date": dt.date.today().isoformat(), "document": {"type": "passport", "front": "@@"}, "selfie": {"challenge": ["blink"], "frames": []}})
    assert response.json() == {"error": "image_invalid"}
    response, _ = post(client, {"reference_date": "2001-01-01", "document": {}, "selfie": {}})
    assert response.json() == {"error": "reference_date_invalid"}  # horloge de PHP incohérente


def test_full_analysis_returns_only_the_contract(client, payload, caplog):
    caplog.set_level(logging.DEBUG)
    response, headers = post(client, payload)
    assert response.status_code == 200, response.text
    check_response_signature(response, headers["X-VeriAge-Nonce"])
    body = response.json()
    assert tuple(body) == RESPONSE_KEYS
    assert body["mrz_valid"] is True and body["age"] == 17 and body["doc_expired"] is False  # 18 ans demain
    assert body["liveness_passed"] is True and body["reasons"] == []
    assert body["face_match_score"] > 0.6
    # Aucune donnée d'identité dans la réponse ni dans les journaux.
    birth = eighteen_tomorrow()
    for forbidden in ("SPECIMEN", "ANNA", "AB1234567", birth.strftime("%y%m%d"), birth.isoformat(), str(birth.year), "310520"):
        assert forbidden not in response.text
        assert forbidden not in caplog.text


def test_wrong_challenge_order_and_other_face(client, payload, assets):
    reordered = {**payload, "selfie": {**payload["selfie"], "challenge": ["turn_right", "blink", "open_mouth", "turn_left"]}}
    body = post(client, reordered)[0].json()
    assert body["liveness_passed"] is False and "liveness_challenge_failed" in body["reasons"]
    # Carte cohérente d'une autre personne (biden) : liée, mais le visage ne correspond pas.
    front, back = synth.identity_card(synth.load_asset("biden.jpg"), "AB1234567", eighteen_tomorrow(), dt.date(2031, 5, 20))
    swapped = {**payload, "document": {"type": "id_card", "front": base64.b64encode(synth.jpeg(front)).decode(),
                                       "back": base64.b64encode(synth.jpeg(back)).decode()}}
    body = post(client, swapped)[0].json()
    assert body["reasons"] == [] and body["face_match_score"] < 0.363 and body["liveness_passed"] is True


def test_expired_document_and_passport_without_back(client, payload, assets):
    page = synth.jpeg(synth.passport(synth.load_asset("obama2.jpg"), "L898902C3", dt.date(1990, 1, 1), dt.date(2020, 1, 1)))
    passport = {**payload, "document": {"type": "passport", "front": base64.b64encode(page).decode(), "back": None}}
    body = post(client, passport)[0].json()
    assert body["mrz_valid"] is True and body["doc_expired"] is True and "document_expired" in body["reasons"]
    assert body["face_match_score"] > 0.6  # portrait lu sur la page du passeport


def test_response_body_hash_matches_signature_base():
    body = b'{"a":1}'
    assert hashlib.sha256(body).hexdigest() in response_base("n" * 16, 200, body).decode()


# -- Contrôle : pannes internes, charge, journaux ---------------------------------------------------

FAKE_RESULT = {"age": 30, "doc_expired": False, "face_match_score": 0.9, "liveness_passed": True, "mrz_valid": True, "reasons": []}


def test_internal_error_is_signed_with_the_nonce_and_never_echoes_data(services, monkeypatch, caplog):
    """Une exception imprévue : réponse 500 signée avec le nonce (PHP y voit une panne, pas une réponse
    forgée), aucune pile ni message d'exception dans les journaux (ils peuvent recopier une valeur lue)."""
    from veriage_biometrics import analysis

    def boom(payload, svc):
        raise ValueError("SPECIMEN<<ANNA<<<<740812")

    monkeypatch.setattr(analysis, "analyze", boom)
    caplog.set_level(logging.DEBUG)
    with TestClient(create_app(Settings(secret=SECRET.encode()), services), raise_server_exceptions=True) as c:
        response, headers = post(c, {"x": 1})
    assert response.status_code == 500 and response.json() == {"error": "internal_error"}
    check_response_signature(response, headers["X-VeriAge-Nonce"])
    assert "SPECIMEN" not in caplog.text and "740812" not in caplog.text and "Traceback" not in caplog.text
    assert "ValueError" in caplog.text


def test_busy_service_waits_briefly_then_answers_503(services, monkeypatch):
    import threading

    from veriage_biometrics import analysis

    release = threading.Event()

    def slow(payload, svc):
        release.wait(5)
        return FAKE_RESULT

    monkeypatch.setattr(analysis, "analyze", slow)
    settings = Settings(secret=SECRET.encode(), concurrency=1, queue_wait=0.2)
    with TestClient(create_app(settings, services)) as c:
        first = {}
        worker = threading.Thread(target=lambda: first.update(response=post(c, {"x": 1})[0]))
        worker.start()
        time.sleep(0.3)  # la première analyse occupe la seule place
        response, headers = post(c, {"x": 2})
        assert response.status_code == 503 and response.json() == {"error": "busy"}
        check_response_signature(response, headers["X-VeriAge-Nonce"])
        release.set()
        worker.join(5)
    assert first["response"].status_code == 200


def test_log_filter_drops_tracebacks_and_exception_messages():
    from veriage_biometrics.api import NoTraceback

    try:
        raise ValueError("P<UTOERIKSSON<<ANNA")
    except ValueError:
        import sys
        record = logging.LogRecord("uvicorn.error", logging.ERROR, __file__, 1, "Exception in ASGI application\n", None, sys.exc_info())
    assert NoTraceback().filter(record) is True
    text = logging.Formatter("%(message)s").format(record)
    assert text == "Exception in ASGI application (ValueError)" and "ERIKSSON" not in text


def test_mrz_reading_stops_at_the_deadline(mrz_reader):
    import numpy as np
    started = time.monotonic()
    reading = mrz_reader.read(np.full((600, 900, 3), 255, np.uint8), dt.date.today(), deadline=time.monotonic())
    assert reading.data is None and reading.reason == "mrz_not_found"
    assert time.monotonic() - started < 1.0  # aucun appel à Tesseract
