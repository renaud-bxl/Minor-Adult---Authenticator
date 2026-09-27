#!/usr/bin/env bash
# Lance la démo VeriAge en local sur un Mac, en une commande : `bash bin/demo-mac.sh`
# Reprend pas à pas la section « Démarrage rapide de la démo sur Mac » du README.
# Idempotent : relançable sans risque (la configuration n'est créée qu'une fois).
# Arrêt : Ctrl+C dans ce terminal.
set -euo pipefail
cd "$(dirname "$0")/.."

command -v brew >/dev/null || { echo "Homebrew est requis : https://brew.sh"; exit 1; }
for pkg in php composer mariadb redis; do
  brew list --formula "$pkg" >/dev/null 2>&1 || brew install "$pkg"
done
brew services start mariadb >/dev/null
brew services start redis >/dev/null

composer install --no-interaction --quiet

if [ ! -f .env ]; then
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
  # MariaDB vient d'être démarrée : on attend qu'elle accepte les connexions.
  for _ in $(seq 1 30); do mysql -e "SELECT 1" >/dev/null 2>&1 && break; sleep 1; done
  mysql -e "CREATE DATABASE IF NOT EXISTS veriage CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
            CREATE USER IF NOT EXISTS 'veriage'@'localhost' IDENTIFIED BY 'veriage_dev';
            GRANT ALL ON veriage.* TO 'veriage'@'localhost';"
  php bin/migrate.php
  php bin/project.php demo >> .env
else
  php bin/migrate.php
fi

pids=()
trap 'kill "${pids[@]}" 2>/dev/null' EXIT INT TERM
PHP_CLI_SERVER_WORKERS=4 php -S 127.0.0.1:8000 -t public public/index.php >/dev/null 2>&1 & pids+=($!)
PHP_CLI_SERVER_WORKERS=4 php -S 127.0.0.1:8001 -t public public/index.php >/dev/null 2>&1 & pids+=($!)
php bin/worker.php & pids+=($!)
sleep 2

echo "Site VeriAge : http://127.0.0.1:8000/fr/"
echo "Démo boutique : http://127.0.0.1:8001/demo"
open "http://127.0.0.1:8001/demo" 2>/dev/null || true
echo "Ctrl+C pour tout arrêter."
wait
