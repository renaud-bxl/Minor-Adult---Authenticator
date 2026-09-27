"""API HTTP locale (FastAPI), à l'écoute de 127.0.0.1 uniquement, appelée par PHP.

POST /v1/analyze  demande signée (HMAC) ; corps JSON (images en base64) lu en mémoire, borné.
GET  /v1/health   demande signée ; état des modèles (aucun secret).

Toutes les réponses sont signées. Aucun corps de requête n'est journalisé ; les erreurs ne renvoient
qu'un code stable, jamais la donnée reçue (pas de validation Pydantic, dont les erreurs recopient
l'entrée). Documentation interactive (/docs, /openapi.json) désactivée.
"""
from __future__ import annotations

import json
import logging
import threading
import time

from fastapi import FastAPI, Request
from fastapi.responses import Response
from starlette.concurrency import run_in_threadpool
from starlette.exceptions import HTTPException as StarletteHTTPException

from . import analysis
from .config import Settings
from .security import AuthError, RequestVerifier, response_base, sign

LOG = logging.getLogger("veriage.biometrics")


class BodyTooLarge(Exception):
    pass


async def read_body(request: Request, limit: int) -> bytes:
    declared = request.headers.get("content-length")
    if declared is not None and (not declared.isdigit() or int(declared) > limit):
        raise BodyTooLarge()
    chunks, size = [], 0
    async for chunk in request.stream():
        size += len(chunk)
        if size > limit:
            raise BodyTooLarge()
        chunks.append(chunk)
    return b"".join(chunks)


def create_app(settings: Settings, services: analysis.Services, verifier: RequestVerifier | None = None) -> FastAPI:
    app = FastAPI(docs_url=None, redoc_url=None, openapi_url=None)
    verifier = verifier or RequestVerifier(settings.secret, settings.signature_tolerance)
    slots = threading.BoundedSemaphore(settings.concurrency)

    def respond(status: int, payload: dict, nonce: str = "") -> Response:
        body = json.dumps(payload, separators=(",", ":")).encode()
        return Response(body, status_code=status, media_type="application/json", headers={
            "X-VeriAge-Signature": sign(settings.secret, response_base(nonce, status, body)),
            "Cache-Control": "no-store",
        })

    def authenticate(request: Request, body: bytes) -> str:
        headers = {k.lower(): v for k, v in request.headers.items()}
        return verifier.verify(request.method, request.url.path, headers, body)

    @app.exception_handler(StarletteHTTPException)
    async def http_error(_request: Request, exc: StarletteHTTPException) -> Response:
        return respond(exc.status_code, {"error": "not_found" if exc.status_code == 404 else "http_error"})

    @app.exception_handler(Exception)
    async def unexpected(_request: Request, exc: Exception) -> Response:
        LOG.error("erreur interne : %s", type(exc).__name__)
        return respond(500, {"error": "internal_error"})

    @app.get("/v1/health")
    async def health(request: Request) -> Response:
        try:
            nonce = authenticate(request, b"")
        except AuthError:
            return respond(401, {"error": "unauthorized"})
        return respond(200, {
            "status": "ok",
            "mrz_language": services.mrz.language,
            "tesseract": services.mrz.available(),
            "landmarks": "mediapipe" if services.landmarker is not None else "yunet_fallback",
        }, nonce)

    @app.post("/v1/analyze")
    async def analyze(request: Request) -> Response:
        try:
            body = await read_body(request, settings.max_body_bytes)
        except BodyTooLarge:
            return respond(413, {"error": "payload_too_large"})
        try:
            nonce = authenticate(request, body)
        except AuthError:
            return respond(401, {"error": "unauthorized"})
        try:
            payload = json.loads(body)
        except (ValueError, UnicodeDecodeError):
            return respond(400, {"error": "invalid_json"}, nonce)
        del body
        if not slots.acquire(blocking=False):
            return respond(503, {"error": "busy"}, nonce)
        started = time.monotonic()
        try:
            result = await run_in_threadpool(analysis.analyze, payload, services)
        except analysis.RequestError as exc:
            return respond(422, {"error": exc.code}, nonce)
        finally:
            slots.release()
            del payload
        # Journal : durée et motifs (codes) seulement ; ni âge, ni score, ni donnée reçue.
        LOG.info("analyse terminée en %d ms, motifs=%s", (time.monotonic() - started) * 1000, ",".join(result["reasons"]) or "-")
        return respond(200, {key: result[key] for key in analysis.RESPONSE_KEYS}, nonce)

    return app
