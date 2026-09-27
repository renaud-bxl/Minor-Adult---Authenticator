# Phase 2 : rapport du contrôleur

Date : 2026-09-27. Base contrôlée : commit `3fee3ad` (créateur), diff `0acf602..3fee3ad` (156 fichiers).
Modifications du contrôleur non commitées par lui. Remarque : un commit `e280c65` « WIP phase 2 : sauvegarde
intermédiaire pendant le contrôle » a été créé pendant le contrôle par un autre processus que le contrôleur ;
il contient une partie des corrections ci-dessous (état intermédiaire), le reste est dans l'arbre de travail.

Périmètre relu : tout le diff (`app/`, `config/`, migrations 0006 à 0012, `public/widget/verify.js`,
`public/assets/js/verify-page.js`, vues du module et de la démo, `bin/`, `cron/`, `deploy/`, tests, `lang/`,
`docs/api-tests.md`, `.env.example`), au regard de `CLAUDE.md`, `tasks/lessons.md`, `tasks/todo.md` et du
cahier des charges (sections 3 et 7).

Appréciation générale : phase de très bonne qualité. Cloisonnement (projet, mode) systématique, transitions
d'état atomiques, SSRF traitée sérieusement (résolution contrôlée puis connexion épinglée), page hébergée
réellement sans cookie, `postMessage` strict dans les deux sens, CSP sans inline y compris pour le widget.
Les défauts trouvés sont une vraie faille de concurrence dans le worker et des durcissements.

## Problèmes trouvés et corrigés

| # | Gravité | Emplacement (dans `3fee3ad`) | Problème | Correction |
|---|---|---|---|---|
| 1 | Élevée | `Webhooks/WebhookDispatcher.php:385-393`, `Models/WebhookDeliveryRepository.php:57-88` | Verrou de concurrence du worker insuffisant : un lot de 20 livraisons est réservé 60 s (`2 × (10 + 5) + 30`), alors que chaque envoi peut durer 15 s. Au-delà de 4 livraisons lentes, la réservation des suivantes expire, un second worker les reprend : **double envoi**. Et `markDelivered` / `markAttemptFailed` (`WHERE id = ?`) de l'ancien worker écrasent ensuite l'état écrit par le second (livrée → re-planifiée, compteur faussé). | Réservation par bail : `renewLock()` prolonge la réservation d'UNE livraison juste avant son envoi, sous un **nouveau** jeton (MariaDB ne compte que les lignes réellement modifiées : prolonger dans la même seconde renvoyait 0) ; si elle a été reprise, on n'envoie pas. `markDelivered` / `markAttemptFailed` sont conditionnés au jeton détenu. |
| 2 | Moyenne | `Verification/VerificationService.php:353-386` | Force brute du code à 6 chiffres limitée à 5 essais **par session** seulement : en ouvrant des sessions en série (10/h par adresse, 4 essais chacune pour ne pas déclencher le blocage), ~960 essais/jour par adresse et par site. Enjeu : s'approprier la vérification partagée d'un tiers sur un autre site. Aucune limite de saisie par IP (hors 120 req/min sur la page). | Compteur `verify_code_email` : 10 codes erronés par adresse (projet + mode) sur 24 h toutes sessions confondues ; au-delà, la session échoue (`code_attempts_exceeded`, compte pour le blocage de l'adresse). Compteur `verify_code_ip` : 60 saisies par IP (/64) et par heure, refusées sans consommer d'essai (`notice=throttled`). Les codes soumis sur une session close ou déjà validée ne comptent pas. |
| 3 | Faible | `Models/VerificationRepository.php:34-41`, `VerificationService.php:399` | Réutilisation entre clients en sandbox ouverte à tous les comptes : en sandbox le code est affiché à l'écran, donc le contrôle de l'adresse ne prouve rien. N'importe quel client pouvait sonder (et copier) une vérification de test partagée d'un autre client pour une adresse quelconque (les testeurs utilisent souvent de vraies adresses). | En sandbox, `findShared()` se limite aux projets du **même compte** (le client peut toujours tester la fonction avec deux projets). Production inchangée. |
| 4 | Faible | `Core/ConfigValidator.php` | La démonstration activée en production (`DEMO_ENABLED=true`) acceptait une clé `sk_live_` : sessions de production créées par une page publique. | Refus au démarrage si `DEMO_API_KEY` n'est pas une clé `sk_test_`. |
| 5 | Info | `public/widget/verify.js:282, 317` | `data-target` / `data-trigger` invalides (sélecteur CSS mal formé) : exception non rattrapée dans la page du client (pas de XSS : sélecteurs seulement). | `find()` : sélecteur invalide ignoré. |

## Limites du créateur traitées

| Limite | Correction |
|---|---|
| Pas d'`Idempotency-Key` sur `POST /api/v1/sessions` | En-tête `Idempotency-Key` (1 à 255 caractères `[A-Za-z0-9_.:-]`), 24 h, clés propres au projet et au mode. Même clé + même corps : réponse d'origine rejouée, `Idempotent-Replayed: true`. Autre corps : `422 idempotency_key_reused`. Requête d'origine en cours (réservation `SET NX`, 60 s) : `409 idempotency_in_progress` + `Retry-After: 1`. Erreurs non mémorisées. Redis ne garde que l'empreinte HMAC de la requête et l'identifiant de session : le corps est reconstruit depuis la session (aucune adresse en Redis) ; session effacée entre-temps (art. 17) : nouvelle requête. Les rejeux ne consomment pas les limites de débit. |
| Rotation du secret de signature immédiate | Migration `0013` (un seul `ALTER TABLE`) : `previous_secret_{test,live}_enc` (chiffré, AAD distincte) et `_until`. `rotate-secret --grace-hours=N` (24 h par défaut, 0 = révocation immédiate, 168 h max) : l'ancien secret signe encore les webhooks (`t=…,v1=nouveau,v1=ancien`) ; courant et ancien lus dans une même ligne. Le JWT de retour est signé avec le nouveau seul (documenté). |
| Pas de rotation versionnée de `CRYPTO_KEY` | Trousseau dans `Crypto` : l'octet de version (déjà présent) désigne la clé ; chiffrement avec la clé courante (`CRYPTO_KEY_VERSION`, 1 à 255), anciennes clés en lecture seule (`CRYPTO_PREVIOUS_KEYS=1:base64:…`), `needsReencryption()`. Contrôle au démarrage en production (format, versions distinctes, clé courante absente des anciennes, aucune clé dans les messages). `bin/reencrypt.php` / `App\Services\KeyRotation` : réécrit secrets de projets (courants et en recouvrement), e-mails des vérifications et des sessions, corps des webhooks ; idempotent, `UPDATE … WHERE colonne = ancienne valeur` (jamais d'écrasement d'une écriture concurrente). Procédure en 3 étapes dans `docs/api-tests.md` §14. |

## Tests ajoutés (10)

- Intégration : `WebhookDeliveryTest::testAStaleWorkerNeitherSendsNorOverwritesADeliveryTakenOver`,
  `testSecretRotationWithOverlapSignsWithBothSecrets` ; `HostedPageTest::testSandboxSharingStaysWithinTheAccount`,
  `testCodeGuessingIsCappedPerAddressAcrossSessions`, `testCodeEntryIsThrottledPerIp` ;
  `SessionApiTest::testIdempotencyKeyReplaysTheSameSession` ; `OperationsTest::testCryptoKeyRotationRewritesEveryCiphertext`.
- Unitaires : `CryptoTest::testKeyringDecryptsOldVersionsAndEncryptsWithTheCurrentOne`, `testKeyringConfiguration` ;
  `ConfigValidatorTest::testCryptoKeyringAndDemoKeyAreChecked`.
- Modifiés : `ModuleTestCase::createProject()` (compte existant facultatif) ; `testCrossClientReuseRequiresBothConsents`
  (projets du même compte, conséquence du point 3).

## Points vérifiés sans anomalie

- **IDOR** : toute lecture/écriture de l'API filtrée par (projet, mode) ; `load()` de la page par identifiant public
  de 190 bits ; livraisons liées au projet et au mode de l'endpoint ; idempotence cloisonnée. Effacement complet
  (vérification, sessions, livraisons, compteur d'échecs).
- **Sel par client** : `email_salt` 32 octets aléatoires par projet, HMAC poivré par `APP_KEY` ; le hash partagé
  (sel global) n'existe que sur consentement et disparaît avec l'effacement.
- **Chiffrement** : AES-256-GCM, AAD liant chaque chiffré à sa ligne (session, projet + mode + hash, événement,
  secret + mode), file Redis chiffrée.
- **JWT** : HS256 figé (`new Key($secret, 'HS256')`, `alg:none`/HS512 refusés, testé), `exp` 5 min, `aud` = projet,
  `sub` = session vérifiés, aucune adresse.
- **Webhooks** : HMAC-SHA256 de `t.corps`, `hash_equals` sans sortie anticipée, fenêtre ±300 s, `event_id`
  constant entre relances, unicité (endpoint, événement), relances exponentielles plafonnées, abandon final.
- **SSRF / DNS rebinding** : https seul, pas d'identifiants ni de fragment, plages réservées IPv4/IPv6 (dont
  IPv4 mappée, NAT64, 6to4, Teredo), TOUTES les adresses résolues contrôlées à chaque envoi, `CURLOPT_RESOLVE`
  sur l'adresse contrôlée, ni redirection ni proxy ; mode développement refusé en production.
- **Page hébergée** : aucun `Set-Cookie` (pas de `StartSession` sur l'hôte verify.), `frame-ancestors 'self'` +
  domaines du projet sur toutes les réponses, pas de `X-Frame-Options`, `Referrer-Policy: no-referrer`, jeton
  `_state` HMAC, 303 après POST, `e()` partout.
- **verify.js / verify-page.js** : `event.origin === base` ET `event.source` = fenêtre ouverte ET `session_id`
  attendu ; la page n'émet que vers l'origine parente validée côté serveur ; aucun `'*'` ; `data-*` validés
  (motif de session, liste de modes, langue `[a-z]{2}`) ; libellés en `textContent`.
- **Code** : `random_int`, HMAC stocké, 10 min, consommation atomique, comparaison dans l'`UPDATE` sur un HMAC à clé
  secrète (fuite temporelle sans intérêt exploitable).
- **Consentement** : case de partage facultative et décochée ; réutilisation proposée seulement après contrôle de
  l'adresse, acceptée par un bouton explicite, jamais automatique, jamais entre modes ; la copie n'est pas une source.
- **Démo** : zone d'hôte enregistrée seulement si `demo_enabled` (faux en production sauf `DEMO_ENABLED=true`).

## Propositions non faites (et pourquoi)

1. **Plafond de codes erronés global (tous clients)** plutôt que par projet : bornerait aussi un attaquant qui
   passe par plusieurs sites, mais permettrait à un tiers de bloquer une adresse partout (déni de service).
   Choix produit à trancher ; le plafond par projet suit la règle de blocage existante.
2. **Jeton de retour à usage unique** : `/s/{id}/return` réémet un JWT à chaque clic ; le détenteur de l'identifiant
   de session peut donc en obtenir d'autres. Le JWT ne sert qu'à l'affichage (le résultat fait foi par webhook/API)
   et porte un `jti` : au client de le consommer une fois. À documenter dans `docs/integration.md` (phase 9).
3. **`_state` non lié au navigateur** : l'identifiant de session reste la capacité (comme le cahier des charges
   l'impose sans cookie). Acceptable ; une liaison forte demanderait un cookie tiers.
4. **`verify_code_ip` = 60/h** peut gêner un grand NAT (école) ; valeur à ajuster avec les métriques réelles.
5. **`CURLOPT_PROTOCOLS`** est déprécié au profit de `CURLOPT_PROTOCOLS_STR` (libcurl ≥ 7.85) ; sans effet tant que
   PHP 8.2 est la cible, à reprendre au passage à PHP 8.3+. `dns_get_record` n'a pas de délai propre (résolveur système).
6. **Travaux Redis lors d'une rotation de `CRYPTO_KEY`** : non réécrits (durée de vie de quelques minutes) ; la
   procédure impose d'attendre que la file soit vide avant de retirer l'ancienne clé.

## Résultats

- `vendor/bin/phpunit` → **OK (250 tests, 1 491 assertions)** ; unit 156 tests / 558 assertions, intégration
  94 tests / 933 assertions (avant contrôle : 240 / 1 395).
- `php tools/check_translations.php` → **100 % fr (265/265) et en (265/265)**, 259 clés citées, 0 inconnue.
- `php bin/migrate.php` (base de développement) → `0013_alter_projects_add_previous_secrets.sql` appliquée ; worker
  `--id=dev` redémarré sur le nouveau code.
- `python3 tools/e2e_demo.py` (Chromium réel, `CHROMIUM_PATH=/opt/pw-browsers/chromium-1194/…`, captures dans un
  dossier temporaire) → **20/20 contrôles réussis** (4 modes, webhooks livrés par le worker redémarré, JWT, CSP).
  L'interface n'a pas changé : captures `docs/screenshots/phase-2/` conservées.
- cURL rejoués (serveur 127.0.0.1:8000) : idempotence 201 puis 201 + `Idempotent-Replayed: true`, corps identiques,
  autre corps → 422 `idempotency_key_reused` ; page `/s/…?embed=iframe&origin=http://127.0.0.1:8001` → 200,
  `frame-ancestors 'self' http://127.0.0.1:8001`, COOP `unsafe-none`, aucun `Set-Cookie`, aucun `X-Frame-Options` ;
  origine non autorisée → aucun `data-parent-origin` ; clé invalide → 401 ; `rotate-secret --grace-hours=24` → OK,
  `--grace-hours=500` → code 1 ; `bin/reencrypt.php` → 0 réécriture (aucune rotation en cours). Détails :
  `docs/api-tests.md` §14.
- Leçon ajoutée à `tasks/lessons.md` (bail de réservation par élément, écritures conditionnées au jeton).
