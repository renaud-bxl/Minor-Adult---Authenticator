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
