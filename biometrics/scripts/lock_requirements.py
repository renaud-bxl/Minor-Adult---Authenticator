#!/usr/bin/env python3
"""Produit les fichiers de dépendances figées AVEC sommes SHA-256 (pip install --require-hashes).

    .venv/bin/python scripts/lock_requirements.py

Pour chaque fichier *.in : les versions installées dans le venv courant (dépendances transitives
comprises, sauf pour requirements-mediapipe.in, installé sans dépendances) sont figées, et les sommes
de TOUS les fichiers publiés pour ces versions (roues de chaque plateforme, sources) sont lues sur l'API
JSON de PyPI : le même fichier sert au Mac de développement et au serveur Debian.
Outil de développement uniquement (réseau), jamais appelé à l'exécution.
"""
from __future__ import annotations

import json
import re
import sys
import urllib.request
from importlib import metadata
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent


def canonical(name: str) -> str:
    return re.sub(r"[-_.]+", "-", name).lower()


def requirements_of(name: str) -> list[str]:
    deps = []
    for req in metadata.requires(name) or []:
        if ";" in req and "extra ==" in req:
            continue
        if ";" in req:
            marker = req.split(";", 1)[1]
            if "sys_platform == \"win32\"" in marker or "platform_system == \"Windows\"" in marker or "python_version < \"3.11\"" in marker:
                continue
        deps.append(re.split(r"[ <>=!~;\[(]", req, 1)[0])
    return deps


def closure(roots: list[str], exclude: set[str]) -> dict[str, str]:
    pinned: dict[str, str] = {}
    todo = list(roots)
    while todo:
        name = canonical(todo.pop())
        if name in pinned or name in exclude:
            continue
        pinned[name] = metadata.version(name)
        todo.extend(requirements_of(name))
    return pinned


def hashes(name: str, version: str) -> list[str]:
    with urllib.request.urlopen(f"https://pypi.org/pypi/{name}/{version}/json", timeout=30) as response:
        data = json.load(response)
    return sorted({f["digests"]["sha256"] for f in data["urls"]})


def pins(path: Path) -> list[str]:
    return [line.split("==")[0].strip() for line in path.read_text().splitlines() if "==" in line and not line.startswith("#")]


def write(target: str, pinned: dict[str, str], header: str) -> None:
    lines = [header]
    for name in sorted(pinned):
        digests = hashes(name, pinned[name])
        lines.append(f"{name}=={pinned[name]} \\")
        lines.extend(f"    --hash=sha256:{d}" + (" \\" if i < len(digests) - 1 else "") for i, d in enumerate(digests))
    (ROOT / target).write_text("\n".join(lines) + "\n")
    print(f"{target} : {len(pinned)} paquets")


def main() -> int:
    runtime = closure(pins(ROOT / "requirements.in"), exclude={"mediapipe"})
    header = "# Produit par scripts/lock_requirements.py à partir de {src} : ne pas modifier à la main.\n# Installation : .venv/bin/pip install --require-hashes {flags}-r {dst}"
    write("requirements.txt", runtime, header.format(src="requirements.in", flags="", dst="requirements.txt"))
    write("requirements-mediapipe.txt", {"mediapipe": metadata.version("mediapipe")},
          header.format(src="requirements-mediapipe.in", flags="--no-deps ", dst="requirements-mediapipe.txt"))
    dev = closure(pins(ROOT / "requirements-dev.in"), exclude=set(runtime) | {"mediapipe"})
    write("requirements-dev.txt", dev, header.format(src="requirements-dev.in", flags="", dst="requirements-dev.txt"))
    return 0


if __name__ == "__main__":
    sys.exit(main())
