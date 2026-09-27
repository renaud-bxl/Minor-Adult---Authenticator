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

Jeton CSRF récupéré sur le formulaire, puis six tentatives avec la même adresse :

```bash
TOKEN=$(curl -s -c jar $B/fr/login | grep -o 'name="_token" value="[a-f0-9]*"' | head -1 | sed 's/.*value="//;s/"//')
for i in 1 2 3 4 5 6; do
  curl -s -b jar -c jar -o /dev/null -D hdr -w "tentative $i : %{http_code}\n" \
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

Limites (config/security.php) : 5 tentatives / 15 min par adresse e-mail, 30 / 15 min par IP.
Le message d'échec est identique pour une adresse inconnue et un mauvais mot de passe.

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

### Parcours complet

Inscription → e-mail de validation → validation → connexion → mot de passe oublié → réinitialisation
→ invalidation des autres sessions : couvert de bout en bout par `tests/Integration/AuthFlowTest.php`
(MariaDB + Redis réels, e-mails lus dans la boîte d'envoi du pilote `log`).
