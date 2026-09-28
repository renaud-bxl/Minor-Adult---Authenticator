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

## Biométrie locale (pièce d'identité + visage)

Microservice Python `biometrics/` (FastAPI + Uvicorn), **à l'écoute de 127.0.0.1 uniquement**, appelé par
PHP avec authentification mutuelle HMAC. **Aucun service externe à l'exécution** : les modèles sont
téléchargés une fois, au déploiement, avec contrôle SHA-256 (licences : `docs/licences.md`). Les images
sont traitées en mémoire ; la réponse se limite à `{ age, doc_expired, face_match_score, liveness_passed,
mrz_valid, reasons[] }`.

**Niveau d'assurance : faible à modéré, sans certification.** Recto, verso et portrait sont liés à une même
pièce (document détecté, portrait à sa place, champs imprimés = MRZ) ; contrôle du vivant par défis aléatoires.
Aucune détection d'injection (caméra virtuelle) ni de deepfake, aucun contrôle des éléments de sécurité du
document : une autre photo de la personne animée et injectée passe (l'E2E le démontre). Détail pour les clients :
`docs/integration.md`, encadré « ce qu'elle garantit, et ce qu'elle ne garantit pas ».

### Installation (Debian 12, sans toucher au Python du système)

```bash
# 1. Paquets système (root) : Python 3.11 + venv, Tesseract, bibliothèques chargées par MediaPipe.
apt install python3.11-venv tesseract-ocr libegl1 libgles2
# (développement / tests seulement : police OCR-B libre des images synthétiques, strace)
apt install fonts-ocr-b strace

# 2. Environnement virtuel dédié (biometrics/.venv, ignoré par git), dépendances figées AVEC sommes :
cd biometrics
python3.11 -m venv .venv
.venv/bin/pip install --upgrade pip
.venv/bin/pip install --require-hashes -r requirements.txt
.venv/bin/pip install --require-hashes --no-deps -r requirements-mediapipe.txt   # MediaPipe sans opencv-contrib ni audio
.venv/bin/pip install --require-hashes -r requirements-dev.txt                   # tests (facultatif en production)

# 3. Modèles (YuNet, SFace, MediaPipe Face Landmarker, MRZ OCR-B), SHA-256 vérifiés :
scripts/fetch_models.sh
```

`pip check` signale que mediapipe « requiert » `opencv-contrib-python` et `sounddevice` : c'est voulu
(installation `--no-deps`, voir `requirements-mediapipe.in`). Pour régénérer les verrous après une montée
de version : `.venv/bin/python scripts/lock_requirements.py` (outil de développement).

### Configuration

```bash
php bin/generate-keys.php   # produit aussi BIOMETRICS_SECRET (64 caractères hexadécimaux)
```

- `.env` (PHP) : `BIOMETRICS_ENABLED=true`, `BIOMETRICS_URL=http://127.0.0.1:8765`, `BIOMETRICS_SECRET=…`,
  seuils `BIOMETRICS_FACE_MATCH_THRESHOLD` / `BIOMETRICS_FACE_REVIEW_THRESHOLD` (à calibrer), etc.
  (voir `.env.example`).
- Service : même secret dans `/etc/veriage/biometrics.env` (root:veriage-bio, 0640) avec
  `BIOMETRICS_HOST=127.0.0.1` et `BIOMETRICS_PORT=8765`. Le service refuse de démarrer si l'adresse n'est
  pas une boucle locale, si le secret fait moins de 32 caractères ou si un modèle manque.

### Service systemd (production)

`deploy/systemd/veriage-biometrics.service` : utilisateur système dédié `veriage-bio` (sans shell),
`ProtectSystem=strict`, `PrivateTmp`, `NoNewPrivileges`, aucune capacité, aucun réseau sortant
(`IPAddressDeny=any`, `IPAddressAllow=localhost`), `LimitCORE=0` (pas de vidage mémoire contenant des
images), `MemoryMax=2G`.

```bash
useradd --system --home /nonexistent --shell /usr/sbin/nologin veriage-bio
# Le home Hestia n'est pas ouvert aux autres comptes : traversée seule pour veriage-bio, puis lecture de biometrics/.
# Vérifier : sudo -u veriage-bio test -r /home/veriage/web/compose-web.net/app/biometrics/models/face_landmarker.task
setfacl -m u:veriage-bio:x /home/veriage /home/veriage/web /home/veriage/web/compose-web.net /home/veriage/web/compose-web.net/app
setfacl -R -m u:veriage-bio:rX /home/veriage/web/compose-web.net/app/biometrics
install -m 0644 deploy/systemd/veriage-biometrics.service /etc/systemd/system/
systemctl daemon-reload && systemctl enable --now veriage-biometrics
php bin/biometrics.php health   # requête et réponse signées ; code retour 0 si tout est chargé
```

Retour arrière : `systemctl disable --now veriage-biometrics`, `BIOMETRICS_ENABLED=false` (la méthode
n'est plus proposée), suppression de l'unité. Rien d'autre n'est modifié sur le serveur.

### Pool PHP-FPM de VeriAge (via le template Hestia, phase 0)

L'envoi des images (≈ 3 à 5 Mo, chiffré AES-GCM dans le navigateur) exige, **pour ce pool seulement** :
`post_max_size = 16M` et un `upload_tmp_dir` propre au projet, idéalement un tmpfs (PHP y recopie tout
corps de requête de plus de 16 Kio, le temps de la requête ; ce fichier ne contient qu'un chiffré).
Indiquer ce dossier dans `BIOMETRICS_TMP_DIR` : `cron/purge_tmp.php` le purge toutes les 15 minutes
(`deploy/crontab`).

### Développement et tests

```bash
cd biometrics && scripts/fetch_test_assets.sh     # photos de TEST du domaine public (SHA-256 vérifiées)
.venv/bin/python -m pytest -q                      # MRZ, HMAC, OCR, visages, liveness, API
set -a; . /chemin/biometrics.env; set +a; .venv/bin/python -m veriage_biometrics   # service local
```

`vendor/bin/phpunit` lance aussi `BiometricsServiceTest` (PHP → Python réel, sauté si le venv, les
modèles ou les photos de test manquent). E2E de la capture (fausse caméra Chromium) :

```bash
biometrics/.venv/bin/python biometrics/scripts/make_test_images.py --out /tmp/e2e/set \
    --camera-frames /tmp/e2e/poses --doc-poses /tmp/e2e/doc-poses --y4m /tmp/e2e/face.y4m --card-video /tmp/e2e/card.y4m
# Base jetable (jamais la base de développement : le journal d'audit n'est jamais retouché) :
mysql -uroot -e "CREATE DATABASE veriage_e2e; GRANT ALL ON veriage_e2e.* TO 'veriage'@'localhost';"
export DB_DATABASE=veriage_e2e REDIS_DB=13 VERIFY_URL=http://127.0.0.1:8010
php -d variables_order=EGPCS bin/migrate.php
php -d variables_order=EGPCS bin/project.php create --account-name=E2E --name=E2E --origins=http://127.0.0.1:8011
php -d variables_order=EGPCS -S 127.0.0.1:8010 -t public public/index.php &
python3 tools/e2e_capture.py --assets /tmp/e2e --verify http://127.0.0.1:8010 --api-key sk_test_…
mysql -uroot -e "DROP DATABASE veriage_e2e"          # suppression en bloc à la fin
```

Revue manuelle : **désactivée en V1** (audit de la phase 3, E3) ; sous le seuil, la vérification échoue.
`bin/review.php` reste en place pour une réactivation après décision juridique.

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
| `php cron/purge_tmp.php` | purge des fichiers temporaires éventuels (toutes les 15 minutes) |
| `php bin/review.php list` | file de vérification manuelle (désactivée en V1) |
| `php bin/biometrics.php health` | état du microservice biométrique (authentification mutuelle) |
| `python3 tools/e2e_capture.py --assets DIR` | E2E de la capture pièce d'identité + visage (fausse caméra Chromium) |
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
