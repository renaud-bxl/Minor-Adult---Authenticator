# CLAUDE.md : VeriAge (nom provisoire)

SaaS européen de vérification d'âge. Une plateforme cliente envoie l'**e-mail** de son utilisateur. VeriAge vérifie l'âge **lui-même, sur son propre VPS**, et renvoie seulement `email`, `is_adult`, `verified_at`, `method`, `expires_at`. Aucune donnée d'identité n'est transmise ni conservée.

Cahier des charges complet : section de chaque phase dans `tasks/todo.md`.

## Démarrage de session (obligatoire)
1. Lire `tasks/lessons.md` et appliquer chaque leçon.
2. Lire `tasks/todo.md` pour connaître l'état.
3. Après toute correction de Renaud : ajouter `[date] | ce qui a mal tourné | règle` dans `tasks/lessons.md`.

## Décisions validées (2026-09-27)
- Domaine de production : `veriage.eu` (provisoire). **Domaine temporaire de recette sur le VPS : `compose-web.net`** (213.156.135.222).
  Le domaine est toujours lu depuis `.env` (`APP_DOMAIN`), jamais écrit en dur.
  Sous-domaines : `www.`, `verify.` (module + widget + API), `eid.` (certificat client).
- Paiement : **Stripe** d'abord, derrière `PaymentGatewayInterface` (Mollie plus tard).
- Vérification : **100 % interne**. **Aucun** fournisseur KYC externe, ni maintenant ni en secours.
  Adaptateurs : `MockProvider` (dev/tests) et `LocalBiometricsProvider` (microservice Python local).
- VPS : **Debian 12 (Bookworm) + HestiaCP**.

## Stack (non négociable)
- PHP 8.2+ (MVC léger maison, aucun framework), PDO + requêtes préparées uniquement.
- MariaDB (celle de HestiaCP), Redis (files, sessions, rate limiting) via `predis/predis`.
- Front : HTML + CSS + JS vanilla. **Pas de Node.js, pas de npm, pas de bundler.**
- Python 3.11 + FastAPI : microservice `biometrics/` sur `127.0.0.1` uniquement, jamais exposé.
- Composer autorisé : stripe/stripe-php, phpmailer/phpmailer, vlucas/phpdotenv, firebase/php-jwt,
  endroid/qr-code, predis/predis. En dev : phpunit/phpunit (tests exigés). Toute autre dépendance doit être justifiée dans `tasks/todo.md`.
- Modèles IA : uniquement des modèles sous licence compatible avec un usage commercial (voir `docs/licences.md`).

## HestiaCP : conséquences
- Vhosts : **templates web Hestia personnalisés** (`/usr/local/hestia/data/templates/web/...`), sources dans `deploy/hestia/`.
  Ne jamais éditer les vhosts générés (ils sont écrasés au rebuild).
- Pare-feu et fail2ban : ceux de Hestia (`v-add-firewall-rule`), **pas d'UFW**.
- TLS : Let's Encrypt de Hestia (`v-add-letsencrypt-domain`), **pas de Certbot**.
- eID : si nginx est en frontal (stack Hestia par défaut), c'est **nginx** qui termine le TLS. Il faut alors un
  `ssl_verify_client` dans un template nginx du sous-domaine `eid.`, qui transmet le certificat au backend. À valider en phase 0.
- Workers : systemd. Tâches planifiées : cron.

## Conventions de code
- Arborescence : `app/{Controllers,Models,Services,Views,Middleware,Verification,Billing,Core}`, `lang/{code}/`,
  `public/` (seul dossier exposé au web), `config/`, `database/migrations/`, `cron/`, `bin/`, `tools/`, `tests/`,
  `biometrics/`, `deploy/`, `docs/`.
- Namespace `App\` en PSR-4 sur `app/`. `declare(strict_types=1);` partout.
- **Aucune chaîne en dur** dans les vues, le JS ou les e-mails : tout passe par `__('domaine.cle', [params])`.
  Langue source FR, repli EN. `tools/check_translations.php` doit afficher 100 % avant chaque livraison.
- Échappement systématique dans les vues (`e()`), CSRF sur tous les formulaires, Argon2id pour les mots de passe,
  clés API stockées hashées.
- RGPD : ne jamais stocker ni journaliser d'image, de nom, de numéro de document, de numéro de registre national
  ni de date de naissance. On stocke uniquement : hash d'e-mail (SHA-256 salé par client), e-mail chiffré
  (AES-256-GCM), booléen, date, méthode, expiration.
- Secrets dans `.env` (jamais commité). `.env.example` doit rester complet.

## Commandes
- Tests : `vendor/bin/phpunit`
- Migrations : `php bin/migrate.php`
- Traductions : `php tools/check_translations.php`
- Serveur de dev : `php -S 127.0.0.1:8000 -t public public/index.php`

## Standard de vérification
Aucune tâche n'est terminée sans preuve : tests PHPUnit passants, appels cURL (`docs/api-tests.md`), vérification visuelle des pages.
Toujours se demander : « Est-ce qu'un staff engineer validerait ça ? »

## Protocole multi-agents (mode automatique, validé par Renaud le 2026-09-27)
Cahier des charges intégral : `docs/cahier-des-charges.md` (fait foi, avec les décisions ci-dessus qui le priment).
Chaque phase de `tasks/todo.md` passe par trois agents :
1. **Créateur** : implémente la phase, écrit les tests, fournit les preuves, coche `tasks/todo.md`.
2. **Contrôleur** : relit tout le diff de la phase, cherche bugs, failles, écarts au cahier des charges, solutions plus élégantes ; corrige directement ; rapport dans `tasks/reviews/phase-N-controle.md`.
3. **Critique** : audit final exigeant (sécurité, RGPD, qualité, tests, i18n, UX, conformité) ; verdict `APPROUVÉ` ou `REJETÉ` + liste d'exigences dans `tasks/reviews/phase-N-critique.md`. Si rejet : retour au créateur puis au contrôleur, puis nouvel audit.
Une phase n'est commitée qu'après `APPROUVÉ`.
