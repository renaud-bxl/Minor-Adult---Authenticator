"""Captures d'écran de contrôle visuel (outil de développement, Python + Playwright).

Usage : python3 tools/screenshots.py --base http://127.0.0.1:8000 --out docs/screenshots/phase-1
Variable CHROMIUM_PATH : exécutable Chromium à utiliser (sinon celui géré par Playwright).
Le parcours « tableau de bord » crée un compte de démonstration : serveur local avec MAIL_DRIVER=log
et MAIL_OUTBOX=storage/mail (les liens de validation sont lus dans la boîte d'envoi).
"""
import argparse
import glob
import os
import pathlib
import quopri
import re
import time

from playwright.sync_api import sync_playwright

PAGES = {"accueil": "/{lang}/", "inscription": "/{lang}/register", "connexion": "/{lang}/login"}
EXTRA_PAGES = {"mot-de-passe-oublie": "/{lang}/forgot-password", "erreur-404": "/{lang}/page-inexistante"}
VIEWPORTS = {"desktop": {"width": 1366, "height": 900}, "mobile": {"width": 390, "height": 844}}
THEMES = ("light", "dark")
DEMO_PASSWORD = "quiet river stones at dawn"


def new_context(browser, device, lang, theme):
    return browser.new_context(
        viewport=VIEWPORTS[device],
        locale=lang,
        color_scheme=theme,
        device_scale_factor=2 if device == "mobile" else 1,
        is_mobile=device == "mobile",
        has_touch=device == "mobile",
    )


def shot(page, out, name):
    target = out / f"{name}.png"
    page.screenshot(path=str(target), full_page=True)
    print(target)


def latest_verify_link(outbox, since):
    for _ in range(50):
        files = sorted(f for f in glob.glob(str(outbox / "*.eml")) if os.path.getmtime(f) >= since)
        for path in reversed(files):
            text = quopri.decodestring(pathlib.Path(path).read_bytes()).decode("utf-8", "replace")
            match = re.search(r"https?://[^\s\"<]+/verify-email\?token=[A-Za-z0-9_-]{43}", text)
            if match:
                return match.group(0)
        time.sleep(0.2)
    raise RuntimeError("lien de validation introuvable dans la boîte d'envoi")


def main() -> None:
    parser = argparse.ArgumentParser()
    parser.add_argument("--base", default="http://127.0.0.1:8000")
    parser.add_argument("--out", default="docs/screenshots/phase-1")
    parser.add_argument("--outbox", default="storage/mail")
    parser.add_argument("--langs", default="fr,en")
    args = parser.parse_args()

    out = pathlib.Path(args.out)
    out.mkdir(parents=True, exist_ok=True)
    launch = {"executable_path": os.environ["CHROMIUM_PATH"]} if os.environ.get("CHROMIUM_PATH") else {}
    langs = args.langs.split(",")

    with sync_playwright() as p:
        browser = p.chromium.launch(**launch)

        # 1. Pages principales : langues × appareils × thèmes.
        for theme in THEMES:
            for device in VIEWPORTS:
                for lang in langs:
                    context = new_context(browser, device, lang, theme)
                    page = context.new_page()
                    for name, path in PAGES.items():
                        page.goto(args.base + path.format(lang=lang), wait_until="networkidle")
                        suffix = "" if theme == "light" else "-sombre"
                        shot(page, out, f"{name}-{lang}-{device}{suffix}")
                    context.close()

        # 2. États et pages complémentaires (desktop, deux thèmes).
        for theme in THEMES:
            suffix = "" if theme == "light" else "-sombre"
            for lang in langs:
                context = new_context(browser, "desktop", lang, theme)
                page = context.new_page()

                # Focus clavier sur le champ e-mail (anneau de focus visible).
                page.goto(f"{args.base}/{lang}/login", wait_until="networkidle")
                page.locator("#email").focus()
                page.keyboard.press("Shift+Tab")
                page.keyboard.press("Tab")
                shot(page, out, f"etat-focus-{lang}{suffix}")

                # Erreurs de formulaire (validation serveur, 422).
                page.goto(f"{args.base}/{lang}/register", wait_until="networkidle")
                page.fill("#company", "A")
                page.fill("#email", "pas-une-adresse")
                page.fill("#password", "password1234")
                page.fill("#password_confirmation", "password1234")
                page.click("button[type=submit].button-block")
                page.wait_for_load_state("networkidle")
                shot(page, out, f"etat-erreur-inscription-{lang}{suffix}")

                for name, path in EXTRA_PAGES.items():
                    page.goto(args.base + path.format(lang=lang), wait_until="networkidle")
                    shot(page, out, f"{name}-{lang}{suffix}")
                context.close()

        # 3. Tableau de bord : compte de démonstration créé, validé puis connecté.
        email = f"demo+{int(time.time())}@example.test"
        since = time.time() - 1
        context = new_context(browser, "desktop", "fr", "light")
        page = context.new_page()
        page.goto(f"{args.base}/fr/register", wait_until="networkidle")
        page.fill("#company", "Brasserie Démo SRL")
        page.fill("#email", email)
        page.fill("#password", DEMO_PASSWORD)
        page.fill("#password_confirmation", DEMO_PASSWORD)
        page.click("button[type=submit].button-block")
        page.wait_for_load_state("networkidle")
        link = latest_verify_link(pathlib.Path(args.outbox), since)
        page.goto(re.sub(r"^https?://[^/]+", args.base, link), wait_until="networkidle")
        page.fill("#email", email)
        page.fill("#password", DEMO_PASSWORD)
        page.click("button[type=submit].button-block")
        page.wait_for_load_state("networkidle")
        shot(page, out, "tableau-de-bord-fr")
        page.emulate_media(color_scheme="dark")
        shot(page, out, "tableau-de-bord-fr-sombre")
        page.goto(f"{args.base}/en/dashboard?lang=en", wait_until="networkidle")
        shot(page, out, "tableau-de-bord-en")
        context.close()

        browser.close()


if __name__ == "__main__":
    main()
