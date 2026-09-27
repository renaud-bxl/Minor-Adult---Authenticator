"""Tests E2E de la démonstration (phase 2) : parcours sandbox complet dans un vrai navigateur, avec captures.

Prérequis (voir README, « Démarrage rapide de la démo ») : module sur --verify (php -S 127.0.0.1:8000),
démo sur --demo (php -S 127.0.0.1:8001), worker (php bin/worker.php) et projet de démo configuré
(php bin/project.php demo >> .env).

Usage : python3 tools/e2e_demo.py [--verify http://127.0.0.1:8000] [--demo http://127.0.0.1:8001]
                                  [--out docs/screenshots/phase-2]
Variable CHROMIUM_PATH : exécutable Chromium (sinon celui géré par Playwright).

Chaque scénario vérifie : événements du widget (opened, completed/failed, closed), statut confirmé
par l'API (serveur de la boutique) et webhook signé reçu (signature vérifiée par la boutique).
Code retour ≠ 0 au premier échec.
"""
import argparse
import json
import os
import pathlib
import sys
import time

from playwright.sync_api import sync_playwright

VIEWPORTS = {"desktop": {"width": 1366, "height": 900}, "mobile": {"width": 390, "height": 844}}
RESULTS = []


def context(browser, device="desktop", lang="fr", theme="light"):
    return browser.new_context(
        viewport=VIEWPORTS[device],
        locale=lang,
        color_scheme=theme,
        device_scale_factor=2 if device == "mobile" else 1,
        is_mobile=device == "mobile",
        has_touch=device == "mobile",
    )


def shot(target, out, name, full_page=True):
    path = out / f"{name}.png"
    target.screenshot(path=str(path), full_page=full_page)
    print("  capture", path)


def check(name, condition, detail=""):
    RESULTS.append((name, bool(condition)))
    print(("  OK   " if condition else "  ÉCHEC ") + name + (f" ({detail})" if detail and not condition else ""))
    if not condition:
        raise AssertionError(name)


def create_session(page, demo, lang, mode, email, min_age="18"):
    page.goto(f"{demo}/demo?lang={lang}", wait_until="networkidle")
    page.fill("#demo-email", email)
    page.select_option("#demo-age", min_age)
    page.check(f"input[name=mode][value={mode}]")
    page.click("#demo-create")
    page.wait_for_selector("#log-api[data-session]", timeout=10000)
    return page.get_attribute("#log-api", "data-session")


def run_hosted_flow(frame, outcome, out=None, prefix=None, share=False):
    """Parcours de la page hébergée (frame = page, iframe ou popup)."""
    frame.check("input[name=consent]")
    if out and prefix:
        shot(frame, out, f"{prefix}-1-consentement")
    frame.click("button[type=submit]")
    frame.wait_for_selector("[data-sandbox-code]")
    code = frame.inner_text("[data-sandbox-code]").strip()
    frame.fill("#code", code)
    if out and prefix:
        shot(frame, out, f"{prefix}-2-code")
    frame.click("form[action*='/code?'] button[type=submit]")
    frame.wait_for_selector(f"input[name=outcome][value={outcome}]")
    frame.check(f"input[name=outcome][value={outcome}]")
    if share:
        frame.check("input[name=share]")
    if out and prefix:
        shot(frame, out, f"{prefix}-3-methode")
    frame.click("form.method-card button[type=submit]")
    frame.wait_for_selector("[data-result-status]")
    status = frame.get_attribute("[data-result-status]", "data-result-status")
    if out and prefix:
        shot(frame, out, f"{prefix}-4-resultat")
    return status


def wait_confirmation(page, expected_status, expected_adult):
    page.wait_for_selector("#log-webhooks[data-received]", timeout=30000)
    page.wait_for_selector("#log-confirm[data-status]:not([data-status=pending])", timeout=30000)
    api = json.loads(page.inner_text("#log-confirm"))
    hooks = json.loads(page.inner_text("#log-webhooks"))
    check(f"API confirme le statut « {expected_status} »", api.get("status") == expected_status, json.dumps(api))
    if expected_adult is not None:
        check(f"API : is_adult = {expected_adult}", api.get("is_adult") is expected_adult, json.dumps(api))
    check("webhook signé reçu (signature valide)", hooks and hooks[0].get("signature") == "valid", json.dumps(hooks))
    return api, hooks


def events(page):
    return page.eval_on_selector_all("#log-events li", "items => items.map(i => i.getAttribute('data-event'))")


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--verify", default="http://127.0.0.1:8000")
    parser.add_argument("--demo", default="http://127.0.0.1:8001")
    parser.add_argument("--out", default="docs/screenshots/phase-2")
    args = parser.parse_args()
    out = pathlib.Path(args.out)
    out.mkdir(parents=True, exist_ok=True)
    launch = {"executable_path": os.environ["CHROMIUM_PATH"]} if os.environ.get("CHROMIUM_PATH") else {}
    stamp = int(time.time())

    with sync_playwright() as p:
        browser = p.chromium.launch(**launch)

        # 1. Mode modale : majeur, captures de chaque étape (FR, desktop).
        print("Scénario 1 : modale, majeur (FR)")
        ctx = context(browser)
        page = ctx.new_page()
        create_session(page, args.demo, "fr", "modal", f"alice+{stamp}@example.com")
        shot(page, out, "demo-1-session-creee-fr")
        page.click("#demo-open")
        frame_el = page.wait_for_selector("iframe[src*='/s/vs_']")
        check("iframe avec allow=\"camera; fullscreen\"", frame_el.get_attribute("allow") == "camera; fullscreen")
        frame = frame_el.content_frame()
        frame.wait_for_selector("input[name=consent]")
        shot(page, out, "demo-2-modale-consentement-fr", full_page=False)
        status = run_hosted_flow(frame, "adult")
        check("page hébergée : statut verified", status == "verified")
        page.wait_for_selector("#log-events li[data-event='veriage:completed']")
        shot(page, out, "demo-3-modale-resultat-fr", full_page=False)
        frame.click(".actions-stack [data-veriage-close]")  # « Fermer » dans la page : message « close »
        wait_confirmation(page, "verified", True)
        check("événements opened → completed → closed", events(page) == ["veriage:opened", "veriage:completed", "veriage:closed"], str(events(page)))
        shot(page, out, "demo-4-coulisses-confirmation-fr")
        ctx.close()

        # 2. Mode iframe intégrée : mineur (EN), captures des étapes de la page hébergée.
        print("Scénario 2 : iframe intégrée, mineur (EN)")
        ctx = context(browser, lang="en")
        page = ctx.new_page()
        create_session(page, args.demo, "en", "iframe", f"bob+{stamp}@example.com")
        page.click("#demo-open")
        frame = page.wait_for_selector("#demo-frame iframe").content_frame()
        frame.wait_for_selector("input[name=consent]")
        status = run_hosted_flow(frame, "minor")
        check("iframe : statut verified (mineur)", status == "verified")
        wait_confirmation(page, "verified", False)
        shot(page, out, "demo-5-iframe-mineur-en")
        ctx.close()

        # 3. Mode popup : échec simulé (FR).
        print("Scénario 3 : popup, échec simulé (FR)")
        ctx = context(browser)
        page = ctx.new_page()
        create_session(page, args.demo, "fr", "popup", f"carol+{stamp}@example.com")
        with page.expect_popup() as popup_info:
            page.click("#demo-open")
        popup = popup_info.value
        popup.wait_for_selector("input[name=consent]")
        status = run_hosted_flow(popup, "fail")
        check("popup : statut failed", status == "failed")
        page.wait_for_selector("#log-events li[data-event='veriage:failed']")
        wait_confirmation(page, "failed", False)
        page.wait_for_selector("#log-events li[data-event='veriage:closed']", timeout=10000)
        shot(page, out, "demo-6-popup-echec-fr")
        ctx.close()

        # 4. Mode redirection : majeur, retour sur return_url avec jeton JWT vérifié par la boutique.
        print("Scénario 4 : redirection, majeur, retour avec jeton (FR)")
        ctx = context(browser)
        page = ctx.new_page()
        create_session(page, args.demo, "fr", "redirect", f"dave+{stamp}@example.com")
        page.click("#demo-open")
        page.wait_for_url("**/s/vs_*")
        status = run_hosted_flow(page, "adult", out, "page-hebergee-fr-desktop")
        check("redirection : statut verified", status == "verified")
        page.click("[data-veriage-return]")
        page.wait_for_url("**/demo/return?session_id=*")
        check("retour : jeton vérifié par la boutique", page.get_attribute("[data-return-status]", "data-return-status") == "verified")
        time.sleep(2)
        page.reload(wait_until="networkidle")
        shot(page, out, "demo-7-retour-jeton-verifie-fr")
        ctx.close()

        # 5. Réutilisation (même client) : nouvelle session pour l'adresse déjà vérifiée → résultat immédiat.
        print("Scénario 5 : réutilisation pour le même client")
        ctx = context(browser)
        page = ctx.new_page()
        create_session(page, args.demo, "fr", "modal", f"alice+{stamp}@example.com")
        api = json.loads(page.inner_text("#log-api"))
        check("réutilisation : statut verified immédiat, sans nouvelle vérification", api["response"]["status"] == "verified" and api["response"].get("reused") is True, json.dumps(api))
        shot(page, out, "demo-8-reutilisation-immediate-fr")
        ctx.close()

        # 6. Page hébergée : captures FR/EN, mobile, sombre ; widget modale plein écran sur mobile.
        print("Captures : page hébergée (langues, mobile, sombre)")
        for lang, device, theme in [("en", "desktop", "light"), ("fr", "mobile", "light"), ("fr", "desktop", "dark"), ("en", "mobile", "dark")]:
            ctx = context(browser, device, lang, theme)
            page = ctx.new_page()
            create_session(page, args.demo, lang, "redirect", f"erin+{lang}{device}{theme}{stamp}@example.com")
            page.click("#demo-open")
            page.wait_for_url("**/s/vs_*")
            suffix = f"{lang}-{device}" + ("-sombre" if theme == "dark" else "")
            run_hosted_flow(page, "adult", out, f"page-hebergee-{suffix}")
            ctx.close()

        ctx = context(browser, "mobile")
        page = ctx.new_page()
        create_session(page, args.demo, "fr", "modal", f"frank+{stamp}@example.com")
        page.click("#demo-open")
        frame = page.wait_for_selector("iframe[src*='/s/vs_']").content_frame()
        frame.wait_for_selector("input[name=consent]")
        box = page.eval_on_selector("iframe[src*='/s/vs_']", "f => { const r = f.getBoundingClientRect(); return [r.width, r.height]; }")
        check("mobile : modale en plein écran", box[0] >= 389 and box[1] >= 800, str(box))
        shot(page, out, "demo-9-modale-plein-ecran-mobile-fr", full_page=False)
        ctx.close()

        # 7. Sécurité : la page hébergée refuse d'être cadrée par un domaine non autorisé (CSP).
        print("Sécurité : frame-ancestors")
        ctx = context(browser)
        page = ctx.new_page()
        session = create_session(page, args.demo, "fr", "modal", f"gina+{stamp}@example.com")
        response = page.request.get(f"{args.verify}/s/{session}")
        header = response.headers.get("content-security-policy", "")
        check("CSP frame-ancestors limitée aux domaines du projet", f"frame-ancestors 'self' {args.demo}" in header, header)
        check("aucun X-Frame-Options sur la page intégrable", "x-frame-options" not in response.headers)
        ctx.close()

        browser.close()

    failed = [name for name, ok in RESULTS if not ok]
    print(f"\n{len(RESULTS) - len(failed)}/{len(RESULTS)} contrôles réussis.")
    return 1 if failed else 0


if __name__ == "__main__":
    sys.exit(main())
