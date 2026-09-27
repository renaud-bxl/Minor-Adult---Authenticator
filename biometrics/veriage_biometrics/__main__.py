"""Point d'entrée : python -m veriage_biometrics (unité systemd veriage-biometrics.service).

Refuse de démarrer si la configuration est invalide (secret trop court, adresse hors boucle locale) ou si
un modèle manque : mieux vaut un service arrêté (PHP échoue proprement) qu'un contrôle dégradé en silence.
MediaPipe seul peut manquer (repli YuNet documenté, sans défi « cligner ») si BIOMETRICS_ALLOW_NO_MEDIAPIPE=1.
"""
from __future__ import annotations

import logging
import os
import sys

import uvicorn

from .analysis import Services
from .api import NoTraceback, create_app
from .config import ConfigError, Settings
from .faces import FaceEngine
from .liveness import Landmarker
from .mrz_ocr import MrzReader


def build_services(settings: Settings) -> Services:
    faces = FaceEngine(settings.model_dir)
    reader = MrzReader(settings.tessdata_dir)
    if not reader.available():
        raise ConfigError("Tesseract introuvable (apt install tesseract-ocr)")
    try:
        landmarker: Landmarker | None = Landmarker(settings.model_dir)
    except (ImportError, OSError, FileNotFoundError, RuntimeError) as exc:
        if os.environ.get("BIOMETRICS_ALLOW_NO_MEDIAPIPE") != "1":
            raise ConfigError(f"MediaPipe indisponible ({type(exc).__name__}) ; voir README") from None
        logging.getLogger("veriage.biometrics").warning("MediaPipe indisponible : repli YuNet (défi « cligner » impossible)")
        landmarker = None
    return Services(faces=faces, mrz=reader, landmarker=landmarker)


def main() -> int:
    logging.basicConfig(level=os.environ.get("BIOMETRICS_LOG_LEVEL", "INFO"), format="%(levelname)s %(name)s %(message)s")
    # Un seul gestionnaire (celui-ci, sur la racine) pour tous les journaux, Uvicorn compris
    # (log_config=None ci-dessous) : aucune pile d'exception n'atteint journald.
    for handler in logging.getLogger().handlers:
        handler.addFilter(NoTraceback())
    try:
        settings = Settings.from_env()
        services = build_services(settings)
    except (ConfigError, FileNotFoundError) as exc:
        print(f"veriage-biometrics : {exc}", file=sys.stderr)
        return 2
    app = create_app(settings, services)
    uvicorn.run(app, host=settings.host, port=settings.port, workers=1, access_log=False, log_level="warning", log_config=None,
                server_header=False, date_header=False, proxy_headers=False, limit_concurrency=32,
                timeout_keep_alive=5)
    if services.landmarker is not None:
        services.landmarker.close()
    return 0


if __name__ == "__main__":
    sys.exit(main())
