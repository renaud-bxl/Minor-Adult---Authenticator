"""Captures d'écran de contrôle visuel (outil de développement, Python + Playwright).

Usage : python3 tools/screenshots.py --base http://127.0.0.1:8000 --out docs/screenshots/phase-1
Variable CHROMIUM_PATH : exécutable Chromium à utiliser (sinon celui géré par Playwright).
"""
import argparse
import os
import pathlib

from playwright.sync_api import sync_playwright

PAGES = {"accueil": "/{lang}/", "inscription": "/{lang}/register", "connexion": "/{lang}/login"}
VIEWPORTS = {"desktop": {"width": 1366, "height": 900}, "mobile": {"width": 390, "height": 844}}


def main() -> None:
    parser = argparse.ArgumentParser()
    parser.add_argument("--base", default="http://127.0.0.1:8000")
    parser.add_argument("--out", default="docs/screenshots/phase-1")
    parser.add_argument("--langs", default="fr,en")
    args = parser.parse_args()

    out = pathlib.Path(args.out)
    out.mkdir(parents=True, exist_ok=True)
    launch = {"executable_path": os.environ["CHROMIUM_PATH"]} if os.environ.get("CHROMIUM_PATH") else {}

    with sync_playwright() as p:
        browser = p.chromium.launch(**launch)
        for device, viewport in VIEWPORTS.items():
            for lang in args.langs.split(","):
                context = browser.new_context(
                    viewport=viewport,
                    locale=lang,
                    device_scale_factor=2 if device == "mobile" else 1,
                    is_mobile=device == "mobile",
                    has_touch=device == "mobile",
                )
                page = context.new_page()
                errors = []
                page.on("console", lambda msg: errors.append(msg.text) if msg.type == "error" else None)
                for name, path in PAGES.items():
                    response = page.goto(args.base + path.format(lang=lang), wait_until="networkidle")
                    status = response.status if response else 0
                    target = out / f"{name}-{lang}-{device}.png"
                    page.screenshot(path=str(target), full_page=True)
                    print(f"{status} {target}")
                if errors:
                    print("Erreurs console :", errors)
                context.close()
        browser.close()


if __name__ == "__main__":
    main()
