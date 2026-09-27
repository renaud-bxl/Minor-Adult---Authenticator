# Phase 2 : suivi de l'audit critique

Date : 2026-09-27. Agent : créateur. Base : commit `4745f4d` (rapport critique : REJETÉ, 3 exigences
bloquantes), après les corrections du contrôleur (`10909ab`). Modifications non commitées.

## Exigences bloquantes

| # | Exigence | Correction | Preuve |
|---|---|---|---|
| E1 | Un résultat « âge non atteint » était réutilisé 365 jours | Migration `0014` : `projects.negative_ttl_hours` (défaut 24 h, de 0 à 720, `VERIFICATION_NEGATIVE_TTL_HOURS` pour les nouveaux projets, `bin/project.php create --negative-ttl-hours` / `set-negative-ttl`). `VerificationService::resultExpiry()` : validité du projet si le résultat est positif, courte s'il est négatif. Appliqué au résultat direct et à la copie partagée ; `expires_at` identique dans l'API, le webhook, le JWT et la réponse de réutilisation. Avec 0 : jamais réutilisé. Un échec technique n'est jamais mis en cache (inchangé, désormais testé). | `ReauditTest::testNegativeResultExpiresQuicklySoThePersonCanBeReverified`, `testPositiveResultKeepsTheProjectValidity`, `testZeroNegativeTtlNeverReusesANegativeResultAndFailuresAreNeverReused`, `testSharedNegativeCopyAlsoGetsTheShortValidity` ; cURL `docs/api-tests.md` (corrections de l'audit, E1). |
| E2 | Modale du widget inaccessible au clavier | `verify.js` : pendant l'ouverture, `inert` + `aria-hidden` sur les frères de l'overlay, restaurés à la fermeture. Piège de focus : sentinelles avant et après (cadre ↔ bouton), plus un filet `focusin`. Focus rendu à l'élément d'origine. `verify-page.js` : en `embed=modal`, Échap envoie `{type:'close'}` par `postMessage` vers l'origine parente validée ; le widget contrôle l'origine, la fenêtre source et la session. Motif « dialog » : `role=dialog`, `aria-modal`, `aria-label`. | `tools/e2e_demo.py` (7 contrôles : ARIA, `inert`, 30 × Tab et 12 × Maj+Tab confinés, Échap dans l'iframe → `veriage:closed` et modale fermée, focus rendu à `#demo-open`, page réactivée). |
| E3 | Mode iframe sur mobile : deux boutons de fermeture | La présentation (cadre en ligne ou superposition) est décidée AVANT de construire l'URL : `embed=modal` pour toute superposition (modale, ou iframe en plein écran sur mobile), `embed=iframe` seulement pour un cadre en ligne. | E2E « mobile iframe » : `embed=modal` dans l'URL et exactement un bouton de fermeture visible (widget + page) ; capture `demo-10-iframe-plein-ecran-mobile-fr.png`. |

## Arbitrages appliqués

| Réf. | Décision | Mise en œuvre | Preuve |
|---|---|---|---|
| Q1 | Au-delà d'environ 20 codes erronés par adresse en 24 h, tous clients confondus : pas de blocage, mais une preuve renforcée et une alerte | Compteur `verify_code_global` (production seulement ; clé = HMAC global de l'adresse ; `VERIFICATION_GLOBAL_CODE_FAILURES=20`). Au-delà, `sendCode` envoie un **lien à usage unique** (32 caractères base 62, 15 min, stocké haché ; migration `0015` : `proof_kind`) au lieu du code. Le lien ouvre une page de confirmation en POST (les scanners de liens ne le consomment pas). Six chiffres ne valent plus rien. Audit `verification.proof_escalated` et avertissement dans le journal. Plafonds et verrouillage par projet inchangés. | `ReauditTest::testManyWrongCodesAcrossClientsSwitchToASingleUseLinkWithoutBlocking` |
| Q2 | Jeton de retour réutilisable, mais émis pendant une courte fenêtre | `canIssueReturnToken()` : 10 min après la fin de la session (`VERIFICATION_RETURN_TOKEN_WINDOW=600`). Au-delà : résultat affiché sans jeton (`token: null` dans le `postMessage`), `/return` renvoie à la page, bouton de retour masqué. Démo = implémentation de référence : session rattachée au visiteur (cookie `demo_owner` du site de démo) et `jti` consommé une seule fois. Documenté dans `docs/integration.md` §7. | `ReauditTest::testReturnTokenIsOnlyIssuedShortlyAfterTheEnd` ; E2E : `token_replayed` au rechargement, `owner_mismatch` dans un autre navigateur. |
| Q3 | Limites par IP : clés (IP, projet), seuils dans `.env`, compatibles CGNAT | `verify_code_ip` : 300/h par (IP /64, projet) (`RATE_VERIFY_CODE_IP_PER_HOUR`) ; `verify_code_send_ip` : 100/h par (IP, projet) (`RATE_VERIFY_CODE_SEND_IP_PER_HOUR`) ; `verify_page_ip` : 600/min par IP (`RATE_VERIFY_PAGE_IP_PER_MINUTE`). Le middleware de page précède le chargement de la session, d'où la clé IP seule, avec un seuil relevé. | `HostedPageTest::testCodeEntryIsThrottledPerIp` (même IP, autre client non affecté), `testLimitsAreReadFromTheEnvironment` |

## Recommandations traitées

| Réf. | Traitement | Preuve |
|---|---|---|
| R4 | `docs/integration.md` (FR + EN) : flux, clés et modes, sessions, statuts et validités, effacement, webhooks (schéma, vérification PHP et Node.js, deux `v1` pendant une rotation, anti-rejeu, déduplication), JWT (claims, fenêtre, `jti`, rattachement à l'utilisateur), catalogue des codes d'erreur, codes de `details`, `failure_reason`, widget (attributs, événements, CSP, Permissions-Policy, COOP), encodage de l'adresse. | fichier |
| R5 | `min_age` en paramètre de consultation (logique de `covers()`), et `POST /api/v1/verifications/lookup` avec l'adresse dans le corps (champs inconnus refusés). | `ReauditTest::testLookupByAgeAndInTheBody` |
| R6 | Schéma `Bearer` insensible à la casse. | `ReauditTest::testBearerSchemeIsCaseInsensitive` |
| R8 | Erreur de code reliée au champ (`aria-invalid`, `aria-describedby`, `role=alert`) ; étapes « terminée / en cours / à venir » en texte masqué, et coche verte contrastée (`--success-text`) ; `<title>` propre à chaque étape. | `ReauditTest::testCodeErrorIsTiedToTheFieldAndStepsAreAnnounced` ; captures |
| R9 | Popup : plus de fermeture automatique ; « Vous pouvez maintenant fermer cette page » et bouton « Fermer ». | E2E scénario 3 (ouvert 2 s après le résultat, invitation présente) |
| R10 | Mode redirection sans `return_url` (ou fenêtre du jeton close) : même invitation à fermer. | vue `verify/result.php` (`canClose`) |
| R12 | Codes épuisés : message dédié (`module.result.failed_code_text`). | `ReauditTest::testExhaustedCodesShowADedicatedMessage` |
| R13 | `findShared` filtre l'âge en SQL. | `HostedPageTest::testCrossClientReuseRequiresBothConsents` (inchangé, vert) |
| R14 | `min_age` dans `detail` de `veriage:completed` et dans le `postMessage`. | `ReauditTest::testReturnTokenIsOnlyIssuedShortlyAfterTheEnd` |
| R17 | `docs/rgpd.md` : squelette du registre (dont `external_ref` : 30 j, JWT, webhook), AIPD, droits, points ⚖️. | fichier |

## Recommandations non traitées

| Réf. | Raison | Échéance |
|---|---|---|
| R7 (partiel) | `min_age` en chaîne → `invalid` (le catalogue dit « entier parmi 16, 18, 21 ») ; `http://h:8001:80` → `insecure_scheme` et `email[]=` en GET → `required` : messages imprécis mais sans effet sur la sécurité. | Phase 5 (avec l'API de l'espace client) |
| R11 | Avertissements côté intégrateur (origine non autorisée en popup, `data-target` introuvable) : il faudrait des messages dans la console, donc des textes en dur dans le JS, que la règle interdit. Le comportement est documenté (`docs/integration.md` §9). À traiter par un diagnostic affiché en sandbox sur la page hébergée. | Phase 5 |
| R15 | `X-RateLimit-Reset` : exige de prolonger le script Lua du limiteur (horodatage de la plus ancienne tentative). | Phase 5 |
| R16 | Exécution sur un PHP 8.2 réel : aucune image PHP 8.2 dans l'environnement de développement. | Phase 10 (CI) |
| R18 | Unité systemd (`/usr/bin/php8.x`, instance Redis dédiée) et purge toutes les 15 min des fichiers temporaires. | Phases 0 et 3 |

## Résultats

```
vendor/bin/phpunit                           OK (261 tests, 1 611 assertions ; unit 156, intégration 105)
php tools/check_translations.php             fr 100 % (285/285), en 100 % (285/285), 0 clé inconnue
CHROMIUM_PATH=… python3 tools/e2e_demo.py    33/33 contrôles réussis (captures : docs/screenshots/phase-2/, 32 fichiers)
php bin/migrate.php                          0014 et 0015 appliquées (un DDL chacune)
```
