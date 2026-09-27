# Tests cURL

Collection de requêtes de vérification, phase par phase. Serveur de développement :
`php -S 127.0.0.1:8000 -t public public/index.php` avec le `.env` local du README.

```bash
B=http://127.0.0.1:8000
```

## Phase 1 : fondations

Résultats relevés le 2026-09-27 (PHP 8.4, MariaDB 10.11, Redis 7).

### 1. En-têtes de sécurité

```bash
curl -sI $B/fr/
```

```
HTTP/1.1 200 OK
Content-Security-Policy: default-src 'none'; script-src 'self'; style-src 'self'; img-src 'self' data:; font-src 'self'; connect-src 'self'; manifest-src 'self'; form-action 'self'; frame-ancestors 'none'; base-uri 'none'
Strict-Transport-Security: max-age=31536000
X-Content-Type-Options: nosniff
X-Frame-Options: DENY
Referrer-Policy: no-referrer
Permissions-Policy: camera=(), microphone=(), geolocation=(), payment=(), usb=()
Cross-Origin-Opener-Policy: same-origin
Cross-Origin-Resource-Policy: same-origin
Cache-Control: no-store, private
```

Les mêmes en-têtes sont présents sur les pages d'erreur (404, 419, 429, 500).
Note : avec `php -S`, les fichiers statiques de `public/assets` sont servis directement par le
serveur intégré, sans ces en-têtes ; en production ils viennent du template Hestia (phase 0).

### 2. Redirection de `/` selon Accept-Language

```bash
curl -s -o /dev/null -D - -H 'Accept-Language: fr-BE,fr;q=0.9,en;q=0.8' $B/
curl -s -o /dev/null -D - -H 'Accept-Language: en-GB,en;q=0.9' $B/
```

```
HTTP/1.1 302 Found
Location: /fr/
Vary: Accept-Language, Cookie

HTTP/1.1 302 Found
Location: /en/
```

### 3. POST sans jeton CSRF → 419

```bash
curl -s -o /dev/null -w '%{http_code}\n' -X POST -d 'email=a@b.be&password=x' $B/fr/login
```

```
419
```

### 4. 6e tentative de connexion → 429

Jeton CSRF récupéré sur le formulaire, puis six tentatives avec la même adresse. Chaque tentative
change d'en-tête `X-Forwarded-For` : il est ignoré (aucun proxy de confiance déclaré), la limite
s'applique bien à l'adresse de connexion réelle.

```bash
TOKEN=$(curl -s -c jar $B/fr/login | grep -o 'name="_token" value="[a-f0-9]*"' | head -1 | sed 's/.*value="//;s/"//')
for i in 1 2 3 4 5 6; do
  curl -s -b jar -c jar -o /dev/null -D hdr -w "tentative $i : %{http_code}\n" \
    -H "X-Forwarded-For: 192.0.2.$i" \
    --data-urlencode "_token=$TOKEN" --data-urlencode "email=cible@example.be" \
    --data-urlencode "password=mauvais mot de passe $i" $B/fr/login
done
grep -i retry-after hdr
```

```
tentative 1 : 422
tentative 2 : 422
tentative 3 : 422
tentative 4 : 422
tentative 5 : 422
tentative 6 : 429
Retry-After: 899
```

Limites (config/security.php) : 30 / 15 min par IP (préfixe /64 en IPv6), 5 / 15 min par couple
adresse e-mail + IP, 20 / h par adresse toutes IP confondues. Un tiers ne peut donc pas verrouiller un
compte depuis une seule IP ; une attaque distribuée reste plafonnée. Le message d'échec est identique
pour une adresse inconnue et un mauvais mot de passe.

`X-Forwarded-For` n'est lu que si `REMOTE_ADDR` figure dans `TRUSTED_PROXIES` (vide par défaut :
sous HestiaCP, Apache restaure l'IP réelle via mod_remoteip), et alors de droite à gauche.

### 5. Traductions du module (widget) : `GET /api/v1/i18n/{code}`

```bash
curl -s -D - $B/api/v1/i18n/en
E=$(curl -sI $B/api/v1/i18n/en | grep -i etag | cut -d' ' -f2 | tr -d '\r')
curl -s -o /dev/null -w '%{http_code}\n' -H "If-None-Match: $E" $B/api/v1/i18n/en
curl -s $B/api/v1/i18n/de
```

```
HTTP/1.1 200 OK
ETag: "0f99d200b206099b10008d806a1cca5b63d620e890df4d7c9b77589feb460675"
Cache-Control: public, max-age=3600
Access-Control-Allow-Origin: *
{"locale":"en","messages":{"widget.close":"Close", … ,"widget.title":"Age verification"}}

304

{"error":{"code":"not_found","message":"The requested page does not exist or has been moved."}}
```

(`de` n'est pas encore activée : `LANGS_ENABLED=fr,en` jusqu'à la phase 8.)

### 6. POST avec `?lang=` sans jeton CSRF → 419 (aucune préférence modifiée)

```bash
curl -s -o /dev/null -w '%{http_code}\n' -X POST "$B/fr/login?lang=en"
```

```
419
```

### 7. E-mails après la réponse

Inscription, « mot de passe oublié » et renvoi du lien de validation sont exécutés après l'envoi de la
réponse (`Application::defer`, `fastcgi_finish_request()` sous PHP-FPM) : la durée de la réponse ne
dépend ni de l'existence du compte ni du serveur SMTP. Avec `php -S` (pas de `fastcgi_finish_request`),
le client attend la fin du traitement : mesure non significative en local ; la garantie est vérifiée par
`AuthFlowTest::testForgotPasswordWorkRunsAfterTheResponse` (aucun jeton ni e-mail pendant la requête).

### Parcours complet

Inscription → e-mail de validation → validation → connexion → mot de passe oublié → réinitialisation
→ invalidation des autres sessions : couvert de bout en bout par `tests/Integration/AuthFlowTest.php`
(MariaDB + Redis réels, e-mails lus dans la boîte d'envoi du pilote `log`).

---

## Phase 2 : API et module

Résultats relevés le 2026-09-27 (PHP 8.4, MariaDB 10.11, Redis 7) sur le serveur de développement :
module et site sur `http://127.0.0.1:8000` (`APP_URL` = `VERIFY_URL`), démonstration sur
`http://127.0.0.1:8001` (`DEMO_URL`), worker lancé (`php bin/worker.php`), `.env` local du README
(`VERIFICATION_ALLOW_PRIVATE_NETWORK=true` : en développement seulement, d'où `origin_not_allowed`
au lieu de `private_address` pour `https://10.0.0.1` ; en production, voir `UrlGuardTest`).
En production, l'API et la page hébergée sont servies par `https://verify.{APP_DOMAIN}` uniquement.

### 0. Projet de test (CLI, en attendant l'espace client de la phase 5)

```bash
php bin/project.php create --account-name="Brasserie Démo SRL" --name="Boutique cURL" \
    --origins=https://shop.example,https://*.shop.example --min-age=18
```

```
Projet créé : prj_n43JGRkYIBWooEhxIjCg (Boutique cURL)
  Domaines autorisés : https://shop.example, https://*.shop.example
  Âge minimal : 18 ; validité : 365 jours

À conserver en lieu sûr (affiché une seule fois) :
  Clé sandbox     : sk_test_GmqReKCD…OeLY
  Clé production  : sk_live_iLR45JdJ…UDwb
  Secret de signature (sandbox)    : whsec_24S1lCzG…hTo2
  Secret de signature (production) : whsec_az0VNDZf…GPFK2
```

Un domaine non conforme est refusé (`--origins=http://evil.example` → `Erreur : Domaine autorisé invalide ou interdit`, code retour 1).

```bash
B=http://127.0.0.1:8000
K=sk_test_…      # clé sandbox affichée ci-dessus
```

### 1. Création d'une session (201)

```bash
curl -s -i -X POST $B/api/v1/sessions -H "Authorization: Bearer $K" -H 'Content-Type: application/json' \
  -d '{"email":"user@exemple.be","min_age":18,"return_url":"https://shop.example/retour","lang":"fr","external_ref":"user_4521"}'
```

```
HTTP/1.1 201 Created
Content-Type: application/json; charset=UTF-8
X-RateLimit-Limit: 600
X-RateLimit-Remaining: 599
Cache-Control: no-store, private

{"object":"verification_session","session_id":"vs_vUehIQR6064yboYFckt5jvkgQ3DLR5IH","project":"prj_n43JGRkYIBWooEhxIjCg",
 "status":"pending","verify_url":"http://127.0.0.1:8000/s/vs_vUehIQR6064yboYFckt5jvkgQ3DLR5IH","expires_in":1800,
 "expires_at":"2026-09-27T23:29:56+02:00","livemode":false}
```

### 2. Authentification et erreurs JSON normalisées

```bash
curl -s -i "$B/api/v1/verifications?email=user@exemple.be"                                  # sans clé
curl -s -H "Authorization: Bearer sk_test_AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA" "$B/api/v1/verifications?email=user@exemple.be"
```

```
HTTP/1.1 401 Unauthorized
WWW-Authenticate: Bearer realm="VeriAge"
{"error":{"code":"unauthorized","message":"Missing, invalid or revoked API key. Use the “Authorization: Bearer sk_…” header."}}
{"error":{"code":"unauthorized","message":"Missing, invalid or revoked API key. Use the “Authorization: Bearer sk_…” header."}}   (401)
```

Validation (422, un code stable par champ) :

```bash
curl -s -X POST $B/api/v1/sessions -H "Authorization: Bearer $K" -H 'Content-Type: application/json' \
  -d '{"email":"pas-une-adresse","min_age":17,"return_url":"https://evil.example/x","lang":"xx","minAge":18}'
```

```
{"error":{"code":"validation_failed","message":"Some fields are invalid (see “details”).",
 "details":{"email":"invalid","lang":"unsupported","minAge":"unknown_field","min_age":"invalid","return_url":"origin_not_allowed"}}}   (422)
```

Protection SSRF et redirection ouverte sur `return_url` (domaines autorisés du projet, https, sans identifiants) :

```bash
for u in 'http://shop.example/r' 'https://10.0.0.1/r' 'https://user:pw@shop.example/r' 'https://shop.example.evil.com/r'; do
  curl -s -X POST $B/api/v1/sessions -H "Authorization: Bearer $K" -H 'Content-Type: application/json' -d "{\"email\":\"a@b.be\",\"return_url\":\"$u\"}"; echo
done
```

```
{"error":{"code":"validation_failed",…,"details":{"return_url":"insecure_scheme"}}}
{"error":{"code":"validation_failed",…,"details":{"return_url":"origin_not_allowed"}}}
{"error":{"code":"validation_failed",…,"details":{"return_url":"credentials_not_allowed"}}}
{"error":{"code":"validation_failed",…,"details":{"return_url":"origin_not_allowed"}}}
```

Corps : formulaire → 415, JSON invalide → 400, plus de 64 Kio → 413 :

```
{"error":{"code":"unsupported_media_type","message":"Unsupported content type: use “Content-Type: application/json”."}} 415
{"error":{"code":"invalid_json","message":"The request body must be a valid JSON object."}} 400
{"error":{"code":"payload_too_large","message":"The request body is too large."}} 413
```

`402 insufficient_credits` : préparé (point d'extension `CreditGateInterface`, tout est autorisé jusqu'à la
phase 6) ; prouvé par `ApiSecurityTest::testInsufficientCreditsIs402ForLiveOnly`.

### 3. Statuts `not_verified` / `pending`

```bash
curl -s -H "Authorization: Bearer $K" "$B/api/v1/verifications?email=user@exemple.be"
curl -s -H "Authorization: Bearer $K" "$B/api/v1/verifications?email=inconnu@exemple.be"
```

```
{"object":"verification","email":"user@exemple.be","livemode":false,"status":"pending","is_adult":false,
 "session_id":"vs_vUehIQR6064yboYFckt5jvkgQ3DLR5IH","session_expires_at":"2026-09-27T23:29:56+02:00"}
{"object":"verification","email":"inconnu@exemple.be","livemode":false,"status":"not_verified","is_adult":false}
```

### 4. Parcours de la page hébergée en cURL (sandbox, sans cookie)

Chaque formulaire porte un jeton signé `_state` (aucun cookie) ; chaque POST répond 303.

```bash
S=vs_vUehIQR6064yboYFckt5jvkgQ3DLR5IH
STATE=$(curl -s $B/s/$S | grep -o 'name="_state" value="[^"]*"' | head -1 | sed 's/.*value="//;s/"//')
curl -s -o /dev/null -D - -X POST "$B/s/$S/consent?lang=fr" --data-urlencode "_state=$STATE" -d consent=1
CODE=$(curl -s "$B/s/$S?lang=fr" | grep -o 'data-sandbox-code>[0-9]*' | grep -o '[0-9]*$')   # sandbox : code affiché
curl -s -o /dev/null -D - -X POST "$B/s/$S/code?lang=fr" --data-urlencode "_state=$STATE" -d code=000000
curl -s -o /dev/null -D - -X POST "$B/s/$S/code?lang=fr" --data-urlencode "_state=$STATE" -d code=$CODE
curl -s -o /dev/null -D - -X POST "$B/s/$S/method/mock?lang=fr" --data-urlencode "_state=$STATE" -d outcome=adult
curl -s "$B/s/$S?lang=fr" | grep -o 'data-result-status="[a-z]*"'
```

```
HTTP/1.1 303 See Other
Location: /s/vs_vUehIQR6064yboYFckt5jvkgQ3DLR5IH?lang=fr&notice=code_generated
HTTP/1.1 303 See Other
Location: /s/vs_vUehIQR6064yboYFckt5jvkgQ3DLR5IH?lang=fr&notice=code_invalid
HTTP/1.1 303 See Other
Location: /s/vs_vUehIQR6064yboYFckt5jvkgQ3DLR5IH?lang=fr
HTTP/1.1 303 See Other
Location: /s/vs_vUehIQR6064yboYFckt5jvkgQ3DLR5IH?lang=fr
data-result-status="verified"
```

Sans jeton `_state` : `curl -s -o /dev/null -w '%{http_code}' -X POST "$B/s/$S/consent" -d consent=1` → `419`.

En-têtes de la page (intégrable uniquement par les domaines du projet ; popup : `window.opener` préservé) :

```
Content-Security-Policy: default-src 'none'; script-src 'self'; style-src 'self'; img-src 'self' data:; font-src 'self'; connect-src 'self'; manifest-src 'self'; form-action 'self'; frame-ancestors 'self' https://shop.example https://*.shop.example; base-uri 'none'
Cross-Origin-Opener-Policy: unsafe-none
```
(aucun `X-Frame-Options`, aucun `Set-Cookie`.)

### 5. Statut `verified` et retour `return_url?session_id=&token=`

```bash
curl -s -H "Authorization: Bearer $K" "$B/api/v1/verifications?email=user@exemple.be"
curl -s -o /dev/null -D - "$B/s/$S/return"
```

```
{"object":"verification","email":"user@exemple.be","livemode":false,"status":"verified","is_adult":true,"min_age":18,
 "verified_at":"2026-09-27T23:00:09+02:00","method":"mock","expires_at":"2027-09-27T23:00:09+02:00"}

HTTP/1.1 303 See Other
Location: https://shop.example/retour?session_id=vs_vUehIQR6064yboYFckt5jvkgQ3DLR5IH&token=eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJpc3Mi…
```

Charge utile du JWT (HS256, secret de signature du projet, 5 min) : `iss`, `aud` (= `prj_…`), `sub` (= `session_id`),
`iat`, `exp`, `jti`, `livemode`, `external_ref`, `status`, `is_adult`, `min_age`, `verified_at`, `expires_at`,
`method`, `reused` (jamais l'adresse e-mail). Vérification côté client : `App\Verification\ReturnToken::verify()`.

Statut `failed` : même parcours avec `outcome=fail` → `{"status":"failed","is_adult":false,"failure_reason":"mock_failure",…}`
(`SessionApiTest::testStatusesNotVerifiedPendingVerifiedFailed`).

### 6. Réutilisation (même client) et couverture de l'âge

```bash
curl -s -X POST $B/api/v1/sessions -H "Authorization: Bearer $K" -H 'Content-Type: application/json' -d '{"email":"USER@exemple.be","min_age":16}'
curl -s -X POST $B/api/v1/sessions -H "Authorization: Bearer $K" -H 'Content-Type: application/json' -d '{"email":"user@exemple.be","min_age":21}'
```

```
{"object":"verification_session","session_id":"vs_u48dgUs32ShXRSjKzoSU9Hhjkamix5Hv",…,"status":"verified",…,"reused":true,
 "verification":{"object":"verification","email":"user@exemple.be","status":"verified","is_adult":true,"min_age":16,
 "verified_at":"2026-09-27T23:00:09+02:00","method":"mock","expires_at":"2027-09-27T23:00:09+02:00","livemode":false}}   (200)
{"object":"verification_session","session_id":"vs_NXxB6kYotSgCWDWqFaPlna8btG1yIWZ5",…,"status":"pending",…}   (201 : majeur à 18 ≠ majeur à 21)
```

### 7. Cloisonnement (pas d'IDOR) : autre mode, autre client

```bash
curl -s -H "Authorization: Bearer sk_live_…" "$B/api/v1/verifications?email=user@exemple.be"      # même projet, production
curl -s -H "Authorization: Bearer $DEMO_API_KEY" "$B/api/v1/verifications?email=user@exemple.be"  # autre client
```

```
{"object":"verification","email":"user@exemple.be","livemode":true,"status":"not_verified","is_adult":false}
{"object":"verification","email":"user@exemple.be","livemode":false,"status":"not_verified","is_adult":false}
```

### 8. Effacement (RGPD art. 17)

```bash
curl -s -X DELETE -H "Authorization: Bearer $K" "$B/api/v1/verifications?email=user@exemple.be"
curl -s -H "Authorization: Bearer $K" "$B/api/v1/verifications?email=user@exemple.be"
```

```
{"object":"verification","email":"user@exemple.be","deleted":true,"livemode":false}
{"object":"verification","email":"user@exemple.be","livemode":false,"status":"not_verified","is_adult":false}
```

### 9. Limitation de débit (échecs d'authentification par IP)

```bash
for i in $(seq 1 21); do curl -s -o /dev/null -w '%{http_code} ' -H "Authorization: Bearer sk_test_BBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBB" "$B/api/v1/verifications?email=a@b.be"; done
```

```
401 401 401 401 401 401 401 401 401 401 401 401 401 401 401 401 401 401 429 429 429
HTTP/1.1 429 Too Many Requests
Retry-After: 272
{"error":{"code":"rate_limited","message":"Too many requests. Retry after the delay given by the Retry-After header."}}
```
(les deux appels 401 des exemples précédents comptent aussi : 20 échecs / 5 min.) Quotas par clé (600/min, en-têtes
`X-RateLimit-*`), par IP (300/min), par adresse (10 sessions/h) et blocage après 5 échecs de vérification en 24 h
(`429 email_locked`) : `ApiSecurityTest`.

### 10. Routage par hôte

```bash
curl -s -o /dev/null -w '%{http_code}\n' -H 'Host: www.veriage.eu' "$B/api/v1/verifications?email=a@b.be"
```

```
404
```
(l'API n'existe que sur l'hôte `verify.` ; le domaine nu est redirigé en 301 vers `APP_URL` : `ApiSecurityTest::testRoutingByHost`.)

### 11. Webhooks signés (réception par la boutique de démonstration)

Signature : `X-VeriAge-Signature: t={unix},v1=hex(HMAC-SHA256(secret, "{t}.{corps brut}"))`, calculable avec openssl :

```bash
DS=whsec_…   # DEMO_SIGNING_SECRET
BODY='{"id":"evt_curl_demo_1","type":"verification.completed","data":{"session_id":"vs_AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA","status":"verified","is_adult":true}}'
T=$(date +%s); SIG=$(printf '%s' "$T.$BODY" | openssl dgst -sha256 -hmac "$DS" -hex | sed 's/^.* //')
curl -s -w ' %{http_code}\n' -X POST http://127.0.0.1:8001/demo/webhook -H 'Content-Type: application/json' -H "X-VeriAge-Signature: t=$T,v1=$SIG" -d "$BODY"
curl -s -w ' %{http_code}\n' -X POST http://127.0.0.1:8001/demo/webhook -H 'Content-Type: application/json' -H "X-VeriAge-Signature: t=$T,v1=$SIG" -d "$BODY"   # rejeu du même événement
# horodatage de plus de 5 min, puis corps modifié :
```

```
{"received":true} 200
{"received":true,"duplicate":true} 200
{"error":"invalid_signature"} 400
{"error":"invalid_signature"} 400
```

Livraisons réelles par le worker pendant la démonstration (`webhook_deliveries`) :

```
event_type              status     attempts  last_status_code  ev
verification.completed  delivered  1         200               evt_iZKkJkTg
verification.completed  delivered  1         200               evt_LcEXSHmg
```

Relances exponentielles (30 s × 2^(n-1), plafond 6 h, 10 tentatives), abandon sur adresse privée (DNS rebinding),
URL devenue non autorisée, idempotence et réservation concurrente : `WebhookDeliveryTest`. Anti-rejeu :
`WebhookSignatureTest` (±300 s, corps ou horodatage modifié, autre secret).

### 12. Traductions du widget

```bash
curl -s -i $B/api/v1/i18n/fr
```

```
HTTP/1.1 200 OK
ETag: "0d6bf519dcf9d230ba3a61d39ecae486c68fba7a6c585d44c231c982b2fd9dc3"
Cache-Control: public, max-age=3600
Access-Control-Allow-Origin: *
```

### 13. Exploitation

```bash
php cron/purge.php                 # purge RGPD (cron horaire, deploy/crontab)
php bin/worker.php --once --id=x   # une passe du worker (webhooks + e-mails en file)
APP_ENV=production php bin/migrate.php --status
```

```
sessions_expired              0
sessions_deleted              0
verifications_deleted         0
deliveries_deleted            0
audit_deleted                 0
tokens_deleted                0
unverified_accounts_deleted   0

Worker x démarré.

Configuration de production invalide :
  - APP_URL doit être une URL absolue en https://
  - VERIFY_URL doit être une URL absolue en https://
  - VERIFICATION_ALLOW_PRIVATE_NETWORK est interdit en production (SSRF)
  - MAIL_DRIVER doit valoir smtp
  - SESSION_SECURE_COOKIE doit valoir true
Erreur interne : consulter storage/logs.
```
(avec le `.env` de développement : refus de démarrer, liste sur STDERR, recommandation 22 du ré-audit.)

### 14. Ajouts du contrôleur (2026-09-27)

**Idempotence de `POST /api/v1/sessions`** (en-tête `Idempotency-Key`, 24 h, par projet et par mode) :

```bash
for i in 1 2; do curl -s -D - -o r$i.json -X POST $V/api/v1/sessions -H "Authorization: Bearer $KEY" \
  -H 'Content-Type: application/json' -H 'Idempotency-Key: order-42' \
  -d '{"email":"idem@example.com","min_age":18}' | grep -iE '^HTTP|^idempotent'; done; cmp r1.json r2.json && echo "corps identiques"
curl -s -X POST $V/api/v1/sessions -H "Authorization: Bearer $KEY" -H 'Content-Type: application/json' \
  -H 'Idempotency-Key: order-42' -d '{"email":"other@example.com"}' -w ' HTTP %{http_code}\n'
```

```
HTTP/1.1 201 Created
HTTP/1.1 201 Created
Idempotent-Replayed: true
corps identiques
{"error":{"code":"idempotency_key_reused","message":"This idempotency key was already used for a different request. Use a new key."}} HTTP 422
```
Une erreur n'est pas mémorisée (la clé peut être réessayée) ; requête d'origine en cours : `409 idempotency_in_progress`
(`Retry-After: 1`) ; session effacée entre-temps : traitée comme une nouvelle requête (`SessionApiTest::testIdempotencyKeyReplaysTheSameSession`).

**Rotation du secret de signature avec recouvrement** (l'ancien secret signe encore les webhooks : deux `v1`) :

```bash
php bin/project.php rotate-secret --project=prj_… --mode=live --grace-hours=24   # 0 : révocation immédiate (secret compromis)
```

```
Nouveau secret de signature : whsec_…
L'ancien secret signe encore les webhooks pendant 24 h (deux signatures v1) ; le jeton de retour est signé avec le nouveau.
```
`--grace-hours=500` → `Erreur : Période de recouvrement invalide (0 à 168 heures).` (code retour 1).

**Rotation de `CRYPTO_KEY`** (trousseau versionné, octet de version de chaque chiffré) :
1. `.env` : nouvelle clé dans `CRYPTO_KEY`, `CRYPTO_KEY_VERSION=2`, ancienne clé dans `CRYPTO_PREVIOUS_KEYS=1:base64:…` ; redémarrer PHP-FPM et les workers ;
2. `php bin/reencrypt.php` (idempotent) : projets (secrets courants et en recouvrement), vérifications, sessions, livraisons de webhooks ;
3. file d'e-mails Redis vidée (travaux différés de quelques minutes au plus), puis retrait de l'ancienne clé de `CRYPTO_PREVIOUS_KEYS`.

```
projects.signing_secret_test_enc        0
projects.previous_secret_test_enc       0
projects.signing_secret_live_enc        0
projects.previous_secret_live_enc       0
verifications.email_enc                 0
verification_sessions.email_enc         0
webhook_deliveries.payload_enc          0
```
(sans rotation en cours : rien à réécrire ; `OperationsTest::testCryptoKeyRotationRewritesEveryCiphertext` couvre une rotation réelle.)
