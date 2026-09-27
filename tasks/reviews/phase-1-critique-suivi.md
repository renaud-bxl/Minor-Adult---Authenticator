# Phase 1 : suivi de l'audit critique

Date : 2026-09-27. Agent : créateur. Base : commit `2faa514` (rapport critique : REJETÉ, 4 exigences bloquantes).
Modifications non commitées.

## Exigences bloquantes

| # | Exigence | Correction | Preuve |
|---|---|---|---|
| 1 | Mots de passe courants, compromis, contextuels, triviaux (ASVS V2.1.7) | `PasswordBlocklist` : 142 515 entrées locales (NCSC 100k + xato 100k, SecLists, MIT), recherche dichotomique dans le fichier trié (≈ 0,02 ms, aucune liste en mémoire, aucun appel externe). `PasswordPolicy` : UTF-8 valide ; suites (chiffres, alphabet, claviers QWERTY/AZERTY/QWERTZ, à l'endroit et à l'envers), motifs répétés, moins de 5 caractères distincts ; liste de blocage, y compris mot courant entouré de chiffres/symboles ; contexte : nom du service (`APP_NAME`, « veriage », premier label de `APP_DOMAIN`), e-mail complet, partie locale, nom de domaine, raison sociale et ses mots (≥ 4 caractères). Appliquée à l'inscription et à la réinitialisation (e-mail + raison sociale du compte). `PasswordHasher` : NFC avant hachage et vérification. Messages FR/EN : `password_trivial`, `password_common`, `password_contextual` (`password_same_as_email` retiré, couvert par le contexte). | `PasswordPolicyTest` (25 cas, dont exactitude de la dichotomie sur 1 entrée sur 997 + tri par octets), `InputHardeningTest::testCommonAndContextualPasswordsAreRejected`, `testResetRefusesContextualPassword` |
| 2 | Inactivité de session 30 min (ASVS V3.3.2) | Défaut 30 dans `config/security.php` et `.env.example` ; `ConfigValidator` refuse le démarrage en production au-delà de 30. | `InputHardeningTest::testSessionIdleTimeoutIsThirtyMinutes` (TTL Redis réel ∈ ]1790, 1800]) |
| 3 | Contrastes WCAG 1.4.11 | Jeton `--field-border` pour les contrôles (champs, sélecteur de langue) : #767f94 (4,01:1 sur surface, 3,74:1 sur fond) / sombre #7684a3 (4,44:1 / 4,90:1). `--focus` : #b45309 (≥ 4,47:1 sur fond, surface et surface atténuée) / sombre #fbbf24 (≥ 8,98:1). La bordure décorative des cartes reste `--border`. | `FrontendContrastTest` (calcul WCAG sur les jetons du CSS, deux thèmes) ; captures `etat-focus-*`, `etat-erreur-inscription-*`, `*-sombre` |
| 4 | Validation de la raison sociale et des champs texte | `App\Core\TextInput::normalize()` (réutilisable) : UTF-8 valide, NFC, refus Cc/Cf/Zl/Zp (bidi, invisibles, sauts de ligne, NUL…), espaces compactés. Appliqué à la raison sociale et à tous les champs e-mail (inscription, connexion, mot de passe oublié) ; `forDisplay()` pour réafficher une saisie refusée en UTF-8 valide. Erreur 422 `site.validation.text_invalid` (FR/EN). Le mot de passe exige aussi un UTF-8 valide. | `TextInputTest` (13 cas refusés), `InputHardeningTest::testInvalidCompanyNamesAreRejectedWith422AndNothingIsCreated` (non-UTF-8, U+202E + contrôles, CRLF, U+200B : 422, aucun compte, aucun e-mail, aucune tâche reportée en échec), `testCompanyNameIsStoredNormalized`, `testInvalidEmailBytesAreRejected` |

## Recommandations traitées

| Réf. | Traitement | Preuve |
|---|---|---|
| R1 | Lien de validation déjà suivi (scanner de liens) : succès idempotent si l'adresse est vérifiée (`UserTokenRepository::findUsed`). | `AuthFlowTest` (second clic → 302 + message de succès) |
| R3 | Aucune session créée pour un visiteur anonyme hors formulaire (déjà le cas : accueil, API) ; sessions anonymes de formulaire limitées à 30 min d'inactivité (exigence 2). | `HttpTest::testAnonymousHomeDoesNotCreateASession`, TTL ci-dessus |
| R6 | `site.forgot.sent` : durée `{minutes}` issue de `password_reset_ttl` (messages flash paramétrés). | `check_translations` 100 % |
| R7 | `ConfigValidator` au démarrage en production (APP_URL https, APP_DOMAIN, clés 32 octets et distinctes, SMTP, MAIL_FROM_ADDRESS, cookie Secure, inactivité ≤ 30 min, PHP-FPM pour les requêtes web) ; les messages ne contiennent aucun secret ; code retour CLI 1 (`ErrorHandler`). | `ConfigValidatorTest` ; `APP_ENV=production php bin/migrate.php --status` → refus, `exit=1` |
| R8 | `PHPMailer::$Hostname` = `APP_DOMAIN` (Message-ID, HELO) ; en-tête `Auto-Submitted: auto-generated`. | revue du code |
| R9 | Rotation quotidienne existante + purge automatique au-delà de `LOG_RETENTION_DAYS` (30) ; `PDOException` : SQLSTATE réel et code SGBD conservés, message toujours écarté. | `LoggerRetentionTest`, `HttpPrimitivesTest::testLoggerRedactsPersonalData` |
| R10 | Allégations retirées ou mises au conditionnel (« Conforme au RGPD », langues UE et eID présentées comme prévues, « serveurs en Europe » retiré) ; relecture juridique ⚖️ inscrite dans `tasks/todo.md`. | `lang/{fr,en}/site.php` |
| R11 (partiel) | `.htaccess` : `.well-known/` servi ; `CGIPassAuth On` (Apache ≥ 2.4.13) + repli `E=HTTP_AUTHORIZATION`, lu via `REDIRECT_HTTP_AUTHORIZATION` par `Request`. | `HttpPrimitivesTest::testAuthorizationHeaderIsRecoveredBehindApacheRewrite`, `testHtaccessDeniesDotfilesButNotWellKnown` |
| R13 | Sélecteur de langue : nom accessible « FR Choisir la langue » (texte visible inclus, WCAG 2.5.3) ; erreur de connexion reliée aux champs (`aria-invalid`, `aria-describedby`) ; bouton « Afficher » : libellé seul, `aria-pressed` retiré. | revue du code, captures |
| R15 | Captures : 43 fichiers (voir `tasks/todo.md` 1.7). | `docs/screenshots/phase-1/` |
| R16 | Tests ajoutés : liste de blocage, TTL, encodage/contrôles, `MAIL_DRIVER=log` refusé en production, déconnexion sans jeton → 419. | `ConfigValidatorTest::testLogMailDriverIsRefusedInProduction`, `InputHardeningTest::testLogoutWithoutTokenIs419` |
| R17 (partiel) | README : `-d variables_order=EGPCS` sous `php -S`. | `README.md` |

## Recommandations non traitées (et pourquoi)

| Réf. | Raison | Échéance |
|---|---|---|
| R2 Comptes jamais validés | Purge par cron (7 jours) : relève de l'infrastructure cron/workers, qui n'existe pas encore. Le risque de pré-détournement est limité : la réinitialisation exige le contrôle de la boîte mail. | Phase 2 (cron) ; noté dans `tasks/todo.md` |
| R3 CSRF sans session (double-submit signé) | Supprimer toute session anonyme de formulaire exigerait un second mécanisme CSRF ; gain marginal une fois l'inactivité à 30 min et `maxmemory` + éviction configurés sur l'instance Redis dédiée. | Phase 0 (`maxmemory`) |
| R4 `?lang=` modifie la préférence du compte en GET | Conforme au cahier des charges ; impact faible (langue d'affichage). À remplacer par un formulaire POST dans les paramètres du compte. | Phase 5 |
| R5 Pluriels | Adoption de `MessageFormatter` (ICU) et validation de syntaxe dans `check_translations` : à faire avant la génération des 22 langues. FR/EN actuels corrects. | Phase 8 |
| R11 (reste) | `open_basedir`, en-têtes sur les statiques : templates Hestia. | Phase 0 |
| R12 Routage par hôte, corps JSON | Besoin propre à l'API. | Phase 2 |
| R14 En-tête « visiteur » sur les 404 d'un utilisateur connecté | Cosmétique ; nécessiterait de démarrer la session pour les routes inconnues (coût Redis pour les robots). | Non planifié |
| R17 URL canoniques | Avec le sitemap. | Phase 9 |

## Résultats

```
vendor/bin/phpunit                          OK (168 tests, 711 assertions)
vendor/bin/phpunit --testsuite unit         OK (128 tests, 421 assertions)
vendor/bin/phpunit --testsuite integration  OK (40 tests, 290 assertions)
php tools/check_translations.php            fr 100.0 % (138/138) OK, en 100.0 % (138/138) OK ; 132 clés citées, 0 inconnue ; SUCCÈS
```
