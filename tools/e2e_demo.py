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
        time.sleep(2)
        check("popup : reste ouvert après le résultat (le temps de le lire)", not popup.is_closed())
        check("popup : invitation à fermer la fenêtre", popup.query_selector("[data-can-close]") is not None)
        shot(popup, out, "popup-resultat-echec-fr")
        popup.click(".actions-stack [data-veriage-close]")
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
        shot(page, out, "demo-7-retour-jeton-verifie-fr")
        page.reload(wait_until="networkidle")
        check("retour : jeton rejoué refusé (jti consommé une fois)", page.get_attribute("[data-return-problem]", "data-return-problem") == "token_replayed")
        shot(page, out, "demo-7b-retour-jeton-rejoue-fr")
        stranger = context(browser)
        other = stranger.new_page()
        other.goto(page.url, wait_until="networkidle")
        check("retour : session d'un autre visiteur refusée", other.get_attribute("[data-return-problem]", "data-return-problem") == "owner_mismatch")
        stranger.close()
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

        # 7. Accessibilité clavier de la modale (WCAG 2.4.3 / motif « dialog » de l'APG).
        print("Accessibilité : modale au clavier")
        ctx = context(browser)
        page = ctx.new_page()
        create_session(page, args.demo, "fr", "modal", f"kim+{stamp}@example.com")
        # État d'origine de la page cliente, qui doit être restauré tel quel à la fermeture : un élément
        # déjà inerte, un autre explicitement « aria-hidden=false », un style de défilement propre.
        page.evaluate("""() => {
            const a = document.createElement('div'); a.id = 'pre-inert'; a.inert = true; document.body.appendChild(a);
            const b = document.createElement('div'); b.id = 'pre-visible'; b.setAttribute('aria-hidden', 'false'); document.body.appendChild(b);
            document.documentElement.style.overflow = 'scroll';
        }""")
        page.focus("#demo-open")
        page.keyboard.press("Enter")
        frame = page.wait_for_selector("iframe[src*='/s/vs_']").content_frame()
        frame.wait_for_selector("input[name=consent]")
        check("modale : role=dialog, aria-modal, nom accessible",
              page.eval_on_selector("[role=dialog]", "d => d.getAttribute('aria-modal') === 'true' && d.getAttribute('aria-label') !== ''"))
        check("modale : reste de la page inerte", page.eval_on_selector(".shop-main", "m => m.inert === true && m.getAttribute('aria-hidden') === 'true'"))
        inside = []
        for _ in range(30):
            page.keyboard.press("Tab")
            inside.append(page.evaluate("() => { const d = document.querySelector('[role=dialog]'); return !!d && d.contains(document.activeElement); }"))
        check("modale : 30 tabulations, le focus ne quitte jamais la modale", all(inside), str(inside))
        for _ in range(12):
            page.keyboard.press("Shift+Tab")
        check("modale : Maj+Tab reste aussi dans la modale", page.evaluate("() => document.querySelector('[role=dialog]').contains(document.activeElement)"))
        frame.focus("input[name=consent]")
        page.keyboard.press("Escape")
        page.wait_for_selector("#log-events li[data-event='veriage:closed']", timeout=5000)
        check("modale : Échap dans la page hébergée ferme la modale", page.query_selector("[role=dialog]") is None)
        check("modale : focus rendu au bouton d'ouverture", page.evaluate("() => document.activeElement && document.activeElement.id === 'demo-open'"))
        check("modale : page de nouveau active", page.eval_on_selector(".shop-main", "m => !m.inert && !m.hasAttribute('aria-hidden')"))
        check("modale : état d'origine de la page cliente restauré tel quel", page.evaluate("""() => {
            const a = document.getElementById('pre-inert'), b = document.getElementById('pre-visible');
            return a.inert === true && !a.hasAttribute('aria-hidden') && !b.inert && b.getAttribute('aria-hidden') === 'false'
                && document.documentElement.style.overflow === 'scroll';
        }"""))
        ctx.close()

        # 8. Mode iframe sur mobile : plein écran, un seul bouton de fermeture.
        print("Mobile : mode iframe en plein écran")
        ctx = context(browser, "mobile")
        page = ctx.new_page()
        create_session(page, args.demo, "fr", "iframe", f"lea+{stamp}@example.com")
        page.click("#demo-open")
        frame_el = page.wait_for_selector("iframe[src*='/s/vs_']")
        check("mobile iframe : présentée comme une superposition (embed=modal)", "embed=modal" in frame_el.get_attribute("src"))
        frame = frame_el.content_frame()
        frame.wait_for_selector("input[name=consent]")
        widget_close = page.eval_on_selector_all("[role=dialog] button", "bs => bs.filter(b => b.offsetParent !== null).length")
        page_close = frame.eval_on_selector_all(".verify-header [data-veriage-close]", "bs => bs.filter(b => b.offsetParent !== null).length")
        check("mobile iframe : un seul bouton de fermeture visible", widget_close + page_close == 1, f"widget={widget_close} page={page_close}")
        shot(page, out, "demo-10-iframe-plein-ecran-mobile-fr", full_page=False)
        ctx.close()

        # 9. Sécurité : la page hébergée refuse d'être cadrée par un domaine non autorisé (CSP).
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
