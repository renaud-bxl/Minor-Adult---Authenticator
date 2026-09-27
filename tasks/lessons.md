# Leçons apprises

Format : `[date] | ce qui a mal tourné | règle pour l'éviter`

À relire au début de chaque session, avant de toucher au code.

---

[2026-09-27] | J'ai proposé des fournisseurs KYC externes (Didit, Veriff, IDnow) comme solution de secours | VeriAge vérifie l'âge LUI-MÊME, sur le VPS, sans aucun service de vérification tiers. Ne jamais proposer ni coder d'adaptateur KYC externe. Seuls MockProvider (dev/tests) et LocalBiometricsProvider existent.
[2026-09-27] | Le prompt imposait UFW + Certbot + des vhosts Apache écrits à la main, alors que le VPS tourne sous HestiaCP | Sur ce VPS, passer par HestiaCP : templates web personnalisés (pas de vhosts écrits à la main), pare-feu et fail2ban de Hestia (pas d'UFW), Let's Encrypt de Hestia (pas de Certbot). Vérifier la stack Hestia (nginx en frontal ou non) avant toute configuration TLS.
