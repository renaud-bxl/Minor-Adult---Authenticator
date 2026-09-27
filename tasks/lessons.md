# Leçons apprises

Format : `[date] | ce qui a mal tourné | règle pour l'éviter`

À relire au début de chaque session, avant de toucher au code.

---

[2026-09-27] | J'ai proposé des fournisseurs KYC externes (Didit, Veriff, IDnow) comme solution de secours | VeriAge vérifie l'âge LUI-MÊME, sur le VPS, sans aucun service de vérification tiers. Ne jamais proposer ni coder d'adaptateur KYC externe. Seuls MockProvider (dev/tests) et LocalBiometricsProvider existent.
[2026-09-27] | Le prompt imposait UFW + Certbot + des vhosts Apache écrits à la main, alors que le VPS tourne sous HestiaCP | Sur ce VPS, passer par HestiaCP : templates web personnalisés (pas de vhosts écrits à la main), pare-feu et fail2ban de Hestia (pas d'UFW), Let's Encrypt de Hestia (pas de Certbot). Vérifier la stack Hestia (nginx en frontal ou non) avant toute configuration TLS.
[2026-09-27] | Risque de casser les sites déjà hébergés sur le VPS HestiaCP | Tout déploiement passe par HestiaCP (v-*), dans un utilisateur/répertoire propre au projet, sans jamais modifier une config globale partagée ; chaque étape serveur : sauvegarde + retour arrière documenté.
[2026-09-27] | Rate limiting de connexion par e-mail seul (compte verrouillable par un tiers), par IP exacte en IPv6 (/64 contournable), et travail dépendant de l'existence du compte fait pendant la requête (énumération par le temps) | Limiter par IP (/64 en IPv6) + couple identifiant/IP + plafond global plus haut ; tout traitement qui dépend de l'existence d'un compte (e-mails compris) passe après la réponse (`Application::defer`) ou en file.
[2026-09-27] | Regex de route ancrée par `$` : `/en/login%0A` routé comme `/en/login`, puis 500 sur l'en-tête Location | En PCRE, ancrer avec le modificateur `D` (`#^…$#D`) ou `\z` dès qu'une chaîne vient de l'utilisateur.
[2026-09-27] | Worker de webhooks : un lot de 20 livraisons réservé 60 s alors qu'une livraison peut durer 15 s ; au-delà, un second worker reprenait les mêmes lignes (double envoi) et l'ancien écrasait ensuite leur statut | Réservation par bail : le bail couvre UN élément et est prolongé juste avant chaque traitement (nouveau jeton : MariaDB ne compte que les lignes réellement modifiées), et toute écriture de fin est conditionnée au jeton détenu.
[2026-09-27] | Microservice FastAPI : le gestionnaire d'exception global renvoyait un 500 propre, mais Starlette relance l'exception et Uvicorn journalisait la pile complète (message compris) ; un OCR sur image hostile pouvait durer 80 s, bien au-delà du délai de PHP | Dans un service qui voit des données sensibles : rattraper les exceptions dans la route elle-même, filtre de journal qui retire `exc_info`, et un budget de temps GLOBAL pour tout traitement d'une entrée hostile (pas seulement un délai par appel).
[2026-09-27] | Une panne technique du service d'analyse (arrêté, saturé, réponse non signée) faisait échouer la session : webhook « failed », compteur de blocage de l'adresse, session perdue | Une panne n'est pas une décision : ne jamais la transformer en échec métier ; laisser la session ouverte et proposer de recommencer (sans rembourser les tirages anti-fraude).
