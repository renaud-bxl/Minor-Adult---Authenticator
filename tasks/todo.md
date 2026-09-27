# VeriAge : plan et état d'avancement

Légende : `[ ]` à faire · `[~]` en cours · `[x]` terminé (avec preuve) · `⚖️` à faire valider par un juriste · `🌐` à faire relire par un traducteur humain

## État actuel
- [x] Section 0 : lessons.md, todo.md, CLAUDE.md créés. Questions bloquantes posées et tranchées (voir CLAUDE.md).
- [ ] **Prochaine étape : validation du plan détaillé de la phase 1 par Renaud**, puis implémentation.

---

## Phase 0 : Serveur (Debian 12 + HestiaCP)
À exécuter sur le VPS (accès root). Le code est préparé ici, dans `deploy/`.
- [ ] `deploy/inventory.sh` : OS, CPU/RAM/disque, GPU, versions PHP/MariaDB/Redis/Python, modules PHP (`intl`, `openssl`, `gd`, `sodium`, `pdo_mysql`, `mbstring`), stack web Hestia (nginx+Apache ou nginx+php-fpm), modules Apache (`ssl`, `headers`, `rewrite`).
- [ ] Analyser la sortie de l'inventaire avant d'écrire la moindre configuration.
- [ ] Créer dans Hestia un utilisateur dédié `veriage` et les domaines `veriage.eu`, `verify.veriage.eu`, `eid.veriage.eu`.
- [ ] Templates web Hestia personnalisés (`deploy/hestia/`) : docroot pointé sur `public/`, en-têtes de sécurité, pool PHP-FPM dédié.
- [ ] Template `eid.` : authentification par certificat client (nginx `ssl_verify_client` + transmission du certificat au backend si nginx est en frontal, sinon Apache `SSLVerifyClient require`). Chaîne Belgium Root CA + Citizen CA.
- [ ] Let's Encrypt via Hestia. Pare-feu Hestia : 22, 80, 443 uniquement (ports du panneau Hestia restreints par IP). Fail2ban Hestia actif.
- [ ] Redis (apt), lié à 127.0.0.1 avec mot de passe. Python 3.11 + venv pour `biometrics/`, Tesseract.
- [ ] Sauvegardes : sauvegardes Hestia + dump MariaDB chiffré quotidien hors VPS.
- [ ] Services systemd : `veriage-worker@.service` (vérifications, webhooks), `veriage-biometrics.service`. Crontab : `deploy/crontab`.
- [ ] Script `deploy/deploy.sh` (git pull, composer install --no-dev, migrations, cache, redémarrage des workers).

## Phase 1 : Fondations  ← PLAN DÉTAILLÉ À VALIDER
Objectif : squelette MVC fonctionnel, i18n FR+EN, authentification des comptes clients, layout. Aucune logique de vérification.

### 1.1 Projet et configuration
- [ ] `composer.json` : PHP ≥ 8.2, PSR-4 `App\` → `app/`. Dépendances : `vlucas/phpdotenv`, `phpmailer/phpmailer`, `predis/predis`. Dev : `phpunit/phpunit` (justifié : les tests sont exigés par la section 9).
- [ ] `.gitignore`, `.env.example` complet (APP_*, DB_*, REDIS_*, MAIL_*, CRYPTO_KEY, LANGS_ENABLED…).
- [ ] `app/bootstrap.php` : chargement du `.env`, fuseau Europe/Brussels, gestion des erreurs (page 500 générique, log détaillé sans données personnelles), helpers globaux.
- [ ] `config/*.php` : app, database, redis, mail, i18n, security.

### 1.2 Noyau MVC (`app/Core`)
- [ ] `Router` : méthodes HTTP, paramètres `{id}`, groupes avec préfixe et middlewares, 404/405.
- [ ] `Request` / `Response` (HTML, JSON, redirection), `View` (vues PHP + layouts, `e()` pour échapper).
- [ ] `Database` : PDO MariaDB (`ERRMODE_EXCEPTION`, `EMULATE_PREPARES=false`, utf8mb4), transactions.
- [ ] `Session` : sessions PHP stockées dans Redis (handler maison sur predis), cookies `Secure`/`HttpOnly`/`SameSite=Lax`, régénération de l'ID à la connexion.
- [ ] `Csrf` (jeton par session, vérifié par un middleware sur toute requête non-GET du site).
- [ ] `Crypto` : AES-256-GCM (e-mails chiffrés), HMAC-SHA256 salé (hash d'e-mail). Base des phases suivantes.
- [ ] `RateLimiter` sur Redis (fenêtre glissante), utilisé dès la phase 1 pour la connexion.
- [ ] Middlewares : `SecurityHeaders` (CSP stricte sans inline, HSTS, nosniff, Referrer-Policy, X-Frame-Options DENY), `Csrf`, `Auth`, `Guest`, `Locale`.

### 1.3 Migrations
- [ ] `bin/migrate.php` : applique `database/migrations/NNNN_nom.sql` dans l'ordre, suivi via une table `migrations`, chaque migration dans une transaction.
- [ ] Tables initiales : `accounts` (entreprise cliente, langue préférée), `users`, `account_users` (rôle : owner / developer / accountant), `user_tokens` (validation d'e-mail, réinitialisation ; jetons stockés hashés, expiration), `audit_log` (acteur, action, date, IP tronquée, sans donnée d'identité).

### 1.4 Internationalisation
- [ ] `App\I18n\Translator` + helper `__('site.home.title', ['name' => …])`, domaines `site`, `module`, `emails`, `billing`, `errors` dans `lang/{code}/{domaine}.php`.
- [ ] Chaîne de repli : langue demandée → EN → clé (+ log de la clé manquante).
- [ ] Détection : paramètre `lang` > préférence du compte > préfixe d'URL > `Accept-Language` (avec q-values) > EN.
- [ ] URL préfixées `/{lang}/…` pour le site, redirection de `/` vers la langue détectée, balises `hreflang` générées dans le layout.
- [ ] `App\I18n\Formatter` : dates, nombres, devises (`IntlDateFormatter`, `NumberFormatter`).
- [ ] Endpoint `GET /api/v1/i18n/{code}` : clés du domaine `module` en JSON (servira au widget), avec cache HTTP.
- [ ] FR (source) + EN complets. Les 24 dossiers `lang/` sont créés, mais seules les langues de `LANGS_ENABLED` (fr,en) sont servies jusqu'à la phase 8.
- [ ] `tools/check_translations.php` : clés manquantes et orphelines par langue (référence : FR), plus un scan du code pour les `__()` inconnus. Code retour ≠ 0 si moins de 100 %.

### 1.5 Authentification des comptes clients
- [ ] Inscription (entreprise + e-mail + mot de passe ; Argon2id, 12 caractères minimum), e-mail de validation (jeton à usage unique, 24 h).
- [ ] Connexion / déconnexion, rate limiting (par IP et par e-mail), message d'erreur générique (pas d'énumération des comptes).
- [ ] Mot de passe oublié / réinitialisation (jeton 1 h, invalidation des sessions existantes).
- [ ] `App\Services\Mailer` : PHPMailer en SMTP, gabarits HTML + texte traduits ; en dev, `MAIL_DRIVER=log`.
- [ ] Tableau de bord client minimal (page protégée « Bienvenue », elle sera remplie en phase 5).
- [ ] 2FA TOTP : phase 5 (voir plus bas).

### 1.6 Layout et front
- [ ] Layout HTML sémantique, CSS vanilla (variables, responsive, mode sombre), sélecteur de langue, messages flash, pages 404/500 traduites.
- [ ] JS vanilla minimal (aucun script inline, compatible CSP).

### 1.7 Vérification de la phase 1 (preuves exigées)
- [ ] PHPUnit : Router, Translator (repli, paramètres, détection Accept-Language), Crypto (aller-retour, hash stable par sel), Csrf, RateLimiter, validation des mots de passe, migrations.
- [ ] Tests d'intégration sur MariaDB + Redis réels (installés dans le conteneur de dev) : inscription → validation → connexion → réinitialisation.
- [ ] `tools/check_translations.php` → 100 % pour fr et en.
- [ ] `php -S` + cURL : en-têtes de sécurité présents, `/` → `/fr/` selon Accept-Language, POST sans CSRF → 419, 6e tentative de connexion → 429.
- [ ] Captures d'écran (Playwright) des pages accueil, inscription, connexion en FR et EN, sur desktop et mobile.

---

## Phase 2 : API et module
- [ ] Tables `projects`, `api_keys` (`sk_live_` / `sk_test_`, hash SHA-256 + préfixe visible), `verification_sessions`, `verifications` (email_hash, email_enc, is_adult, method, verified_at, expires_at), `webhook_endpoints`, `webhook_deliveries`.
- [ ] Auth API Bearer, rate limiting par clé et par IP, erreurs JSON normalisées (`402 insufficient_credits` préparé).
- [ ] `POST /api/v1/sessions`, `GET /api/v1/verifications?email=`, `DELETE /api/v1/verifications?email=`. Statuts : `not_verified`, `pending`, `failed`, `verified`.
- [ ] Réutilisation : même client + non expirée → résultat immédiat, non facturé. Entre clients : seulement avec consentement explicite.
- [ ] Preuve de contrôle de l'e-mail : code à 6 chiffres (ou lien magique) avant de lier le résultat.
- [ ] Blocage après X échecs par e-mail, journal d'audit.
- [ ] Retour : redirection `return_url?session_id=&token=` (JWT court, `firebase/php-jwt`), webhook HMAC-SHA256 (`X-VeriAge-Signature: t=…,v1=…`, fenêtre anti-rejeu de 5 min), worker systemd avec relances exponentielles.
- [ ] Interface `VerificationMethodInterface` + `MockProvider` (sandbox `sk_test_`).
- [ ] Page de vérification hébergée `verify.veriage.eu/s/{session_id}` : choix de la méthode, consentement art. 9, sans cookie tiers.
- [ ] Widget `public/widget/verify.js` : modes `modal`, `popup`, `iframe`, `redirect` ; `postMessage` avec contrôle de l'origine ; événements `veriage:*` ; CSP `frame-ancestors` par client ; iframe `allow="camera; fullscreen"` ; bascule popup/redirection pour l'eID ; plein écran sur mobile.
- [ ] `docs/api-tests.md` (collection cURL) et tests de signature et d'anti-rejeu.

## Phase 3 : Document d'identité + visage (100 % local)
- [ ] Capture JS (`getUserMedia`) du recto et du verso avec cadrage guidé et contrôle de netteté. Upload chiffré en mémoire vers PHP, relayé au microservice. Jamais écrit sur disque.
- [ ] Microservice `biometrics/` (FastAPI, 127.0.0.1), modèles sous licence commerciale :
  - MRZ : Tesseract (Apache-2.0) + modèle OCR-B/MRZ, ou PassportEye (MIT) ; TD1 (carte ID BE/UE, 3 lignes) et TD3 (passeport).
  - Détection du visage : OpenCV Zoo **YuNet** (MIT). Comparaison : OpenCV Zoo **SFace** (Apache-2.0).
  - Liveness : **MediaPipe Face Landmarker** (Apache-2.0) : défis aléatoires (tourner la tête gauche/droite, cligner des yeux), contrôle de cohérence temporelle, détection basique de rejeu d'écran.
  - ⛔ InsightFace : ses modèles pré-entraînés sont réservés à un usage non commercial, on l'écarte. À documenter dans `docs/licences.md`.
  - Réponse : `{ age, doc_expired, face_match_score, liveness_passed }`, jamais d'image ni d'identité.
- [ ] PHP : contrôle des chiffres de la MRZ (7-3-1), calcul de l'âge exact et du document expiré, seuils configurables dans l'admin, échec ou revue manuelle selon le réglage du client.
- [ ] Piste IA à étudier : estimation de l'âge par le visage, en signal complémentaire (modèle à licence commerciale à trouver). À valider avant de l'inclure.
- [ ] Tests : MRZ (jeux ICAO), calcul d'âge (anniversaire le jour même, 29 février), jeux d'images de test.

## Phase 4 : eID belge (lecteur de carte + PIN)
- [ ] Vhost `eid.` avec certificat client (voir phase 0), OCSP, chaîne de certificats vérifiée en PHP.
- [ ] Extraction du NRN depuis `serialNumber`, puis de l'âge seul (règle du siècle via le chiffre de contrôle, mod 97 avec préfixe 2 pour les naissances ≥ 2000), effacement immédiat. Jamais en base ni dans les logs.
- [ ] Page d'aide traduite, détection de l'échec, proposition d'une autre méthode.
- [ ] Tests : NRN 19xx/20xx, date de naissance inconnue (mois 00), anniversaire le jour même. Tests navigateurs (Firefox, Chrome, Edge, Safari ; macOS et Windows).
- [ ] ⚖️ `docs/juridique.md` : usage du NRN par une entreprise privée (loi du 8 août 1983, autorisation éventuelle).

## Phase 5 : Espace client complet
- [ ] 2FA TOTP (RFC 6238 implémenté en interne, QR code via `endroid/qr-code`), codes de secours.
- [ ] Profil de l'entreprise + TVA validée via VIES (SOAP/REST de la Commission européenne).
- [ ] Projets : clés API test et live (régénération), webhook, domaines autorisés, âge minimum (16/18/21), méthodes, durée de validité, personnalisation du widget (logo, couleur, texte par langue).
- [ ] Tableau de bord avec graphiques (SVG/Canvas vanilla), journal des vérifications + export CSV, multi-utilisateurs et rôles.
- [ ] Exemples d'intégration : PHP, JS, cURL, plugin WordPress/WooCommerce.

## Phase 6 : Facturation (Stripe)
- [ ] `PaymentGatewayInterface` + `StripeGateway` (carte, Bancontact, SEPA, iDEAL ; portail client).
- [ ] Crédits prépayés : packs 100 / 250 / 500 / 1 000 € et montant libre, prix dégressifs par méthode, alertes à 20 % et 5 %, recharge automatique, `402`.
- [ ] Abonnements (valeurs de départ proposées, modifiables dans l'admin) :
  - Small : 500 vérifs/mois, 99 €
  - Medium : 2 000 vérifs/mois, 299 €
  - Large : 10 000 vérifs/mois, 990 €
  - XL : 50 000 vérifs/mois, 3 490 €
  - Dépassement : montée de palier au prorata, ou facturation à l'unité.
- [ ] Prix unitaires proposés (prépayé) : document + visage 0,60 € → 0,40 € selon le pack ; eID 0,30 € → 0,20 €.
- [ ] Webhooks Stripe idempotents (table `stripe_events`).
- [ ] Factures PDF conformes (numérotation continue, TVA 21 %, autoliquidation UE via VIES). **Dépendance PDF à justifier** : `dompdf/dompdf` ou `tecnickcom/tcpdf`.
- [ ] Tests : décompte des crédits, montée de palier, numérotation des factures.

## Phase 7 : Back-office admin
- [ ] Clients (suspension, ajustement de crédits), offres et prix, seuils biométriques, chiffre d'affaires et factures, journaux système, alertes.

## Phase 8 : Traductions (24 langues UE)
- [ ] Script `tools/translate.php` (API Claude ou DeepL, **outil de dev uniquement**, jamais appelé à l'exécution) : FR → 22 langues restantes.
- [ ] `check_translations.php` à 100 % sur les 24 langues.
- [ ] 🌐 CGV, confidentialité, cookies, DPA : relecture par un traducteur humain.

## Phase 9 : Site vitrine, documentation, pages légales
- [ ] Accueil, fonctionnement, méthodes, tarifs, documentation développeurs, FAQ, contact, page « utilisateurs finaux », sitemap multilingue.
- [ ] Formulaire public de demande d'effacement.
- [ ] ⚖️ CGV, confidentialité, cookies, DPA. `docs/integration.md` en FR, NL, EN et DE.

## Phase 10 : Tests, audit de sécurité, déploiement
- [ ] Audit : OWASP ASVS niveau 2, revue des en-têtes, fuzzing de l'API, revue RGPD (aucune donnée d'identité en base ni dans les logs).
- [ ] ⚖️ `docs/rgpd.md` : registre des traitements, AIPD (données biométriques, art. 9), DPA.
- [ ] README d'installation pas à pas, puis déploiement.
