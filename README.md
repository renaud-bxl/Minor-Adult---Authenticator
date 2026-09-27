# VeriAge (nom provisoire)

Service européen de vérification d'âge : une plateforme cliente envoie l'e-mail de son utilisateur,
VeriAge vérifie l'âge sur ses propres serveurs et renvoie uniquement `is_adult`, la date, la méthode
et l'expiration. Contexte, décisions et conventions : [`CLAUDE.md`](CLAUDE.md) ; plan : [`tasks/todo.md`](tasks/todo.md).

> État : **phase 2 (API et module)** : API REST, page de vérification hébergée, widget, webhooks signés,
> méthode simulée (sandbox) et démonstration. Le guide d'installation serveur (Debian 12 + HestiaCP) arrive en phases 0 et 10.

## Démarrage rapide de la démo sur Mac

La démonstration montre une **boutique fictive** (« Cave du Parc ») qui intègre VeriAge comme le ferait
un vrai client : création de session par l'API, widget (modale, popup, iframe, redirection), page de
vérification, webhook signé et confirmation par l'API. Tout est en **mode test (sandbox)** : aucune
vérification réelle, aucun e-mail envoyé (le code à 6 chiffres s'affiche dans la fenêtre).

**1. Prérequis** (une seule fois, avec [Homebrew](https://brew.sh)) :

```bash
brew install php composer mariadb redis
brew services start mariadb
brew services start redis
```

**2. Installation** (dans le dossier du projet) :

```bash
composer install
cp .env.example .env
cat >> .env <<'EOF'
APP_ENV=local
APP_DEBUG=true
APP_URL=http://127.0.0.1:8000
VERIFY_URL=http://127.0.0.1:8000
DEMO_URL=http://127.0.0.1:8001
SESSION_SECURE_COOKIE=false
MAIL_DRIVER=log
MAIL_QUEUE=sync
VERIFICATION_ALLOW_PRIVATE_NETWORK=true
DB_PASSWORD=veriage_dev
EOF
php bin/generate-keys.php >> .env

mysql -e "CREATE DATABASE IF NOT EXISTS veriage CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
          CREATE USER IF NOT EXISTS 'veriage'@'localhost' IDENTIFIED BY 'veriage_dev';
          GRANT ALL ON veriage.* TO 'veriage'@'localhost';"
php bin/migrate.php
php bin/project.php demo >> .env      # projet de démonstration : clé sandbox + secret de signature
```

(Dans `.env`, la dernière valeur d'une variable l'emporte : les lignes ajoutées remplacent celles de l'exemple.
`bin/generate-keys.php` n'est à lancer qu'une fois : changer `CRYPTO_KEY` rend illisibles les données déjà chiffrées.)

**3. Lancement** : trois terminaux, dans le dossier du projet :

```bash
PHP_CLI_SERVER_WORKERS=4 php -S 127.0.0.1:8000 -t public public/index.php   # VeriAge (site + module + API)
PHP_CLI_SERVER_WORKERS=4 php -S 127.0.0.1:8001 -t public public/index.php   # boutique fictive (autre origine)
php bin/worker.php                                                          # webhooks signés (et e-mails en file)
```

**4. Ouvrir** <http://127.0.0.1:8001/demo> : choisir un mode d'affichage, cliquer « 1. Créer la session »,
puis « 2. Vérifier mon âge ». Le panneau « Coulisses » montre l'appel d'API, les événements du widget, la
confirmation par l'API et le webhook reçu (signature vérifiée). En mode « Redirection », la page de retour
vérifie le jeton JWT.

Notes : `http://` et l'adresse `127.0.0.1` ne sont acceptés pour les webhooks et `return_url` que grâce à
`VERIFICATION_ALLOW_PRIVATE_NETWORK=true`, **interdit en production** (refus de démarrer). La démo est désactivée
en production (sauf `DEMO_ENABLED=true`). Tests de bout en bout du parcours, avec captures :
`python3 tools/e2e_demo.py` (Python 3 + Playwright ; `CHROMIUM_PATH` optionnel).

## Installation de développement

Prérequis : PHP ≥ 8.2 avec `intl`, `mbstring`, `openssl`, `pdo_mysql`, `sodium` (Argon2id) ; Composer ;
MariaDB ≥ 10.6 ; Redis ≥ 6. Aucun Node.js ni npm.

```bash
composer install
cp .env.example .env
php bin/generate-keys.php          # copier APP_KEY et CRYPTO_KEY dans .env
```

Dans `.env`, pour un poste local en HTTP :

```dotenv
APP_ENV=local
APP_DEBUG=true
APP_URL=http://127.0.0.1:8000
SESSION_SECURE_COOKIE=false   # cookie sans « Secure » car pas de HTTPS en local
MAIL_DRIVER=log               # e-mails écrits dans storage/mail/*.eml au lieu d'être envoyés
DB_PASSWORD=...
```

Base de données (les dates sont stockées en UTC) :

```sql
CREATE DATABASE veriage CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE DATABASE veriage_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;  -- tests d'intégration
CREATE USER 'veriage'@'localhost' IDENTIFIED BY '...';
GRANT ALL ON veriage.* TO 'veriage'@'localhost';
GRANT ALL ON veriage_test.* TO 'veriage'@'localhost';
```

```bash
php bin/migrate.php                              # applique database/migrations/*.sql
php -S 127.0.0.1:8000 -t public public/index.php # http://127.0.0.1:8000/
```

En production (`APP_ENV=production`), l'application refuse de démarrer si la configuration est
incomplète (URL https, clés, SMTP, cookie Secure, inactivité de session ≤ 30 min, PHP-FPM).

Sous `php -S`, une variable exportée dans le shell ne prime sur `.env` qu'avec
`php -d variables_order=EGPCS -S …` (phpdotenv ne lit pas `getenv()`).

## Commandes

| Commande | Rôle |
|---|---|
| `vendor/bin/phpunit` | tous les tests |
| `vendor/bin/phpunit --testsuite unit` | tests unitaires (aucun service requis) |
| `vendor/bin/phpunit --testsuite integration` | MariaDB (`veriage_test`, recréée) + Redis (base 15, purgée) réels |
| `php tools/check_translations.php` | couverture des traductions (`--all` : 24 langues) |
| `php bin/migrate.php [--status]` | migrations |
| `python3 tools/screenshots.py` | captures de contrôle visuel (Playwright ; `CHROMIUM_PATH` optionnel) |
| `python3 tools/e2e_demo.py` | tests E2E de la démonstration (4 modes du widget, webhooks, jeton) + captures `docs/screenshots/phase-2/` |
| `php bin/project.php create …` | projet client : clés `sk_test_`/`sk_live_`, secrets de signature, domaines, webhooks (voir l'en-tête du script) |
| `php bin/worker.php [--id=1] [--once]` | worker : webhooks signés (relances exponentielles) et e-mails en file (service systemd `deploy/systemd/`) |
| `php cron/purge.php` | purge RGPD (sessions, vérifications expirées, jetons, comptes non validés, audit) ; cron horaire (`deploy/crontab`) |

Les identifiants des tests d'intégration sont définis dans `phpunit.xml` (utilisateur `veriage`).

## Arborescence

```
app/Core         noyau : routeur, requête/réponse, vues, PDO, sessions Redis, CSRF, crypto, rate limiting, migrations
app/I18n         traductions, négociation de langue, formats localisés
app/Middleware   en-têtes de sécurité, session, langue, CSRF, authentification
app/Controllers  contrôleurs (site + API)
app/Models       accès aux données (requêtes préparées uniquement)
app/Services     authentification, e-mails (file Redis + worker), mots de passe, projets, purge
app/Verification module : sessions, méthodes (VerificationMethodInterface, MockProvider), webhooks, SSRF, jetons
app/Billing      point d'extension de la facturation (CreditGateInterface ; phase 6)
app/Views        gabarits PHP (layout, pages, e-mails)
lang/{code}/     traductions : site, module, emails, billing, errors (24 langues UE ; fr et en actives)
public/          seul dossier exposé (index.php, assets, widget/verify.js)
cron/            tâches planifiées (purge)
deploy/          crontab et service systemd du worker (phase 0 : templates HestiaCP)
database/        migrations SQL
bin/, tools/     scripts CLI
tests/           PHPUnit (Unit, Integration)
docs/            cahier des charges, tests cURL, captures
```
