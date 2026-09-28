"""Tests E2E de la méthode « pièce d'identité + visage » (phase 3) dans un vrai Chromium, avec captures.

Prérequis : module sur --verify (php -S ; de préférence sur une BASE JETABLE, voir README), microservice
biométrique démarré (python -m veriage_biometrics, BIOMETRICS_* dans .env), clé sandbox (--api-key ou
E2E_API_KEY, sinon DEMO_API_KEY de .env), images de test générées :
    biometrics/.venv/bin/python biometrics/scripts/make_test_images.py --out DIR/set \\
        --camera-frames DIR/poses --doc-poses DIR/doc-poses --y4m DIR/face.y4m --card-video DIR/card.y4m

Usage : python3 tools/e2e_capture.py --assets DIR [--verify URL] [--api-key sk_test_…] [--out docs/screenshots/phase-3]
Variable CHROMIUM_PATH : exécutable Chromium (sinon celui géré par Playwright).

⚠ Ce que ces scénarios démontrent, honnêtement :
- Scénario 1, LIMITE CONNUE (attaque réussie) : aucune personne réelle n'est filmée. Une PHOTO FIXE (une autre
  photo de la titulaire que celle du document), animée en 2D pour suivre les consignes affichées, est injectée
  à la place de la caméra (remplacement de getUserMedia, comme le ferait une caméra virtuelle). Le résultat est
  « verified » : la méthode ne détecte ni l'injection ni l'animation d'une photo. C'est aussi le seul moyen
  d'exercer tout le parcours automatiquement (une vidéo .y4m en boucle ne suit pas des défis tirés au hasard).
- Scénario 2 (attaque C du critique, doit échouer) : le PORTRAIT DU DOCUMENT lui-même, animé de la même façon
  → refusé (« face_identical_to_document » → liveness_failed).
- Scénario 3 (attaque A, doit échouer) : un selfie envoyé à la place du recto → document_inconsistent.
- Scénario 4 : vidéo fixe d'un visage rejouée par la fausse caméra de Chromium (face.y4m) → liveness_failed.
- Scénario 5 : aucune caméra → envoi de fichier proposé pour le document, selfie impossible.
Documents : recto par la fausse caméra de Chromium (card.y4m, carte fictive), verso par fichier.
Code retour ≠ 0 au premier échec.
"""
import argparse
import base64
import json
import os
import pathlib
import sys
import time
import urllib.request

from playwright.sync_api import sync_playwright

ROOT = pathlib.Path(__file__).resolve().parent.parent
VIEWPORTS = {"desktop": {"width": 1366, "height": 900}, "mobile": {"width": 390, "height": 844}}
RESULTS = []

VIRTUAL_CAMERA = """
(() => {
  const poses = %s;
  const original = navigator.mediaDevices.getUserMedia.bind(navigator.mediaDevices);
  navigator.mediaDevices.getUserMedia = async (constraints) => {
    const facing = constraints && constraints.video && constraints.video.facingMode;
    if (facing !== 'user') { return original(constraints); }
    const canvas = document.createElement('canvas');
    canvas.width = 640; canvas.height = 480;
    const ctx = canvas.getContext('2d');
    const images = {};
    await Promise.all(Object.entries(poses).map(([name, url]) => new Promise((resolve) => {
      const img = new Image(); img.onload = () => { images[name] = img; resolve(); }; img.src = url;
    })));
    let current = null, since = 0;
    const pick = () => {
      const el = document.querySelector('[data-challenge]');
      const action = el && !el.hidden ? el.getAttribute('data-current') : null;
      if (action !== current) { current = action; since = performance.now(); }
      const t = (performance.now() - since) / 1000;
      if (action === 'turn_left' || action === 'turn_right') {
        let level = 0;
        if (t > 0.3 && t < 1.1) level = (t - 0.3) / 0.8; else if (t >= 1.1 && t < 1.5) level = 1; else if (t >= 1.5 && t < 2.3) level = 1 - (t - 1.5) / 0.8;
        const idx = Math.round(level * 5);
        return idx === 0 ? images.neutral : images[(action === 'turn_left' ? 'left_' : 'right_') + idx];
      }
      if (action === 'blink') { return t > 0.5 && t < 1.5 ? images.closed : images.neutral; }
      if (action === 'open_mouth') { return t > 0.5 && t < 1.6 ? images.mouth : images.neutral; }
      return images.neutral;
    };
    const draw = () => { ctx.drawImage(pick(), 0, 0, 640, 480); requestAnimationFrame(draw); };
    draw();
    return canvas.captureStream(25);
  };
})();
"""


def check(name, condition, detail=""):
    RESULTS.append((name, bool(condition)))
    print(("  OK   " if condition else "  ÉCHEC ") + name + (f" ({detail})" if detail and not condition else ""))
    if not condition:
        raise AssertionError(name)


def shot(page, out, name, full_page=True):
    path = out / f"{name}.png"
    # Aucun visage dans le dépôt, même du domaine public : flux caméra et aperçus du document masqués.
    page.screenshot(path=str(path), full_page=full_page, mask_color="#5b6475",
                    mask=[page.locator("video"), page.locator("[data-preview-img]")])
    print("  capture", path)


def env_value(name):
    """Dernière valeur non vide de .env (« php bin/project.php demo >> .env » ajoute les lignes en fin de fichier)."""
    values = [line.split("=", 1)[1].strip() for line in (ROOT / ".env").read_text().splitlines() if line.startswith(name + "=")]
    values = [v for v in values if v]
    return values[-1] if values else ""


API_KEY = ""


def api(verify, method, path, body=None):
    opener = urllib.request.build_opener(urllib.request.ProxyHandler({}))
    request = urllib.request.Request(verify + path, method=method, data=json.dumps(body).encode() if body else None,
                                     headers={"Authorization": "Bearer " + (API_KEY or env_value("DEMO_API_KEY")), "Content-Type": "application/json"})
    with opener.open(request, timeout=30) as response:
        return json.load(response)


def context(browser, device="desktop", lang="fr", theme="light", init_script=None):
    ctx = browser.new_context(viewport=VIEWPORTS[device], locale=lang, color_scheme=theme,
                              device_scale_factor=2 if device == "mobile" else 1, is_mobile=device == "mobile", has_touch=device == "mobile")
    if init_script:
        ctx.add_init_script(init_script)
    return ctx


def until_method(page, url, out=None, prefix=None):
    """Consentement général, code (affiché en sandbox), puis liste des méthodes."""
    page.goto(url, wait_until="networkidle")
    page.check("input[name=consent]")
    page.click("button[type=submit]")
    page.wait_for_selector("[data-sandbox-code]")
    page.fill("#code", page.inner_text("[data-sandbox-code]").strip())
    page.click("form[action*='/code?'] button[type=submit]")
    page.wait_for_selector("a[data-method='id_document_face']")
    if out:
        shot(page, out, f"{prefix}-1-methodes")
    page.click("a[data-method='id_document_face']")
    page.wait_for_selector("form[action*='/document/consent'] input[name=consent]")
    if out:
        shot(page, out, f"{prefix}-2-consentement-biometrique")
    page.check("form[action*='/document/consent'] input[name=consent]")
    page.click("form[action*='/document/consent'] button[type=submit]")
    page.wait_for_selector("[data-panel=type]:not([hidden])")


def document_by_camera(page, out=None, prefix=None):
    page.wait_for_selector("[data-panel=document]:not([hidden]) [data-action=shoot]:not([hidden])", timeout=15000)
    time.sleep(1.0)
    if out:
        shot(page, out, f"{prefix}-4-recto-camera", full_page=False)
    page.click("[data-action=shoot]")
    page.wait_for_selector("[data-preview]:not([hidden])")
    verdict = page.inner_text("[data-quality]")
    if out:
        shot(page, out, f"{prefix}-5-recto-apercu")
    page.click("[data-action=use]")
    return verdict


def document_by_file(page, path, out=None, name=None):
    page.wait_for_selector("[data-panel=document]:not([hidden]) [data-action=upload]")
    page.set_input_files("[data-file]", str(path))
    page.wait_for_selector("[data-preview]:not([hidden])")
    if out:
        shot(page, out, name)
    page.click("[data-action=use]")


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--verify", default="http://127.0.0.1:8000")
    parser.add_argument("--assets", required=True)
    parser.add_argument("--out", default="docs/screenshots/phase-3")
    parser.add_argument("--api-key", default=os.environ.get("E2E_API_KEY", ""))
    args = parser.parse_args()
    global API_KEY
    API_KEY = args.api_key
    out = pathlib.Path(args.out)
    out.mkdir(parents=True, exist_ok=True)
    assets = pathlib.Path(args.assets)
    poses = {p.stem: "data:image/jpeg;base64," + base64.b64encode(p.read_bytes()).decode() for p in sorted((assets / "poses").glob("*.jpg"))}
    camera = VIRTUAL_CAMERA % json.dumps(poses)
    doc_poses = {p.stem: "data:image/jpeg;base64," + base64.b64encode(p.read_bytes()).decode() for p in sorted((assets / "doc-poses").glob("*.jpg"))}
    doc_camera = VIRTUAL_CAMERA % json.dumps(doc_poses)
    launch = {"executable_path": os.environ["CHROMIUM_PATH"]} if os.environ.get("CHROMIUM_PATH") else {}
    fake = ["--use-fake-ui-for-media-stream", "--use-fake-device-for-media-stream"]
    stamp = int(time.time())

    with sync_playwright() as p:
        # 1. LIMITE CONNUE : photo fixe animée injectée à la place de la caméra → acceptée (attaque réussie).
        print("Scénario 1 — LIMITE CONNUE : une photo animée injectée passe (FR, desktop)")
        browser = p.chromium.launch(args=[*fake, f"--use-file-for-fake-video-capture={assets / 'card.y4m'}"], **launch)
        ctx = context(browser, init_script=camera)
        page = ctx.new_page()
        console = []
        page.on("console", lambda m: console.append(m.text) if m.type == "error" else None)
        session = api(args.verify, "POST", "/api/v1/sessions", {"email": f"e2e.adult+{stamp}@example.com", "min_age": 18, "lang": "fr"})
        until_method(page, session["verify_url"], out, "capture-fr-desktop")
        shot(page, out, "capture-fr-desktop-3-type-document")
        page.click("[data-panel=type] button[type=submit]")
        verdict = document_by_camera(page, out, "capture-fr-desktop")
        check("recto : caméra (fausse caméra Chromium, y4m) et contrôle de qualité", verdict != "", verdict)
        document_by_file(page, assets / "set" / "back.jpg", out, "capture-fr-desktop-6-verso-fichier")
        page.wait_for_selector("[data-panel=selfie]:not([hidden]) [data-action=start-selfie]:not([hidden])", timeout=15000)
        shot(page, out, "capture-fr-desktop-7-selfie", full_page=False)
        page.click("[data-action=start-selfie]")
        page.wait_for_selector("[data-challenge][data-current]", timeout=10000)
        time.sleep(1.2)
        shot(page, out, "capture-fr-desktop-8-defi", full_page=False)
        live = page.inner_text("#capture-live")
        check("consigne annoncée (région ARIA)", "Consigne" in live, live)
        page.wait_for_selector("[data-panel=sending]:not([hidden])", timeout=30000)
        shot(page, out, "capture-fr-desktop-9-analyse", full_page=False)
        page.wait_for_selector("[data-result-status]", timeout=90000)
        status = page.get_attribute("[data-result-status]", "data-result-status")
        shot(page, out, "capture-fr-desktop-10-resultat")
        check("LIMITE CONNUE : photo animée injectée acceptée (aucune détection d'injection)", status == "verified", status + " " + page.inner_text("main"))
        confirmed = api(args.verify, "GET", "/api/v1/verifications?email=" + urllib.request.quote(f"e2e.adult+{stamp}@example.com"))
        check("API : verified, majeur, méthode id_document_face", (confirmed["status"], confirmed.get("is_adult"), confirmed.get("method")) == ("verified", True, "id_document_face"), str(confirmed))
        check("aucune erreur JavaScript (CSP comprise)", console == [], str(console))
        ctx.close()

        # 1 bis. Captures EN, mobile, sombre : consentement biométrique, type de document, selfie.
        print("Captures : EN, mobile, mode sombre")
        ctx = context(browser, device="mobile", lang="en", theme="dark", init_script=camera)
        page = ctx.new_page()
        session = api(args.verify, "POST", "/api/v1/sessions", {"email": f"e2e.mobile+{stamp}@example.com", "min_age": 18, "lang": "en"})
        until_method(page, session["verify_url"], out, "capture-en-mobile-dark")
        shot(page, out, "capture-en-mobile-dark-3-type-document")
        page.check("input[name=document_type][value=passport]")
        page.click("[data-panel=type] button[type=submit]")
        page.wait_for_selector("[data-panel=document]:not([hidden]) [data-action=shoot]:not([hidden])", timeout=15000)
        time.sleep(1.0)
        check("passeport : consigne propre à la page photo", "passport" in page.inner_text("[data-doc-title]").lower())
        shot(page, out, "capture-en-mobile-dark-4-passeport-camera", full_page=False)
        ctx.close()
        browser.close()

        # 2. Attaque C : le portrait du document lui-même, animé et injecté → refusé.
        print("Scénario 2 : attaque C, portrait du document animé (doit échouer)")
        browser = p.chromium.launch(args=fake, **launch)
        ctx = context(browser, init_script=doc_camera)
        page = ctx.new_page()
        session = api(args.verify, "POST", "/api/v1/sessions", {"email": f"e2e.attack.c+{stamp}@example.com", "min_age": 18, "lang": "fr"})
        until_method(page, session["verify_url"])
        page.click("[data-panel=type] button[type=submit]")
        document_by_file(page, assets / "set" / "front.jpg")
        document_by_file(page, assets / "set" / "back.jpg")
        page.wait_for_selector("[data-action=start-selfie]:not([hidden])", timeout=15000)
        page.click("[data-action=start-selfie]")
        page.wait_for_selector("[data-result-status]", timeout=90000)
        reason = page.get_attribute("[data-failure-reason]", "data-failure-reason")
        shot(page, out, "capture-fr-desktop-11b-attaque-c-portrait-anime")
        check("attaque C : portrait du document animé refusé (liveness_failed)", reason == "liveness_failed", str(reason))
        ctx.close()

        # 3. Attaque A : un selfie envoyé à la place du recto → refusé.
        print("Scénario 3 : attaque A, selfie au lieu du recto (doit échouer)")
        ctx = context(browser, init_script=camera)
        page = ctx.new_page()
        session = api(args.verify, "POST", "/api/v1/sessions", {"email": f"e2e.attack.a+{stamp}@example.com", "min_age": 18, "lang": "fr"})
        until_method(page, session["verify_url"])
        page.click("[data-panel=type] button[type=submit]")
        document_by_file(page, assets / "poses" / "neutral.jpg")
        document_by_file(page, assets / "set" / "back.jpg")
        page.wait_for_selector("[data-action=start-selfie]:not([hidden])", timeout=15000)
        page.click("[data-action=start-selfie]")
        page.wait_for_selector("[data-result-status]", timeout=90000)
        reason = page.get_attribute("[data-failure-reason]", "data-failure-reason")
        shot(page, out, "capture-fr-desktop-11c-attaque-a-selfie-au-recto")
        check("attaque A : selfie au lieu du recto refusé (document_inconsistent)", reason == "document_inconsistent", str(reason))
        ctx.close()
        browser.close()

        # 4. Vidéo fixe d'un visage (fausse caméra Chromium, y4m) : le contrôle du vivant échoue.
        print("Scénario 4 : vidéo fixe rejouée (y4m), documents par fichier (FR)")
        browser = p.chromium.launch(args=[*fake, f"--use-file-for-fake-video-capture={assets / 'face.y4m'}"], **launch)
        ctx = context(browser)
        page = ctx.new_page()
        session = api(args.verify, "POST", "/api/v1/sessions", {"email": f"e2e.replay+{stamp}@example.com", "min_age": 18, "lang": "fr"})
        until_method(page, session["verify_url"])
        page.click("[data-panel=type] button[type=submit]")
        document_by_file(page, assets / "set" / "front.jpg")
        document_by_file(page, assets / "set" / "back.jpg")
        page.wait_for_selector("[data-action=start-selfie]:not([hidden])", timeout=15000)
        page.click("[data-action=start-selfie]")
        page.wait_for_selector("[data-result-status]", timeout=90000)
        reason = page.get_attribute("[data-failure-reason]", "data-failure-reason")
        shot(page, out, "capture-fr-desktop-11-echec-video-rejouee")
        check("vidéo fixe : échec liveness_failed", reason == "liveness_failed", str(reason))
        ctx.close()

        # 3. Sans caméra : le selfie reste obligatoire en direct (aucun envoi de fichier proposé).
        print("Scénario 5 : aucune caméra")
        ctx = context(browser, init_script="navigator.mediaDevices.getUserMedia = () => Promise.reject(new DOMException('none', 'NotFoundError'));")
        page = ctx.new_page()
        session = api(args.verify, "POST", "/api/v1/sessions", {"email": f"e2e.nocam+{stamp}@example.com", "min_age": 18, "lang": "fr"})
        until_method(page, session["verify_url"])
        page.click("[data-panel=type] button[type=submit]")
        page.wait_for_selector("[data-panel=document]:not([hidden]) [data-camera-status]:has-text('caméra')", timeout=10000)
        check("sans caméra : envoi de fichier proposé pour le document", page.is_visible("[data-action=upload]") and not page.is_visible("[data-action=shoot]"))
        shot(page, out, "capture-fr-desktop-12-sans-camera-document")
        document_by_file(page, assets / "set" / "front.jpg")
        document_by_file(page, assets / "set" / "back.jpg")
        page.wait_for_selector("[data-panel=selfie]:not([hidden])")
        page.wait_for_function("document.querySelector('[data-panel=selfie] [data-camera-status]').textContent.length > 0")
        check("sans caméra : selfie impossible, aucun envoi de fichier", not page.is_visible("[data-action=start-selfie]") and page.query_selector("[data-panel=selfie] input[type=file]") is None)
        shot(page, out, "capture-fr-desktop-13-sans-camera-selfie")
        ctx.close()
        browser.close()

    print(f"\n{sum(ok for _, ok in RESULTS)}/{len(RESULTS)} contrôles réussis")
    return 0 if all(ok for _, ok in RESULTS) else 1


if __name__ == "__main__":
    try:
        sys.exit(main())
    except AssertionError:
        print(f"\nÉCHEC après {sum(ok for _, ok in RESULTS)}/{len(RESULTS)} contrôles")
        sys.exit(1)
