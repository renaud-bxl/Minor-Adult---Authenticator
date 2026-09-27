# VeriAge : plan et état d'avancement

Légende : `[ ]` à faire · `[~]` en cours · `[x]` terminé (avec preuve) · `⚖️` à faire valider par un juriste · `🌐` à faire relire par un traducteur humain

## État actuel
- [x] Section 0 : lessons.md, todo.md, CLAUDE.md créés. Questions bloquantes posées et tranchées (voir CLAUDE.md).
- [x] Plan de la phase 1 validé (protocole multi-agents, 2026-09-27).
- [~] **Phase 1 implémentée par le créateur** : en attente du contrôleur puis de l'audit critique.

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

## Phase 1 : Fondations  ← IMPLÉMENTÉE (créateur, 2026-09-27), en attente de contrôle et d'audit
Objectif : squelette MVC fonctionnel, i18n FR+EN, authentification des comptes clients, layout. Aucune logique de vérification.
Preuves : `vendor/bin/phpunit` → OK (104 tests, 366 assertions : unit 80/169, integration 24/197) ; `php tools/check_translations.php` → 100 % fr et en ; cURL dans `docs/api-tests.md` ; captures dans `docs/screenshots/phase-1/`.

### 1.1 Projet et configuration
- [x] `composer.json` : PHP ≥ 8.2 (plateforme figée à 8.2.0 pour le lock), PSR-4 `App\` → `app/`. Dépendances : `vlucas/phpdotenv` 5.7, `phpmailer/phpmailer` 6.12, `predis/predis` 2.4. Dev : `phpunit/phpunit` 11.5 (justifié : tests exigés par la section 9 ; 11.x car la 12 exige PHP 8.3).
- [x] `.gitignore`, `.env.example` complet (APP_*, DB_*, REDIS_*, SESSION_*, PASSWORD_ARGON2_*, MAIL_*, CRYPTO_KEY, LANGS_ENABLED…) ; `bin/generate-keys.php`.
- [x] `app/bootstrap.php` : `.env`, fuseau Europe/Brussels, `ErrorHandler` (page 500 générique, journal JSON sans donnée personnelle : e-mails et IP masqués, message des PDOException écarté).
- [x] `config/*.php` : app, database, redis, mail, i18n, security.

### 1.2 Noyau MVC (`app/Core`)
- [x] `Router` : méthodes, `{id}` / `{id:regex}`, groupes imbriqués (préfixe + middlewares), 404/405 (+ `Allow`), HEAD → GET. Preuve : `RouterTest`.
- [x] `Request` / `Response` (HTML, JSON, redirection interne uniquement), `View` (layouts, `e()`). `Kernel` + `ErrorRenderer` (pages traduites / JSON pour l'API).
- [x] `Database` : PDO (`ERRMODE_EXCEPTION`, `EMULATE_PREPARES=false`, utf8mb4, sql_mode strict, UTC), transactions.
- [x] `Session` : données JSON dans Redis (`RedisSessionHandler`, predis), cookie `__Host-` Secure/HttpOnly/SameSite=Lax, mode strict anti-fixation, régénération à la connexion, expiration d'inactivité + absolue, aucune session créée pour un visiteur sans état. Choix : gestionnaire maison plutôt que `session_start()` (pas d'état global, testable). Preuves : `SessionTest`, `RedisSessionHandlerTest`.
- [x] `Csrf` (jeton par session, `hash_equals`, rotation à la connexion) + middleware sur toute requête non sûre. Preuve : `CsrfTest`, 419 en cURL.
- [x] `Crypto` : AES-256-GCM versionné avec AAD, HMAC-SHA256 d'e-mail salé par client et poivré, jetons base64url hashés en SHA-256. Preuve : `CryptoTest`.
- [x] `RateLimiter` Redis (fenêtre glissante, script Lua atomique horodaté par Redis, identifiants hashés). Preuve : `RateLimiterTest`, 429 en cURL.
- [x] Middlewares : `SecurityHeaders` (CSP stricte sans inline, HSTS (`includeSubDomains` désactivé par défaut : VPS partagé), nosniff, Referrer-Policy, X-Frame-Options DENY, Permissions-Policy, COOP/CORP), `VerifyCsrfToken`, `Authenticate`, `RedirectIfAuthenticated` (Guest), `SetLocale`, `StartSession`.

### 1.3 Migrations
- [x] `bin/migrate.php` (+ `--status`) / `App\Core\Migrator` : fichiers `NNNN_nom.sql` ordonnés, table `migrations`, transaction par migration, verrou `GET_LOCK`. Limite MariaDB documentée : le DDL valide implicitement ; règle « un DDL par migration » vérifiée par un test. Preuves : `MigratorTest`, `MigratorIntegrationTest`.
- [x] Tables : `accounts` (langue préférée), `users` (`auth_version`), `account_users` (owner / developer / accountant), `user_tokens` (SHA-256, expiration, usage unique), `audit_log` (IP tronquée /24 ou /48, aucune donnée d'identité).

### 1.4 Internationalisation
- [x] `App\I18n\Translator` + `__('site.home.title', ['name' => …])`, domaines `site`, `module`, `emails`, `billing` (vide jusqu'en phase 6), `errors`.
- [x] Repli : langue demandée → EN → clé (+ avertissement journalisé une fois par clé). Preuve : `TranslatorTest`.
- [x] Détection : `?lang=` (mémorisé en session et comme préférence du compte si connecté) > préférence du compte (chargée en session à la connexion) > préfixe d'URL > `Accept-Language` (q-values) > EN. Preuve : `LocaleNegotiatorTest`, `HttpTest`.
- [x] URL `/{lang}/…`, `/` → langue détectée (`Vary: Accept-Language, Cookie`), `hreflang` + `x-default` dans le layout.
- [x] `App\I18n\Formatter` : dates (fuseau Bruxelles), nombres, devises en centimes. Preuve : `FormatterTest`.
- [x] `GET /api/v1/i18n/{code}` : domaine `module` en JSON, `Cache-Control: public, max-age=3600`, ETag/304, CORS `*`.
- [x] FR (source) + EN complets (135 clés). 24 dossiers `lang/` créés ; seules fr,en servies (`LANGS_ENABLED`).
- [x] `tools/check_translations.php` : manquantes, orphelines, paramètres `{x}` divergents, scan du code (clés inconnues), `--all`, code retour ≠ 0 si < 100 %.

### 1.5 Authentification des comptes clients
- [x] Inscription (entreprise + e-mail + mot de passe ; Argon2id, 12 caractères min., 1 024 max., ≠ e-mail) ; e-mail de validation (jeton à usage unique, 24 h). Adresse déjà inscrite : même réponse + e-mail d'information au titulaire.
- [x] Connexion / déconnexion, rate limiting (IP et e-mail), message générique, temps de réponse comparable (hash factice), connexion refusée tant que l'adresse n'est pas confirmée (lien renvoyé, limité à 3/h).
- [x] Mot de passe oublié / réinitialisation (jeton 1 h, consommation atomique, invalidation de toutes les sessions via `auth_version`, e-mail « mot de passe modifié »).
- [x] `App\Services\Mailer` : PHPMailer SMTP, gabarits HTML + texte traduits ; `MAIL_DRIVER=log` écrit le MIME dans `storage/mail/` (interdit en production), destinataires jamais journalisés.
- [x] Tableau de bord minimal protégé (« Bienvenue, {entreprise} »).
- [ ] 2FA TOTP : phase 5 (voir plus bas).

### 1.6 Layout et front
- [x] Layout sémantique, CSS vanilla (variables, responsive, mode sombre), sélecteur de langue sans JS (`details`), messages flash, pages 404/405/419/429/500 traduites, lien d'évitement, attributs ARIA sur les erreurs de formulaire.
- [x] JS vanilla minimal (`public/assets/js/app.js`, aucun script inline, libellés via `data-*` traduits).

### 1.7 Vérification de la phase 1 (preuves)
- [x] PHPUnit unitaires : Router, Translator, LocaleNegotiator (Accept-Language), Crypto, Session, Csrf, PasswordPolicy/Argon2id, Migrator, Formatter, primitives HTTP/Logger/IP → OK (80 tests, 169 assertions).
- [x] Intégration MariaDB 10.11 + Redis 7 réels : inscription → validation → connexion → réinitialisation → sessions invalidées, anti-énumération, 429, CSRF, langues, API i18n, 500 générique (Redis coupé), migrations → OK (24 tests, 197 assertions).
- [x] `php tools/check_translations.php` → 100 % fr (135/135) et en (135/135), 0 clé inconnue.
- [x] `php -S` + cURL (`docs/api-tests.md`) : en-têtes présents, `/` → `/fr/` ou `/en/` selon Accept-Language, POST sans CSRF → 419, 6e tentative → 429 (`Retry-After`).
- [x] Captures Playwright (Chromium) : accueil, inscription, connexion × FR/EN × desktop (1366 px)/mobile (390 px) → `docs/screenshots/phase-1/` (12 fichiers).

### Limites connues (à traiter plus tard)
- [ ] Envoi des e-mails synchrone dans la requête : la différence de durée « compte existant / inexistant » sur « mot de passe oublié » dépend du temps SMTP. À passer par la file Redis (worker, phase 2).
- [ ] Verrouillage par adresse e-mail (5 / 15 min) : un tiers peut bloquer temporairement la connexion d'un compte (compromis classique, à réévaluer avec la 2FA en phase 5).
- [ ] `php -S` ne pose pas les en-têtes de sécurité sur les fichiers statiques ; en production : template Hestia (phase 0).
- [ ] Dossiers `app/Verification`, `app/Billing`, `cron/`, `deploy/` encore vides (phases suivantes).

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
