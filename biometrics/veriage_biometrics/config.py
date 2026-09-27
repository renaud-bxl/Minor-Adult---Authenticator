"""Configuration du service, lue dans l'environnement (fichier EnvironmentFile de systemd, mode 0600).

BIOMETRICS_SECRET     secret partagé avec PHP (HMAC des requêtes et des réponses), 32 caractères au moins
BIOMETRICS_HOST       adresse d'écoute : boucle locale UNIQUEMENT (127.0.0.1 ou ::1), refus sinon
BIOMETRICS_PORT       port d'écoute (défaut 8765)
BIOMETRICS_MODEL_DIR  dossier des modèles (défaut : biometrics/models, rempli par scripts/fetch_models.sh)
BIOMETRICS_MAX_BODY   taille maximale d'une requête en octets (défaut 16 Mio)
BIOMETRICS_CONCURRENCY analyses simultanées (défaut 2 ; au-delà : attente de 10 s au plus, puis 503 ;
                       PHP laisse alors la session ouverte et la page propose de recommencer)
"""
from __future__ import annotations

import ipaddress
import os
from dataclasses import dataclass
from pathlib import Path

PACKAGE_ROOT = Path(__file__).resolve().parent.parent
MIN_SECRET_LENGTH = 32


class ConfigError(RuntimeError):
    pass


@dataclass(frozen=True)
class Settings:
    secret: bytes
    host: str = "127.0.0.1"
    port: int = 8765
    model_dir: Path = PACKAGE_ROOT / "models"
    max_body_bytes: int = 16 * 1024 * 1024
    concurrency: int = 2
    # Attente maximale d'une place libre (pic de charge) avant de répondre 503 « busy » (secondes).
    queue_wait: float = 10.0
    # Fenêtre d'horodatage des requêtes signées (anti-rejeu, secondes).
    signature_tolerance: int = 30

    @property
    def tessdata_dir(self) -> Path:
        return self.model_dir / "tessdata"

    @classmethod
    def from_env(cls, env: dict[str, str] | None = None) -> "Settings":
        env = dict(os.environ if env is None else env)
        secret = env.get("BIOMETRICS_SECRET", "")
        if len(secret) < MIN_SECRET_LENGTH:
            raise ConfigError(f"BIOMETRICS_SECRET doit contenir au moins {MIN_SECRET_LENGTH} caractères")
        host = env.get("BIOMETRICS_HOST", "127.0.0.1")
        try:
            loopback = ipaddress.ip_address(host).is_loopback
        except ValueError:
            loopback = False
        if not loopback:
            raise ConfigError("BIOMETRICS_HOST doit être une adresse de boucle locale (127.0.0.1 ou ::1)")
        try:
            port = int(env.get("BIOMETRICS_PORT", "8765"))
            max_body = int(env.get("BIOMETRICS_MAX_BODY", str(16 * 1024 * 1024)))
            concurrency = int(env.get("BIOMETRICS_CONCURRENCY", "2"))
        except ValueError:
            raise ConfigError("BIOMETRICS_PORT, BIOMETRICS_MAX_BODY et BIOMETRICS_CONCURRENCY doivent être des entiers") from None
        if not 1024 <= port <= 65535 or not 1 <= concurrency <= 16 or not 1024 * 1024 <= max_body <= 64 * 1024 * 1024:
            raise ConfigError("BIOMETRICS_PORT (1024-65535), BIOMETRICS_CONCURRENCY (1-16) ou BIOMETRICS_MAX_BODY (1-64 Mio) hors limites")
        model_dir = Path(env.get("BIOMETRICS_MODEL_DIR", str(PACKAGE_ROOT / "models")))
        return cls(secret=secret.encode("utf-8"), host=host, port=port, model_dir=model_dir,
                   max_body_bytes=max_body, concurrency=concurrency)
