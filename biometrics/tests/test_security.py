"""Authentification HMAC PHP ↔ Python : signature, fenêtre d'horodatage, anti-rejeu."""
import pytest

from veriage_biometrics.security import AuthError, RequestVerifier, request_base, response_base, sign

SECRET = b"s" * 40


def headers(ts, nonce, body=b"{}", path="/v1/analyze", secret=SECRET):
    return {"x-veriage-timestamp": str(ts), "x-veriage-nonce": nonce,
            "x-veriage-signature": sign(secret, request_base(str(ts), nonce, "POST", path, body))}


def verifier(now=1_000_000):
    return RequestVerifier(SECRET, tolerance=30, clock=lambda: now)


def test_valid_request_returns_nonce():
    assert verifier().verify("POST", "/v1/analyze", headers(1_000_000, "n" * 22), b"{}") == "n" * 22


@pytest.mark.parametrize("mutate", [
    lambda h: {**h, "x-veriage-signature": "v1=" + "0" * 64},
    lambda h: {**h, "x-veriage-timestamp": "999960"},  # hors fenêtre de 30 s
    lambda h: {**h, "x-veriage-nonce": "court"},
    lambda h: {k: v for k, v in h.items() if k != "x-veriage-signature"},
    lambda h: {**h, "x-veriage-signature": h["x-veriage-signature"].upper()},
])
def test_rejected_requests(mutate):
    with pytest.raises(AuthError):
        verifier().verify("POST", "/v1/analyze", mutate(headers(1_000_000, "n" * 22)), b"{}")


def test_body_path_and_secret_are_bound_to_the_signature():
    h = headers(1_000_000, "n" * 22)
    for method, path, body in (("POST", "/v1/analyze", b'{"x":1}'), ("POST", "/v1/health", b"{}"), ("GET", "/v1/analyze", b"{}")):
        with pytest.raises(AuthError):
            verifier().verify(method, path, h, body)
    with pytest.raises(AuthError):
        verifier().verify("POST", "/v1/analyze", headers(1_000_000, "n" * 22, secret=b"x" * 40), b"{}")


def test_replayed_nonce_is_rejected():
    v = verifier()
    h = headers(1_000_000, "abcdefghijklmnop")
    v.verify("POST", "/v1/analyze", h, b"{}")
    with pytest.raises(AuthError, match="replay"):
        v.verify("POST", "/v1/analyze", h, b"{}")


def test_response_signature_binds_nonce_status_and_body():
    base = response_base("abcdefghijklmnop", 200, b'{"a":1}')
    assert sign(SECRET, base) != sign(SECRET, response_base("abcdefghijklmnop", 200, b'{"a":2}'))
    assert sign(SECRET, base) != sign(SECRET, response_base("abcdefghijklmnoq", 200, b'{"a":1}'))
    assert sign(SECRET, base) != sign(SECRET, response_base("abcdefghijklmnop", 422, b'{"a":1}'))
