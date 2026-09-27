# VeriAge : plan et état d'avancement

Légende : `[ ]` à faire · `[~]` en cours · `[x]` terminé (avec preuve) · `⚖️` à faire valider par un juriste · `🌐` à faire relire par un traducteur humain

## État actuel
- [x] Section 0 : lessons.md, todo.md, CLAUDE.md créés. Questions bloquantes posées et tranchées (voir CLAUDE.md).
- [x] Plan de la phase 1 validé (protocole multi-agents, 2026-09-27).
- [x] **Phase 1 APPROUVÉE par le critique** (168 tests verts ; `tasks/reviews/phase-1-*.md`).
- [x] **Phase 2 APPROUVÉE par le critique** (263 tests + E2E 34/34 ; `tasks/reviews/phase-2-*.md`).
- [~] Phase 3 implémentée (document + visage, biométrie 100 % locale) et contrôlée (`tasks/reviews/phase-3-controle.md`), en attente d'audit.

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
  (Phase 2 : `deploy/systemd/veriage-worker@.service` et `deploy/crontab` préparés, chemins à ajuster ; installation via Hestia/systemd en phase 0.)
- [ ] Template Hestia de l'hôte `verify.` : journaux d'accès SANS chaîne de requête (`GET /api/v1/verifications?email=` : l'adresse ne doit pas finir dans les logs Apache/nginx) ; `CGIPassAuth On` exige `AllowOverride AuthConfig` (sinon 500 sur tout le site, ré-audit phase 1, reco 21) ; en-têtes de sécurité sur les statiques ; `widget/verify.js` servi avec un cache court (déjà dans `.htaccess`).
- [ ] Alias du domaine nu (`veriage.eu`) vers le vhost du site : l'application le redirige en 301 vers `APP_URL`.
- [ ] Script `deploy/deploy.sh` (git pull, composer install --no-dev, migrations, cache, redémarrage des workers).

## Phase 1 : Fondations  ← IMPLÉMENTÉE (créateur, 2026-09-27), en attente de contrôle et d'audit
Objectif : squelette MVC fonctionnel, i18n FR+EN, authentification des comptes clients, layout. Aucune logique de vérification.
Preuves : `vendor/bin/phpunit` → OK (après corrections de l'audit : 168 tests, 625 assertions après contrôle : unit 128/335, integration 40/290) ; `php tools/check_translations.php` → 100 % fr et en ; cURL dans `docs/api-tests.md` ; captures dans `docs/screenshots/phase-1/`.

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
- [x] Inscription (entreprise + e-mail + mot de passe ; Argon2id, 12 caractères min., 1 024 max.) ; e-mail de validation (jeton à usage unique, 24 h). Adresse déjà inscrite : même réponse + e-mail d'information au titulaire.
- [x] Connexion / déconnexion, rate limiting (IP et e-mail), message générique, temps de réponse comparable (hash factice), connexion refusée tant que l'adresse n'est pas confirmée (lien renvoyé, limité à 3/h).
- [x] Mot de passe oublié / réinitialisation (jeton 1 h, consommation atomique, invalidation de toutes les sessions via `auth_version`, e-mail « mot de passe modifié »).
- [x] `App\Services\Mailer` : PHPMailer SMTP, gabarits HTML + texte traduits ; `MAIL_DRIVER=log` écrit le MIME dans `storage/mail/` (interdit en production), destinataires jamais journalisés.
- [x] Tableau de bord minimal protégé (« Bienvenue, {entreprise} »).
- [ ] 2FA TOTP : phase 5 (voir plus bas).
- [x] Corrections de l'audit critique (2026-09-27, suivi : `tasks/reviews/phase-1-critique-suivi.md`) :
  - Politique de mot de passe (ASVS V2.1.7, NIST 800-63B) : liste locale de 55 741 mots de passe courants ou compromis (451 Ko ; entrées inatteignables par la politique retirées par le contrôleur) (`resources/security/common-passwords.txt`, recherche dichotomique, aucun appel externe), mot courant décoré (« !Sunshine2026 »), suites et répétitions, contexte (nom du service, e-mail, raison sociale), NFC avant hachage. Preuves : `PasswordPolicyTest`, `InputHardeningTest`.
    Source de la liste : union dédupliquée, en minuscules NFC, entrées ≥ 4 caractères, triée par octets, de `100k-most-used-passwords-NCSC.txt` (NCSC britannique, issu de Have I Been Pwned) et `xato-net-10-million-passwords-100000.txt`, dépôt SecLists (`Passwords/Common-Credentials`, licence MIT). Dépendance de données justifiée : exigence ASVS niveau 1.
    Régénération : `cat ncsc.txt xato.txt | tr -d '\r'` puis minuscules + NFC + tri par octets (`LC_ALL=C sort -u`), puis filtre « ≥ 12 caractères, ou ≥ 4 bordés de lettres » ; le test `testBlocklistLookupIsExact` vérifie le tri et le filtre. Origine et licence MIT : `resources/security/NOTICE.md`.
  - Inactivité de session : 30 min par défaut (ASVS V3.3.2), refus de démarrer en production au-delà. Preuve : `InputHardeningTest::testSessionIdleTimeoutIsThirtyMinutes`.
  - Champs texte : `App\Core\TextInput` (UTF-8 valide, NFC, refus Cc/Cf/Zl/Zp dont bidi et sauts de ligne, espaces compactés), appliqué à la raison sociale et aux e-mails (inscription, connexion, mot de passe oublié) ; erreur 422 traduite, plus de faux succès. Preuves : `TextInputTest`, `InputHardeningTest`.
  - Contrastes WCAG 1.4.11 : bordure des champs 4,01:1 (clair) / 4,44:1 (sombre), focus ≥ 4,47:1 / ≥ 8,98:1. Preuve : `FrontendContrastTest` + captures (focus, erreurs, mode sombre).

### 1.6 Layout et front
- [x] Layout sémantique, CSS vanilla (variables, responsive, mode sombre), sélecteur de langue sans JS (`details`), messages flash, pages 404/405/419/429/500 traduites, lien d'évitement, attributs ARIA sur les erreurs de formulaire.
- [x] JS vanilla minimal (`public/assets/js/app.js`, aucun script inline, libellés via `data-*` traduits).

### 1.7 Vérification de la phase 1 (preuves)
- [x] PHPUnit unitaires : Router, Translator, LocaleNegotiator (Accept-Language), Crypto, Session, Csrf, PasswordPolicy/Argon2id, Migrator, Formatter, primitives HTTP/Logger/IP, TextInput, liste de blocage, ConfigValidator, rétention des journaux, contrastes CSS → OK (128 tests, 421 assertions).
- [x] Intégration MariaDB 10.11 + Redis 7 réels : inscription → validation → connexion → réinitialisation → sessions invalidées, anti-énumération, 429, CSRF, langues, API i18n, 500 générique (Redis coupé), migrations, champs texte invalides, mots de passe courants/contextuels, inactivité 30 min → OK (40 tests, 290 assertions).
- [x] `php tools/check_translations.php` → 100 % fr (138/138) et en (138/138), 0 clé inconnue.
- [x] `php -S` + cURL (`docs/api-tests.md`) : en-têtes présents, `/` → `/fr/` ou `/en/` selon Accept-Language, POST sans CSRF → 419, 6e tentative → 429 (`Retry-After`).
- [x] Captures Playwright (Chromium, `tools/screenshots.py`) → `docs/screenshots/phase-1/` (43 fichiers) : accueil, inscription, connexion × FR/EN × desktop (1366 px)/mobile (390 px) × clair/sombre ; focus clavier, erreurs de formulaire, mot de passe oublié, 404 (FR/EN, clair/sombre) ; tableau de bord (FR clair/sombre, EN).

### Limites connues (à traiter plus tard)
- [x] ~~Envoi des e-mails synchrone~~ (contrôleur) : inscription, mot de passe oublié et renvoi de validation exécutés après la réponse (`Application::defer` + `fastcgi_finish_request`). La file Redis + worker (phase 2) pourra reprendre ces traitements.
- [x] ~~Verrouillage par adresse e-mail exploitable par un tiers~~ (contrôleur) : compteurs par IP (/64 en IPv6), par couple adresse + IP (5 / 15 min) et plafond global par adresse (20 / h). Un tiers a besoin de plusieurs IP pour bloquer un compte une heure ; à réévaluer avec la 2FA (phase 5).
- [x] ~~Purge des jetons expirés, des comptes jamais validés (7 jours) et rétention du journal d'audit~~ : cron (phase 2, `cron/purge.php`).
- [x] Rétention des journaux applicatifs : rotation quotidienne, purge automatique au-delà de `LOG_RETENTION_DAYS` (30). Preuve : `LoggerRetentionTest`.
- [x] Contrôle de configuration au démarrage en production (`ConfigValidator` : APP_URL https, clés, SMTP, cookie Secure, inactivité ≤ 30 min, PHP-FPM) ; code retour CLI 1. Preuve : `ConfigValidatorTest`.
- [ ] ⚖️ Textes de l'accueil (promesses de confidentialité) : allégations non prouvées retirées (« Conforme au RGPD », langues, eID au présent) ; relecture juridique avant mise en ligne publique.
- [ ] Pluriels ICU (`MessageFormatter`) avant la phase 8 ; préférence de langue du compte via formulaire POST (phase 5) ; ~~routage par hôte et corps JSON~~ (fait en phase 2) ; URL canoniques (phase 9) ; `maxmemory` + éviction sur l'instance Redis dédiée (phase 0).
- [ ] `php -S` ne pose pas les en-têtes de sécurité sur les fichiers statiques ; en production : template Hestia (phase 0).
- [x] ~~Dossiers `app/Verification`, `app/Billing`, `cron/`, `deploy/` encore vides~~ (remplis en phase 2).

---

## Phase 2 : API et module  ← REJETÉE par le critique (3 exigences) puis CORRIGÉE (créateur, 2026-09-27), en attente de ré-audit
Preuves : `vendor/bin/phpunit` → OK (après contrôle : 250 tests, 1 491 assertions ; unit 156, intégration 94) ; `php tools/check_translations.php` → 100 % fr et en (265 clés, 0 inconnue) ;
`python3 tools/e2e_demo.py` → 20/20 contrôles (Chromium réel, 4 modes du widget) ; cURL dans `docs/api-tests.md` (section Phase 2) ; captures dans `docs/screenshots/phase-2/` (29 fichiers).
Après corrections de l'audit (suivi : `tasks/reviews/phase-2-critique-suivi.md`) : `vendor/bin/phpunit` → OK (261 tests, 1 611 assertions) ; traductions 100 % (285 clés) ; E2E 33/33 ; captures 32 fichiers.
Dépendance ajoutée : `firebase/php-jwt` **7.2** (autorisée par CLAUDE.md ; la 6.x est visée par l'avis CVE-2025-45769, `composer audit` : aucun avis).

### 2.1 Données et routage
- [x] Migrations 0006 à 0012 (un DDL par fichier) : `projects` (sel d'e-mail propre, secrets de signature chiffrés), `api_keys` (SHA-256 + 4 derniers caractères), `verification_sessions` (e-mail haché + chiffré, code haché), `verifications` (unique par projet × mode × hash), `webhook_endpoints`, `webhook_deliveries` (corps chiffré, réservation, relances), `audit_log` + `project_id` et `metadata` (liste blanche). Preuve : `MigratorTest::testRealMigrationsAreWellFormed`, suite d'intégration sur base recréée.
- [x] Routage par hôte (report phase 1, R12) : `HostMap` (zones `site` = APP_URL, `verify` = VERIFY_URL, `demo` = DEMO_URL), routes liées à une zone (404 ailleurs), domaine nu → 301 vers APP_URL, hôte inconnu → 404. Garde-fou : accolades hors paramètre de route refusées (bug trouvé : `{32}` rendait une route inatteignable). Preuves : `RouterTest`, `ApiSecurityTest::testRoutingByHost`, `testHostMapFromConfiguration`.
- [x] Corps JSON (report R12) : `Request::json()` (Content-Type JSON, 64 Kio max lus depuis `php://input`, objet exigé, profondeur 16) → 415 / 413 / 400. Preuve : `HttpPrimitivesTest::testJsonBodyParsing`.

### 2.2 API
- [x] Auth Bearer `sk_live_` / `sk_test_` (préfixe cohérent avec le mode enregistré), clés révocables, `last_used_at`. 401 + `WWW-Authenticate`. Preuve : `ApiSecurityTest::testMissingInvalidAndRevokedKeysAre401`.
- [x] Limitation de débit : par IP avant authentification (300/min), échecs d'authentification par IP (20/5 min), par clé (600/min, en-têtes `X-RateLimit-*`), sessions par adresse (10/h). Preuves : `testAuthenticationFailuresAreThrottledPerIp`, `testRateLimitPerKeyAndPerIp`, `testSessionCreationIsThrottledPerEmail`, cURL §9.
- [x] Erreurs JSON normalisées `{"error":{"code","message","details"?}}` (codes stables, messages traduits selon Accept-Language) : 400, 401, 402, 404, 405, 413, 415, 422, 429, 500.
- [x] `402 insufficient_credits` préparé : `App\Billing\CreditGateInterface` (+ `UnlimitedCreditGate` jusqu'à la phase 6), production seulement, jamais pour une réutilisation. Preuve : `testInsufficientCreditsIs402ForLiveOnly`.
- [x] `POST /api/v1/sessions` (email, min_age 16/18/21, return_url, lang, external_ref ; champs inconnus refusés), `GET` et `DELETE /api/v1/verifications?email=`. Statuts `not_verified`, `pending`, `failed`, `verified` (un mineur = `verified` + `is_adult:false`). Preuves : `SessionApiTest`, cURL §1 à §8.
- [x] Cloisonnement (pas d'IDOR) : tout est filtré par (projet, mode) ; hash d'e-mail salé par projet ; la production ne voit pas la sandbox. Preuve : `testClientsAndModesAreIsolated`.
- [x] Effacement : vérification, sessions, livraisons de webhooks (corps chiffré contenant l'adresse) et compteur d'échecs. Preuves : `testEraseDeletesResultAndSessions`, `WebhookDeliveryTest::testErasureAlsoDeletesDeliveriesCarryingTheAddress`.

### 2.3 Règles métier
- [x] Réutilisation même client (non expirée, âge couvert : « majeur à 18 » ne vaut pas pour 21) → 200 immédiat, non facturé. Preuve : `testReuseForTheSameClientIsImmediateAndRespectsTheAge`, E2E scénario 5.
- [x] Réutilisation entre clients : seulement si l'utilisateur l'a autorisée lors de sa vérification (case facultative, décochée) ET l'accepte explicitement sur le nouveau site, APRÈS contrôle de son adresse, et si le projet l'admet (`accept_shared`) ; jamais entre sandbox et production ; en sandbox, entre projets d'un même compte seulement (contrôleur) ; la copie n'est pas une source. Preuves : `HostedPageTest::testCrossClientReuseRequiresBothConsents`, `testSandboxSharingStaysWithinTheAccount`.
- [x] Contrôle de l'adresse : code à 6 chiffres (HMAC stocké, 10 min, 5 essais par session puis échec, 3 envois espacés de 60 s ; contrôleur : 10 codes erronés par adresse et par 24 h toutes sessions confondues, 60 saisies par IP et par heure ; e-mail en production, affiché sur la page en sandbox). Preuves : `testCodeGuessingIsCappedPerAddressAcrossSessions`, `testCodeEntryIsThrottledPerIp`, `testWrongCodesFailTheSessionAfterMaxAttempts`, `testCodeResendIsLimited`, `testLiveModeSendsTheCodeByEmailAndNeverShowsIt`.
- [x] Blocage après 5 échecs par adresse (24 h glissantes, par projet et mode) → `429 email_locked`. Preuve : `testEmailIsLockedAfterRepeatedFailures`.
- [x] Journal d'audit sans donnée d'identité (session, mode, méthode, résultat, motif, 4 derniers caractères de clé ; IP tronquée). Preuve : `testAuditLogHasNoIdentityData`.

### 2.4 Retour du résultat
- [x] Redirection `return_url?session_id=&token=` : JWT HS256 (5 min, `aud` = projet, `sub` = session, secret de signature du projet et du mode, sans e-mail) émis au clic (`/s/{id}/return`). Preuves : `testReturnRedirectCarriesAShortSignedToken`, `TokensTest` (alg none/HS512, expiré, autre secret), E2E scénario 4.
- [x] Webhooks `verification.completed` / `verification.failed` : `X-VeriAge-Signature: t=…,v1=…` (HMAC-SHA256 de `t.corps`), `X-VeriAge-Event-Id`, anti-rejeu ±300 s documenté, mis en file dans la transaction qui termine la session. Preuves : `WebhookSignatureTest`, `WebhookDeliveryTest`, cURL §11.
- [x] Worker `bin/worker.php` (systemd `veriage-worker@`) : relances exponentielles (30 s × 2^(n−1) ±10 %, plafond 6 h, 10 tentatives), réservation atomique (plusieurs workers ; contrôleur : réservation prolongée sous un nouveau jeton avant chaque envoi, écritures conditionnées au jeton), idempotence par événement, réveil Redis. Preuves : `testFailuresAreRetriedWithExponentialBackoffThenAbandoned`, `testConcurrentWorkersNeverClaimTheSameDelivery`, `testAStaleWorkerNeitherSendsNorOverwritesADeliveryTakenOver`, E2E (webhooks reçus).
- [x] SSRF (webhooks et return_url) : https seul, pas d'identifiants ni de fragment, domaines autorisés du projet (jokers `https://*.exemple`), aucune adresse privée/réservée/documentation/multicast (y compris IPv4 dans IPv6), résolution DNS vérifiée à CHAQUE envoi puis connexion épinglée sur l'IP contrôlée (anti DNS rebinding), aucune redirection, aucun proxy. Développement : `VERIFICATION_ALLOW_PRIVATE_NETWORK` (http vers adresses locales seulement), refusé en production. Preuves : `UrlGuardTest`, `testDnsRebindingToAPrivateAddressIsBlocked`, `testReturnUrlMustBelongToTheProjectDomains`, `ConfigValidatorTest`.

### 2.5 Méthodes, page hébergée, widget
- [x] `VerificationMethodInterface` (+ `requiresTopLevelWindow()` pour l'eID) et `MockProvider` (sandbox uniquement, résultat choisi : majeur, mineur, échec). Preuve : `VerificationRulesTest`.
- [x] Page `/s/{session}` (hôte verify.) : langue (?lang= > langue de la session > Accept-Language > EN), consentement art. 9 explicite, code e-mail, réutilisation, méthode, résultat ; sans cookie (état en base + URL, jeton signé `_state` sur chaque formulaire, 419 sinon) ; CSP `frame-ancestors 'self'` + domaines du projet, sans X-Frame-Options, COOP `unsafe-none` sur TOUTES ses réponses (bug trouvé : une redirection en COOP same-origin coupait le popup de sa page parente). Preuves : `HostedPageTest` (10 tests), captures `page-hebergee-*`.
- [x] Widget `public/widget/verify.js` (vanilla, sans dépendance) : modes modal, popup, iframe, redirect ; `postMessage` : origine du module + fenêtre ouverte par le widget + identifiant de session vérifiés, la page n'écrit qu'à l'origine parente validée côté serveur ; événements `veriage:opened|completed|failed|closed` ; iframe `allow="camera; fullscreen"` ; plein écran sur mobile ; styles par CSSOM (compatibles CSP stricte du client) ; libellés via `/api/v1/i18n/{code}` ; bascule popup/redirection pour une méthode « premier niveau » (message `escalate`). Preuve : E2E scénarios 1 à 3 et 6.
- [x] Démonstration « Cave du Parc » (`/demo`, hôte DEMO_URL, jamais en production sauf `DEMO_ENABLED=true`) : session sandbox créée par l'API réelle, widget dans les 4 modes, coulisses (appel d'API, événements, confirmation par l'API, webhooks reçus et vérifiés : signature, fenêtre, déduplication), retour avec JWT vérifié. `README.md` : « Démarrage rapide de la démo sur Mac » (validé sur une copie vierge : base neuve, E2E 20/20). Preuves : `DemoTest`, `tools/e2e_demo.py`.
- [x] CLI `bin/project.php` (create, list, rotate-key, rotate-secret, set-origins, add-webhook, demo) en attendant l'espace client. Preuve : `OperationsTest::testProjectAdminValidatesOriginsAndWebhooks`, `testKeyRotationRevokesPreviousKeys`.

### 2.6 Reports de la phase 1 traités
- [x] E-mails par file Redis chiffrée + worker (relances 1, 2, 4, 8 min ; reprise des travaux d'un worker arrêté) au lieu du seul `defer` : `MAIL_QUEUE=redis` (production) / `sync` (développement) ; e-mails de compte compris. Preuves : `OperationsTest` (3 tests).
- [x] Purge par cron (`cron/purge.php`, `deploy/crontab`) : sessions expirées puis supprimées (30 j), vérifications expirées, livraisons terminées (30 j), audit (365 j, `AUDIT_RETENTION_DAYS`), jetons expirés/utilisés, comptes jamais validés (7 j, R2). Preuve : `OperationsTest::testPurgeAppliesRetentionRules`.
- [x] Ré-audit 18 (contexte insensible aux accents), 19 (répétition avec séparateurs), 20 (entrées `$hex[…]` retirées : 55 739 entrées), 22 (problèmes de configuration listés sur STDERR en CLI). Preuves : `PasswordPolicyTest` (3 tests ajoutés), cURL §13. Reco 21 (AllowOverride) : phase 0.

### 2.7 Corrections de l'audit critique (2026-09-27)
- [x] E1 : résultat négatif valable 24 h par défaut (réglable par projet, 0 à 720 h ; 0 : jamais réutilisé), y compris pour la copie partagée ; échec technique jamais réutilisé. Preuve : `ReauditTest` (4 tests), cURL.
- [x] E2 : modale accessible au clavier (piège de focus, `inert`, Échap depuis l'iframe par `postMessage`, focus restauré). Preuve : E2E (7 contrôles).
- [x] E3 : mode iframe sur mobile présenté en superposition `embed=modal`, un seul bouton de fermeture. Preuve : E2E.
- [x] Arbitrages : lien à usage unique au-delà de 20 codes erronés par adresse en 24 h (tous clients, alerte d'audit) ; jeton de retour émis 10 min après la fin, `jti` consommé une fois et session rattachée au visiteur dans la démo ; limites (IP, projet) lues dans `.env` (300/h, 100/h, 600/min).
- [x] `docs/integration.md` (FR + EN), `docs/rgpd.md` (squelette ⚖️), `min_age` en consultation + `POST /api/v1/verifications/lookup`, `Bearer` insensible à la casse, accessibilité de la page (erreur reliée au champ, états des étapes, titres), popup qui reste ouvert.
- [ ] Reportés : R7 (partiel), R11, R15 (phase 5), R16 (phase 10), R18 (phases 0 et 3) ; voir le suivi.

### Limites connues (phase 2)
- [ ] ⚖️ Textes d'information et de consentement (`module.consent.*`) et case de réutilisation entre sites : relecture juridique (base légale art. 9.2.a, durée de conservation, droits). Points listés dans `docs/rgpd.md`.
- [ ] Aucune méthode réelle en production avant les phases 3 et 4 : une session `sk_live_` affiche « aucune méthode disponible ». La bascule `escalate` (eID en popup) est codée mais ne sera exercée qu'avec l'eID (phase 4).
- [ ] `GET /api/v1/verifications?email=` (contrat du cahier des charges) : l'adresse passe dans l'URL → journaux d'accès du serveur à configurer sans chaîne de requête (phase 0).
- [x] ~~Clé d'idempotence~~ (contrôleur) : en-tête `Idempotency-Key` sur `POST /api/v1/sessions` (24 h, par projet et mode, réponse rejouée + `Idempotent-Replayed`, 422 si autre corps, 409 si en cours, aucune adresse en Redis). Preuve : `SessionApiTest::testIdempotencyKeyReplaysTheSameSession`, cURL §14.
- [x] ~~Rotation d'un secret sans recouvrement~~ (contrôleur) : `rotate-secret --grace-hours=N` (24 h par défaut, 0 à 168) ; l'ancien secret signe encore les webhooks (deux `v1`), migration 0013. Le jeton de retour (JWT) est signé avec le nouveau seul : le client essaie ses deux secrets pendant le recouvrement. Preuve : `WebhookDeliveryTest::testSecretRotationWithOverlapSignsWithBothSecrets`.
- [x] ~~Rotation de `CRYPTO_KEY`~~ (contrôleur) : trousseau versionné (`CRYPTO_KEY_VERSION`, `CRYPTO_PREVIOUS_KEYS`, contrôlés au démarrage), `bin/reencrypt.php` (idempotent). Procédure : `App\Services\KeyRotation`, cURL §14. Preuves : `CryptoTest` (2 tests), `OperationsTest::testCryptoKeyRotationRewritesEveryCiphertext`, `ConfigValidatorTest`.
- [ ] Widget : sans accès à `/api/v1/i18n`, le bouton généré (`data-autoopen="false"`) reste sans libellé (aucune chaîne en dur par règle) ; libellé fourni par l'intégrateur via `data-trigger`.

## Phase 3 : Document d'identité + visage (100 % local)  ← IMPLÉMENTÉE (créateur) puis CONTRÔLÉE (contrôleur, 2026-09-27), en attente d'audit
Preuves : `vendor/bin/phpunit` → OK (343 tests, 2 091 assertions ; unit 225, intégration 118, dont PHP → Python réel) ;
`biometrics/.venv/bin/python -m pytest -q` → 96 passed ; `php tools/check_translations.php` → 100 % fr et en (361 clés) ;
`python3 tools/e2e_capture.py` → 9/9 (Chromium réel) ; cURL dans `docs/api-tests.md` (section Phase 3) ; captures `docs/screenshots/phase-3/` (19).
Aucune dépendance Composer ajoutée. Dépendances Python : `biometrics/requirements*.txt` (versions et sommes SHA-256, licences dans `docs/licences.md`).

### 3.1 Microservice `biometrics/` (Python 3.11, FastAPI + Uvicorn)
- [x] 127.0.0.1 seulement (refus de démarrer sinon), `/docs` désactivé, HMAC mutuel (requête : horodatage ±30 s + nonce unique ; réponse signée et liée au nonce). Preuves : `test_security.py`, `test_api.py`, cURL §1 (401, 404, interface externe refusée).
- [x] Modèles téléchargés une fois par `scripts/fetch_models.sh` (URL figées, SHA-256 imposé, rejet prouvé) : YuNet (MIT), SFace (Apache-2.0), MediaPipe Face Landmarker (Apache-2.0), `mrz.traineddata` OCR-B (BSD-3). InsightFace exclu. `docs/licences.md`.
- [x] venv `biometrics/.venv` (ignoré), `pip install --require-hashes` prouvé dans un venv neuf ; MediaPipe en `--no-deps` (conflit opencv-contrib, documenté).
- [x] Images en mémoire : base64 strict, JPEG/PNG par signature, dimensions lues dans l'en-tête avant décodage, plafond OpenCV ; Tesseract par stdin/stdout (aucun fichier : test strace). Réponse = 6 champs, erreurs = codes stables, journaux sans donnée (test).
- [x] MRZ : localisation (morphologie), redressement, 3 binarisations, 180°, TD1/TD2/TD3, 7-3-1 + composite, correction des confusions guidée par les chiffres de contrôle, dates inconnues prudentes. Preuves : 19 vecteurs partagés PHP/Python, OCR sur cartes et passeport synthétiques (inclinés, flous, retournés, chiffre falsifié refusé).
- [x] Visage : YuNet + SFace, plus grand visage (image fantôme belge ignorée). Même personne 0,97 / autre personne < 0,1 sur images de test.
- [x] Liveness : défis tirés côté serveur (ordre aléatoire gauche/droite/cligner), fenêtre par défi, ordre, aucun mouvement contraire, durée minimale, même visage (SFace), un seul visage ; lacet nez/joues (une photo plane pivotée échoue : test), EAR pour les paupières ; moiré (modeste, limites documentées).
### 3.2 PHP
- [x] `LocalBiometricsProvider` (via `VerificationMethodInterface`, sans refonte), `BiometricsClient` + transport cURL (boucle locale, sans proxy, délais), réponse hors contrat rejetée en bloc. `MockProvider` inchangé en sandbox.
- [x] Seuils dans `.env` (acceptation 0,40, revue 0,30, liveness), validés au démarrage en production (`ConfigValidator`). Sous le seuil : échec ou revue manuelle selon le projet (`set-below-threshold`) ; file `manual_reviews` sans image, décision `bin/review.php`, webhook à la décision, expiration par cron.
- [x] `AgeCalculator` PHP (anniversaire le jour même, 29 février → 1er mars), même règle que Python vérifiée sur les vecteurs MRZ ; document expiré refusé. (Classe PHP `Mrz` supprimée par le contrôleur : code mort, voir arbitrage ci-dessous.)
- [x] Envoi : chiffré AES-256-GCM dans le navigateur (clé par capture, AAD), défi à usage unique, délai minimal serveur, horodatages cohérents, tirages bornés (3), tailles/types bornés, jamais écrit par l'application ; `cron/purge_tmp.php` toutes les 15 min (reco R18).
### 3.3 Front
- [x] `capture.js` (vanilla) : recto/verso (ou page passeport), cadre, netteté/luminosité, aperçu et reprise ; fichier en secours pour les documents seulement ; selfie en direct avec consignes et annonces ARIA ; consentement art. 9 dédié ; FR + EN ; bandeau sandbox exact (analyse réelle).
### 3.4 Reports
- [x] N1 (`/confirm` d'une session close → page de la session), N2 (`last_session` dans `not_verified`). Preuves : `DocumentCaptureTest`, cURL §3.
### 3.5 Déploiement
- [x] `deploy/systemd/veriage-biometrics.service` (utilisateur dédié, ProtectSystem=strict, PrivateTmp, NoNewPrivileges, sans capacités, sans réseau sortant, LimitCORE=0) ; procédure README (venv dédié, pool PHP-FPM, retour arrière).
### Limites connues (phase 3)
- [ ] ⚖️ Seuils à calibrer sur données réelles (taux d'erreur, biais) ; données d'entraînement des modèles ; revue humaine sans image = revue sur scores seulement (`docs/rgpd.md`).
- [x] ~~Contradiction du cahier : « chiffres de contrôle aussi en PHP » vs réponse sans date de naissance.~~ Arbitré par l'orchestrateur : la MRZ ne sort jamais du microservice (minimisation). Le contrôleur a supprimé la classe PHP `Mrz` (jamais appelée hors de ses tests) ; `AgeCalculator` reste (eID, phase 4), sa règle est testée sur les vecteurs partagés.
- [ ] Liveness basique : ne résiste pas à une caméra virtuelle pilotée (l'E2E le démontre), pas de certification ISO 30107-3 ; moiré calibré sur images synthétiques seulement.
- [ ] Analyse synchrone (≈ 2,5 s mesurées) dans la requête PHP ; file + worker si charge (phase 10). Nonces anti-rejeu en mémoire : un seul processus Uvicorn. (Contrôleur : attente bornée à 10 s d'une place libre, budget OCR de 20 s, panne technique = session laissée ouverte ; voir `tasks/reviews/phase-3-controle.md`.)
- [ ] Piste « estimation de l'âge par le visage » : non traitée (aucun modèle à licence commerciale validé).

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
