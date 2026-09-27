# Phase 1 : rapport du contrôleur

Date : 2026-09-27. Base contrôlée : commit `876931d` (créateur). Modifications du contrôleur non commitées.

Périmètre relu : tout `app/`, `config/`, `database/migrations/`, `lang/fr`, `lang/en`, `public/`, `bin/`,
`tools/`, `tests/`, `README.md`, `docs/api-tests.md`, `.env.example`, au regard de `CLAUDE.md`,
`tasks/lessons.md`, `tasks/todo.md` et `docs/cahier-des-charges.md`.

Appréciation générale : fondations de bonne qualité (requêtes préparées partout, `e()` systématique,
CSP réellement sans inline, jetons SHA-256 à usage unique consommés atomiquement, Argon2id, sessions
JSON en mode strict, journaux expurgés, i18n propre et outillée). Les problèmes trouvés portent surtout
sur des cas limites de sécurité, pas sur la conception.

## Problèmes trouvés et corrigés

| # | Gravité | Emplacement (avant correction) | Problème | Correction |
|---|---|---|---|---|
| 1 | Élevée | `PasswordResetController.php:33-35`, `AuthService.php:152-165` | Énumération de comptes par le temps de réponse : pour une adresse inconnue, « mot de passe oublié » répondait sans rien faire ; pour une adresse connue, jeton + audit + envoi SMTP synchrone (plusieurs centaines de ms en production). Même biais, plus faible, à l'inscription. | `Application::defer()` / `runDeferred()` : le traitement complet (recherche, jeton, e-mail) s'exécute après la réponse ; `public/index.php` appelle `fastcgi_finish_request()` (PHP-FPM) avant. Appliqué à l'inscription, au mot de passe oublié et au renvoi du lien de validation. Échec d'une tâche : journalisé, les suivantes s'exécutent. |
| 2 | Élevée | `AuthController.php:70-77`, `config/security.php:37` | Verrouillage d'un compte par un tiers : 5 tentatives / 15 min par adresse, toutes IP confondues, suffisaient à bloquer le titulaire depuis une seule machine. | Trois compteurs contrôlés dans l'ordre : IP (30 / 15 min), couple adresse + IP (5 / 15 min, remis à zéro à la connexion réussie), plafond global par adresse (20 / h). Une tentative refusée par un compteur n'est pas décomptée des suivants : une IP n'apporte que 5 tentatives au plafond global. |
| 3 | Moyenne | tous les `throttle(..., $request->ip())` | Contournement du rate limiting en IPv6 : limite par adresse exacte, alors qu'un abonné dispose d'au moins un /64. | `IpAddress::rateLimitKey()` : /64 en IPv6 (adresse complète en IPv4), via `Controller::throttleIp()` et dans les clés de connexion. |
| 4 | Moyenne | `Request.php:112-119` | IP réelle derrière proxy : seul `REMOTE_ADDR` était lu. Correct si Apache restaure l'IP (mod_remoteip de Hestia), mais sans issue sinon : tous les visiteurs auraient partagé l'IP du nginx frontal (rate limiting global = déni de service, audit faux). | `TRUSTED_PROXIES` (IP/CIDR, vide par défaut). `X-Forwarded-For` n'est lu que si `REMOTE_ADDR` est de confiance, de droite à gauche en sautant les proxys de confiance ; les entrées de gauche (forgeables) ne sont jamais crues. `IpAddress::client()`, `IpAddress::matchesAny()`. |
| 5 | Moyenne | `RedisSessionHandler.php:36-41`, `Session.php:137-147` | Résurrection de session : une requête lente ouverte avant une déconnexion (ou une régénération, ou une expiration) réécrivait la session détruite avec `user_id` : la déconnexion ne tuait pas un identifiant volé si une requête concurrente était en vol. | Une session lue pendant la requête n'est réécrite que si elle existe encore (`SET … EX … XX`) ; `Session::save()` renvoie alors `false` et n'émet pas de cookie. Pas de verrou (dernière écriture gagnante, documenté). |
| 6 | Faible | `SetLocale.php:41-51` | `?lang=` traité aussi en POST, avant le contrôle CSRF : un formulaire tiers pouvait modifier la langue mémorisée et la préférence du compte en base sans jeton. | `?lang=` n'est pris en compte qu'en GET/HEAD. |
| 7 | Faible | `Route.php:66` | `$` en PCRE accepte un saut de ligne final : `/en/login%0A` était routé comme `/en/login`, puis la redirection de langue plantait (en-tête `Location` avec `\n`) → 500 et bruit dans les journaux. | Modificateur `D` (`#^…$#uD`) : 404. |
| 8 | Faible | `AuthService.php:65-74` | Bombardement d'e-mails : chaque inscription avec l'adresse d'un tiers lui envoyait un avertissement (10 / h par IP, sans limite par adresse). | Compteur `register_email` (3 / h par adresse) : au-delà, même réponse, aucun traitement. |
| 9 | Faible | `Session.php:118-126` | La durée de vie absolue partait de la première visite anonyme : se connecter avec une session anonyme de 11 h 59 donnait une session authentifiée d'une minute. | `regenerate()` (changement de privilège) remet `_created` à maintenant. |
| 10 | Info | racine du dépôt | `dump.rdb` (instantané Redis) créé dans le dépôt. | Supprimé, ajouté à `.gitignore`. |

## Tests ajoutés (14)

- Unitaires : `RouterTest::testTrailingNewlineDoesNotMatch` ; `SessionTest::testRegenerateRestartsTheAbsoluteLifetime` ;
  `HttpPrimitivesTest::testRateLimitKeyGroupsIpv6By64`, `testClientIpIgnoresForwardedForUnlessProxyIsTrusted`, `testCidrMatching`.
- Intégration : `AuthFlowTest::testDistributedGuessingHitsThePerAddressCeiling`, `testIpv6ClientsAreThrottledPerSlash64`,
  `testSpoofedForwardedForIsIgnored`, `testRepeatedSignUpsDoNotFloodTheOwnerMailbox`, `testForgotPasswordWorkRunsAfterTheResponse` ;
  `HttpTest::testEncodedNewlineInPathIs404`, `testLangParameterIsIgnoredOnUnsafeMethods`, `testFailingDeferredTaskIsLoggedWithoutBreakingOthers` ;
  `RedisSessionHandlerTest::testAConcurrentRequestCannotResurrectADestroyedSession`.
- Modifié : `AuthFlowTest::testSixthLoginAttemptIsThrottled` (le titulaire n'est plus bloqué depuis une autre IP).
- `tests/Support/HttpClient` exécute les traitements reportés après chaque requête, comme `public/index.php`.

## Points vérifiés sans anomalie

- SQL : uniquement des requêtes préparées (`EMULATE_PREPARES=false`), aucun identifiant dynamique.
- XSS : toutes les vues et e-mails HTML passent par `e()` ; e-mails texte non échappés (text/plain, voulu).
  Aucun `style=`/script inline sur le site (styles en ligne uniquement dans les e-mails, hors CSP).
- Redirections : `Response::redirect` refuse URL absolue, `//` et `\` ; chemins toujours issus de routes connues.
- Jetons : 256 bits, SHA-256 en base, consommation atomique (`UPDATE … used_at IS NULL`), `peek` sans consommation en GET,
  révocation des précédents, CSRF comparé par `hash_equals`, rotation à la connexion.
- Sessions : identifiant 256 bits, mode strict, `__Host-`, HttpOnly, SameSite=Lax, JSON (pas d'`unserialize`), aucune session anonyme inutile.
- En-têtes : CSP sans inline, HSTS, nosniff, XFO, Referrer-Policy `no-referrer` (pas de fuite des URL de jetons), COOP/CORP, `no-store`.
- RGPD : journaux sans e-mail ni IP (expurgation en défense en profondeur, message des `PDOException` écarté), audit avec IP /24 ou /48.
- i18n : aucune chaîne d'interface en dur, 24 dossiers `lang/`, repli EN, `hreflang` + `x-default`, `Vary`, API i18n (ETag/304, CORS), `check_translations` à 100 %.
- Fuzzing rapide (chemins encodés, `%00`, `%FF`, tableaux en query/body, Accept-Language de 45 Ko, OPTIONS, HEAD, `If-None-Match: *`) : aucun 500.

## Améliorations proposées, non faites (et pourquoi)

1. **Lien de validation consommé par les scanners de liens** (Outlook Safe Links, antivirus) : le GET `/verify-email` consomme le jeton ;
   si un robot le suit d'abord, l'adresse est bien validée mais l'utilisateur voit « lien invalide ». Piste : page de confirmation en POST,
   ou message de succès si le jeton est déjà utilisé et l'adresse vérifiée. Choix d'UX à trancher, pas un défaut de sécurité.
2. **Préférence de langue modifiée par un GET** (`?lang=` connecté) : effet de bord mineur exploitable en CSRF (changer la langue d'un compte).
   Conforme au cahier des charges (« mémorisé comme préférence du compte ») ; à reconsidérer si l'espace client (phase 5) offre un réglage explicite.
3. **Verrou de session** : pas de verrou Redis (écritures concurrentes : la dernière gagne). Suffisant pour l'espace client actuel ; à revoir
   si des requêtes AJAX parallèles écrivent en session.
4. **File de traitements** : `defer()` suffit tant qu'il n'y a pas de worker ; la phase 2 (file Redis + systemd) peut reprendre les mêmes closures
   sous forme de messages, avec relances.
5. **Purge** des jetons expirés et rétention du journal d'audit : cron à prévoir (ajouté aux limites connues de `tasks/todo.md`).
6. **Sélecteur de langue** : perd la query string (ex. jeton sur la page de réinitialisation). Volontaire pour ne pas recopier le jeton dans
   des liens ; l'utilisateur rouvre le lien reçu.
7. **Mesure de timing en local** : `php -S` n'a pas `fastcgi_finish_request()`, le client attend donc la fin des traitements reportés.
   Le gain n'est mesurable qu'en PHP-FPM (phase 0) ; l'absence de travail pendant la requête est prouvée par test d'intégration.

## Résultats exacts

```
vendor/bin/phpunit --testsuite unit         OK (85 tests, 192 assertions)
vendor/bin/phpunit --testsuite integration  OK (33 tests, 251 assertions)
vendor/bin/phpunit                          OK (118 tests, 443 assertions)   (créateur : 104 tests, 366 assertions)
php tools/check_translations.php            fr 100.0 % (135/135) OK, en 100.0 % (135/135) OK ; 129 clés citées, 0 inconnue ; SUCCÈS
```

cURL (`php -S`, PHP 8.4, MariaDB 10.11, Redis 7) : en-têtes de sécurité présents sur `/fr/` ; POST sans jeton → 419 ;
POST `/fr/login?lang=en` sans jeton → 419 ; 6 tentatives de connexion avec un `X-Forwarded-For` différent à chaque fois →
422 ×5 puis 429 (`Retry-After: 899`) ; inscription → 302, compte créé, e-mail de validation écrit dans la boîte d'envoi.

Captures : l'interface n'a pas changé (aucune vue, CSS ni JS modifiés) ; `docs/screenshots/phase-1/` reste valable.

---

## Contrôle des corrections (commit 264c85f, réponse au rapport critique)

Base : `git diff b035f16..264c85f`, `tasks/reviews/phase-1-critique.md`, `tasks/reviews/phase-1-critique-suivi.md`.

### Exigences bloquantes

| # | Exigence | Verdict | Vérification |
|---|---|---|---|
| 1 | Mots de passe courants, compromis, contextuels, triviaux | **Satisfaite** | Rejouée à l'inscription : `aaaaaaaaaaaa`, `123456789012`, `password1234`, `Password1234!`, `azertyuiopqs`, `Brasserie du Lac 2026` (raison sociale « Brasserie du Lac ») → 422 ; `quiet river stones at dawn` → 302. Dichotomie vérifiée exhaustivement : 55 741 entrées toutes trouvées, 55 741 quasi-entrées (suffixe `\x01`) toutes absentes, 0,017 ms par recherche. |
| 2 | Inactivité 30 min | **Satisfaite** | Défaut 30 (config, `.env.example`), TTL Redis réel testé, refus de démarrer en production au-delà. |
| 3 | Contrastes WCAG 1.4.11 | **Satisfaite** | `--field-border` appliqué à `.field input` et au sélecteur ; `--focus` sur `:focus-visible` ; `FrontendContrastTest` calcule les ratios à partir des jetons réels du CSS (deux thèmes). Captures focus / erreur / sombre présentes. |
| 4 | Validation de la raison sociale et des champs texte | **Satisfaite** | `company=Bad\xFF\xFECo` → 422 (plus de faux succès) ; `TextInput` refuse Cc/Cf/Zl/Zp, NFC, espaces compactés ; appliqué aux e-mails. |

### Points surveillés

- **PasswordBlocklist, taille** : 62 % des 142 515 entrées ne pouvaient jamais correspondre (la politique n'y compare que des mots de passe
  de 12 caractères ou plus, ou le « cœur » d'un mot décoré, bordé de lettres, d'au moins 4 caractères : `123456`, `!@#$%^`, `qwerty1`…).
  **Corrigé** : fichier réduit à 55 741 entrées, 1,17 Mo → 451 Ko, protection strictement identique ; le test vérifie désormais le tri **et**
  l'absence d'entrée inatteignable (en une assertion, au lieu d'une par ligne). Régénération mise à jour dans `tasks/todo.md`.
- **PasswordBlocklist, licence** : SecLists est sous MIT, qui impose de reproduire l'avis de copyright ; il manquait. **Corrigé** :
  `resources/security/NOTICE.md` (origine, transformation, texte de la licence).
- **PasswordBlocklist, performance** : recherche dichotomique dans le fichier, sans chargement en mémoire, ~16 lectures, 0,017 ms. Conception
  sobre et adaptée ; rien à changer.
- **PasswordPolicy** : règles claires, messages traduits. Réserve non bloquante : un élément de contexte de 4 caractères (partie locale
  `info@`, `sales@`) interdit tout mot de passe qui le contient (« information… ») ; faux refus possibles mais rares, message explicite.
- **TextInput** : simple, réutilisable (35 lignes), bien placé dans `Core`. Refuser `Cf` écarte aussi le ZWJ des émojis composés : sans
  objet pour une raison sociale.
- **ConfigValidator** : utile et sans secret dans les messages. Petit défaut **corrigé** : si les deux clés étaient vides, il signalait en
  plus « APP_KEY et CRYPTO_KEY doivent être différentes » ; la comparaison n'a plus lieu qu'avec des clés renseignées. L'exigence PHP-FPM
  en production web est cohérente avec `fastcgi_finish_request()` (anti-énumération) et avec HestiaCP.
- **Sessions anonymes** : inchangées (aucune session pour l'accueil ni l'API ; sessions de formulaire à 30 min d'inactivité). Pas de régression.
- **.htaccess** : `CGIPassAuth On` (sous `IfVersion`) plus le repli par réécriture est le schéma usuel, sans effet hors du docroot.
  Point à vérifier en phase 0 : `CGIPassAuth` dans un `.htaccess` exige `AllowOverride AuthConfig` (ou `All`, le cas des templates Hestia) ;
  sinon Apache répondrait 500 sur tout le site. L'exception `/.well-known/` est correcte (les fichiers cachés qu'il contiendrait restent refusés).
- **Autres changements** (idempotence du lien de validation, flash paramétré, rétention des journaux, `Auto-Submitted`, `Hostname`) :
  corrects, testés. Ordre des `use` corrigé dans `Controller.php`.

### Résultats

```
vendor/bin/phpunit --testsuite unit         OK (128 tests, 335 assertions)
vendor/bin/phpunit --testsuite integration  OK (40 tests, 290 assertions)
vendor/bin/phpunit                          OK (168 tests, 625 assertions)
php tools/check_translations.php            fr 100.0 % (138/138) OK, en 100.0 % (138/138) OK ; 132 clés citées, 0 inconnue ; SUCCÈS
```

La baisse du nombre d'assertions (711 → 625) vient du test de la liste, qui contrôlait une entrée sur 997 d'un fichier désormais 2,6 fois plus
court ; l'exactitude de la dichotomie a été vérifiée exhaustivement hors suite (ci-dessus).

Verdict du contrôleur : les 4 exigences bloquantes sont satisfaites, aucune régression constatée ; prêt pour le nouvel audit critique.
