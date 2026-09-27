# VeriAge (nom provisoire)

Service européen de vérification d'âge : une plateforme cliente envoie l'e-mail de son utilisateur,
VeriAge vérifie l'âge sur ses propres serveurs et renvoie uniquement `is_adult`, la date, la méthode
et l'expiration. Contexte, décisions et conventions : [`CLAUDE.md`](CLAUDE.md) ; plan : [`tasks/todo.md`](tasks/todo.md).

> État : **phase 1 (fondations)**. Le guide d'installation serveur (Debian 12 + HestiaCP) arrive en phases 0 et 10.

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

Les identifiants des tests d'intégration sont définis dans `phpunit.xml` (utilisateur `veriage`).

## Arborescence

```
app/Core         noyau : routeur, requête/réponse, vues, PDO, sessions Redis, CSRF, crypto, rate limiting, migrations
app/I18n         traductions, négociation de langue, formats localisés
app/Middleware   en-têtes de sécurité, session, langue, CSRF, authentification
app/Controllers  contrôleurs (site + API)
app/Models       accès aux données (requêtes préparées uniquement)
app/Services     authentification, e-mails, mots de passe
app/Views        gabarits PHP (layout, pages, e-mails)
lang/{code}/     traductions : site, module, emails, billing, errors (24 langues UE ; fr et en actives)
public/          seul dossier exposé (index.php, assets)
database/        migrations SQL
bin/, tools/     scripts CLI
tests/           PHPUnit (Unit, Integration)
docs/            cahier des charges, tests cURL, captures
```
