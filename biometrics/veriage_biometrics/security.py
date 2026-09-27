"""Authentification PHP ↔ Python par secret partagé (HMAC-SHA256), dans les deux sens.

Requête (PHP → Python), en-têtes :
  X-VeriAge-Timestamp  secondes Unix
  X-VeriAge-Nonce      16 à 64 caractères [A-Za-z0-9_-], unique
  X-VeriAge-Signature  v1=<hex HMAC-SHA256(secret, "v1\\n{ts}\\n{nonce}\\n{MÉTHODE}\\n{chemin}\\n{sha256_hex(corps)}")>
Refus si l'horodatage s'écarte de plus de `tolerance` secondes ou si le nonce a déjà servi (anti-rejeu).

Réponse (Python → PHP) : X-VeriAge-Signature: v1=<hex HMAC(secret, "v1\\nresponse\\n{nonce}\\n{statut}\\n{sha256_hex(corps)}")>.
PHP rejette toute réponse non signée : un autre processus du serveur qui écouterait sur le port du
service (service arrêté, serveur partagé) ne peut pas forger un résultat « vivant, visage conforme ».
"""
from __future__ import annotations

import hashlib
import hmac
import re
import threading
import time
from collections import OrderedDict
from typing import Callable, Mapping

NONCE = re.compile(r"^[A-Za-z0-9_-]{16,64}$")
SIGNATURE = re.compile(r"^v1=([0-9a-f]{64})$")


class AuthError(Exception):
    pass


def request_base(timestamp: str, nonce: str, method: str, path: str, body: bytes) -> bytes:
    return "\n".join(["v1", timestamp, nonce, method.upper(), path, hashlib.sha256(body).hexdigest()]).encode()


def response_base(nonce: str, status: int, body: bytes) -> bytes:
    return "\n".join(["v1", "response", nonce, str(status), hashlib.sha256(body).hexdigest()]).encode()


def sign(secret: bytes, base: bytes) -> str:
    return "v1=" + hmac.new(secret, base, hashlib.sha256).hexdigest()


class RequestVerifier:
    def __init__(self, secret: bytes, tolerance: int = 30, clock: Callable[[], float] = time.time,
                 max_nonces: int = 50_000) -> None:
        self._secret = secret
        self._tolerance = tolerance
        self._clock = clock
        self._max = max_nonces
        self._seen: OrderedDict[str, float] = OrderedDict()
        self._lock = threading.Lock()

    def verify(self, method: str, path: str, headers: Mapping[str, str], body: bytes) -> str:
        timestamp = headers.get("x-veriage-timestamp", "")
        nonce = headers.get("x-veriage-nonce", "")
        match = SIGNATURE.match(headers.get("x-veriage-signature", ""))
        if not timestamp.isdigit() or len(timestamp) > 12 or not NONCE.match(nonce) or match is None:
            raise AuthError("malformed")
        now = self._clock()
        if abs(now - int(timestamp)) > self._tolerance:
            raise AuthError("stale")
        expected = sign(self._secret, request_base(timestamp, nonce, method, path, body))
        if not hmac.compare_digest(expected, "v1=" + match.group(1)):
            raise AuthError("signature")
        with self._lock:
            # Oubli des nonces sortis de la fenêtre (ils seraient refusés comme périmés de toute façon).
            while self._seen and next(iter(self._seen.values())) < now - 2 * self._tolerance:
                self._seen.popitem(last=False)
            if nonce in self._seen:
                raise AuthError("replay")
            if len(self._seen) >= self._max:
                self._seen.popitem(last=False)
            self._seen[nonce] = now
        return nonce
