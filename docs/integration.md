# Guide d'intégration VeriAge / VeriAge integration guide

> Référence de l'API v1 (phase 2). Français d'abord, puis anglais. Les versions NL et DE suivront
> (phase 9). Les exemples utilisent `https://verify.veriage.eu` ; remplacez-le par votre `VERIFY_URL`.
>
> API v1 reference (phase 2). French first, English below. NL and DE versions will follow (phase 9).

---

## Français

### 1. Principe

1. **Votre serveur** crée une session avec votre clé secrète (`POST /api/v1/sessions`).
2. **Le navigateur** de votre utilisateur ouvre la page de vérification : widget (modale, popup, iframe)
   ou redirection vers `verify_url`.
3. **Votre serveur** reçoit le résultat par **webhook signé**, et peut le consulter à tout moment
   (`GET /api/v1/verifications` ou `POST /api/v1/verifications/lookup`).

**Règle d'or : ne faites jamais confiance au navigateur.** Les événements du widget et le jeton de
retour servent à l'affichage. Seuls le webhook, dont la signature est vérifiée, et l'API, appelée
avec votre clé secrète, font foi.

Vous ne recevez jamais de nom, de photo ni de date de naissance. Seules les données suivantes vous sont
transmises : l'adresse e-mail, `is_adult`, `min_age`, `verified_at`, `method` et `expires_at`.

### 2. Clés, modes et secrets

| Élément | Forme | Usage |
|---|---|---|
| Clé sandbox | `sk_test_…` | Tests. Méthode simulée (vous choisissez le résultat), aucun e-mail envoyé, jamais facturé. |
| Clé production | `sk_live_…` | Vérifications réelles. |
| Secret de signature | `whsec_…` (un par mode) | Vérifier les webhooks (HMAC) et le jeton de retour (JWT HS256). |

- Les clés sont **secrètes**. Utilisez-les uniquement côté serveur, jamais dans une page web.
- En-tête d'authentification : `Authorization: Bearer sk_…` (le mot `Bearer` peut s'écrire en
  majuscules ou en minuscules).
- Les données de la sandbox et celles de la production sont strictement séparées.

### 3. Créer une session : `POST /api/v1/sessions`

```http
POST /api/v1/sessions
Authorization: Bearer sk_live_…
Content-Type: application/json
Idempotency-Key: 8f0c…            (facultatif, conseillé : une nouvelle tentative renvoie la même session)

{ "email": "user@exemple.be", "min_age": 18, "return_url": "https://boutique.example/retour",
  "lang": "fr", "external_ref": "user_4521" }
```

Champs du corps de la requête :

- `email` : obligatoire.
- `min_age` : 16, 18 ou 21. Facultatif ; à défaut, l'âge minimal du projet s'applique.
- `return_url` : obligatoire en mode redirection. Elle doit être en https et appartenir à l'un de vos
  domaines autorisés.
- `lang` : facultatif. Code de langue, par exemple `fr`.
- `external_ref` : facultatif. Votre identifiant interne, de 1 à 64 caractères parmi `A-Za-z0-9_.:-`.
  N'y mettez **aucune donnée personnelle** : il est renvoyé dans le webhook et le jeton.
- Tout champ inconnu est refusé (erreur `422`, code `unknown_field`).

En-tête `Idempotency-Key` (facultatif, 1 à 255 caractères parmi `A-Za-z0-9_.:-`, un UUID par exemple) :
pendant 24 h, un nouvel envoi de la même requête avec la même clé renvoie la même session, avec l'en-tête
`Idempotent-Replayed: true`. La même clé avec un autre corps : `422 idempotency_key_reused` ; requête
d'origine encore en cours : `409 idempotency_in_progress`. Une erreur n'est pas mémorisée : la clé peut
être réessayée. Les clés sont propres à chaque projet et à chaque mode.

Réponses :

- `201` : la session est créée et doit être suivie, `{ "session_id": "vs_…", "verify_url": "…",
  "expires_in": 1800, "status": "pending", … }`.
- `200` : **réutilisation immédiate**. Cette adresse a déjà été vérifiée pour vous, le résultat n'a pas
  expiré et il couvre l'âge demandé. La réponse contient `"status": "verified"`, `"reused": true` et
  un objet `verification`. Cette vérification n'est pas facturée.

### Méthode « pièce d'identité + visage » (`id_document_face`) : ce qu'elle garantit, et ce qu'elle ne garantit pas

**Niveau d'assurance : faible à modéré.** Analyse 100 % locale (aucun fournisseur tiers), sans certification.

Elle vérifie :
- une pièce d'identité (carte au format ID-1 ou page de passeport TD3) détectée sur la photo, avec le portrait
  à sa place ; la MRZ (chiffres de contrôle ICAO 9303) ; la concordance entre la face imprimée et la MRZ
  (date de naissance et numéro ou date d'expiration) : le recto et le verso doivent venir d'une même pièce ;
- l'âge exact et la validité du document ;
- la correspondance entre le visage filmé et le portrait du document ;
- un contrôle du vivant par défis aléatoires (tourner la tête, fermer les yeux, ouvrir la bouche ; 108 suites).

Elle arrête notamment : une simple photo à la place du recto, deux pièces différentes combinées (la carte d'un
mineur avec le verso de celle d'un parent), une photo fixe ou pivotée, une vidéo rejouée, le portrait du
document animé.

Elle **ne garantit pas** :
- **aucune certification** ISO/IEC 30107-3 (détection d'attaque de présentation) ni ISO/IEC 19795 ;
- **aucune détection d'injection** (caméra virtuelle, flux vidéo remplacé dans le navigateur) **ni de deepfake** :
  une autre photo de la personne, animée et injectée, ou un échange de visage en temps réel, **passe** ;
- **aucun contrôle des éléments de sécurité** du document (hologrammes, OVI, puce NFC) : un faux document
  cohérent (MRZ calculée, champs imprimés concordants) passe ;
- la ressemblance entre proches (frère ou sœur majeur) n'est pas mesurée : taux de fausse acceptation inconnu.

Ne présentez pas cette méthode comme conforme à un référentiel qui exige la résistance à l'injection ou une
certification ⚖️. Pour un besoin d'assurance élevé, préférez l'eID belge (phase 4).

**Sous le seuil de correspondance, la vérification échoue** (pas de revue manuelle en V1). Intervention humaine
(RGPD art. 22) : la personne peut toujours choisir une autre méthode sur la page, ou vous contacter ; vous
pouvez relancer une vérification.

### 4. Consulter un résultat

- `GET /api/v1/verifications?email=…&min_age=18`. **Encodez l'adresse dans l'URL** (`rawurlencode`,
  `encodeURIComponent`). Sans encodage, un `+` devient une espace et la requête échoue en 422.
- `POST /api/v1/verifications/lookup` avec le corps `{"email": "…", "min_age": 18}`. **Conseillé** :
  l'adresse n'apparaît alors ni dans l'URL ni dans les journaux.

Statuts renvoyés dans `status` :

| `status` | Signification |
|---|---|
| `verified` | La vérification a abouti. `is_adult` vaut `true` ou `false` : une personne trop jeune est « vérifiée » avec `is_adult: false`. |
| `pending` | Une session est en cours (`session_id`, `session_expires_at`). |
| `failed` | La dernière session a échoué. Motif dans `failure_reason` (voir §8). |
| `not_verified` | Aucun résultat valable. Cela couvre aussi un résultat expiré, et un résultat qui ne permet pas de conclure pour le `min_age` demandé. Si la dernière session a abouti mais que son résultat n'est plus réutilisable (par exemple un résultat négatif à validité 0 h), il est rappelé dans `last_session` (`session_id`, `status`, `is_adult`, `min_age`, `verified_at`, `expires_at`, `method`) : c'est une information, pas une vérification valable. |

**Durée de validité d'un résultat :**

- Un résultat **positif** (`is_adult: true`) reste valable pendant la durée réglée pour le projet,
  365 jours par défaut.
- Un résultat **négatif** (`is_adult: false`) reste valable peu de temps : **24 h par défaut**,
  réglable par projet de 0 à 720 h. La date de naissance n'étant jamais conservée, on ne sait pas quand
  la personne atteindra l'âge requis : elle doit pouvoir se faire revérifier. Avec 0, un résultat
  négatif n'est jamais réutilisé. Il n'est alors disponible que par le webhook et le jeton.
- Un **échec technique** n'est jamais réutilisé : chaque nouvelle session propose une nouvelle
  vérification.

`expires_at` est identique dans l'API, le webhook et le jeton de retour.

Âge demandé : une vérification faite pour 18 ans prouve aussi 16 ans, mais pas 21 ans. Une personne
« pas majeure à 18 ans » ne l'est pas non plus à 21 ans.

### 5. Effacement : `DELETE /api/v1/verifications?email=…`

Cette requête efface le résultat, les sessions et les webhooks liés à cette adresse, pour votre projet
et pour le mode de la clé utilisée. Elle est idempotente et renvoie `{"deleted": true|false}`.

### 6. Webhooks

Deux événements sont envoyés :

- `verification.completed` : le résultat est connu. Il est majeur ou mineur, selon `is_adult`.
- `verification.failed` : la vérification a échoué.

Exemple de corps :

```json
{ "id": "evt_…", "object": "event", "type": "verification.completed", "created": 1790000000,
  "livemode": true, "project": "prj_…",
  "data": { "session_id": "vs_…", "external_ref": "user_4521", "email": "user@exemple.be",
            "status": "verified", "is_adult": true, "min_age": 18, "verified_at": "…",
            "expires_at": "…", "method": "id_document_face", "reused": false, "failure_reason": null } }
```

En-têtes envoyés avec chaque webhook :

- `X-VeriAge-Signature: t=<unix>,v1=<hex>`. Pendant la rotation d'un secret, l'en-tête contient
  **deux** `v1` : acceptez la requête si l'un des deux correspond.
- `X-VeriAge-Event-Id`
- `X-VeriAge-Delivery-Attempt`

La signature vaut `v1 = HMAC-SHA256(secret, t + "." + corps_brut)`, où le corps est pris **octet pour
octet, avant tout décodage JSON**.

Pour chaque webhook reçu, votre serveur doit :

1. vérifier la signature, en temps constant ;
2. refuser si l'horodatage `t` est à plus de **300 s** de l'heure actuelle (protection anti-rejeu) ;
3. dédupliquer par `id`. Nos relances renvoient le même événement ;
4. répondre `2xx` rapidement.

En l'absence de `2xx`, nous relançons : 30 s, 1 min, 2 min…, avec un plafond de 6 h entre deux essais,
puis nous abandonnons après 10 tentatives.

PHP :

```php
function veriage_webhook_valid(string $body, string $header, string $secret, int $tolerance = 300): bool {
    $t = null; $sigs = [];
    foreach (explode(',', $header) as $part) {
        [$k, $v] = array_pad(explode('=', trim($part), 2), 2, '');
        if ($k === 't' && ctype_digit($v)) { $t = (int) $v; }
        if ($k === 'v1') { $sigs[] = $v; }
    }
    if ($t === null || abs(time() - $t) > $tolerance) { return false; }
    $expected = hash_hmac('sha256', $t . '.' . $body, $secret);
    foreach ($sigs as $sig) { if (hash_equals($expected, $sig)) { return true; } }
    return false;
}
// $body = file_get_contents('php://input'); puis déduplication par json_decode($body)->id.
```

Node.js :

```js
const crypto = require('crypto');
function veriageWebhookValid(rawBody, header, secret, tolerance = 300) {
  let t = null; const sigs = [];
  for (const part of header.split(',')) {
    const [k, v] = part.trim().split('=');
    if (k === 't' && /^\d+$/.test(v)) t = Number(v);
    if (k === 'v1') sigs.push(v);
  }
  if (t === null || Math.abs(Date.now() / 1000 - t) > tolerance) return false;
  const expected = crypto.createHmac('sha256', secret).update(`${t}.${rawBody}`).digest('hex');
  return sigs.some(s => s.length === expected.length && crypto.timingSafeEqual(Buffer.from(s), Buffer.from(expected)));
}
```

### 7. Jeton de retour (mode redirection et widget)

- **Où le trouver.** En mode redirection, il est ajouté à l'adresse de retour :
  `return_url?session_id=vs_…&token=<JWT>`. Avec le widget, il est dans `detail.token`.
- **Signature.** C'est un JWT HS256 signé avec le secret de signature du mode. Refusez tout autre
  algorithme.
- **Contrôles à faire côté serveur.** Vérifiez `aud` (votre identifiant de projet `prj_…`), `sub` (le
  `session_id`) et `exp` (5 minutes).
- **Claims.** `iss`, `aud`, `sub`, `iat`, `exp`, `jti`, `livemode`, `external_ref`, `status`,
  `is_adult`, `min_age`, `verified_at`, `expires_at`, `method`, `reused`. Le jeton ne contient jamais
  d'adresse e-mail.
- **Fenêtre d'émission.** Un jeton n'est émis que pendant **10 minutes** après la fin de la session.
  Ce délai est réglable par l'opérateur. Passé ce délai, la page affiche le résultat, mais sans jeton.
- **Deux règles à appliquer de votre côté :**
  1. Rattachez chaque `session_id` à l'utilisateur qui l'a créée, au moment de la création, et
     n'acceptez le retour que pour cet utilisateur.
  2. Consommez chaque `jti` **une seule fois**. Le jeton peut être réémis : rechargement de page,
     `postMessage` puis redirection.

  La démonstration (`/demo`) applique ces deux règles.

### 8. Codes d'erreur

Format de toute erreur : `{"error": {"code": "…", "message": "…", "details": {champ: code}}}`. Les
codes sont stables ; le message est traduit selon `Accept-Language`.

| HTTP | `code` | Cause |
|---|---|---|
| 400 | `bad_request`, `invalid_json` | Requête ou JSON invalide (le corps doit être un objet). |
| 401 | `unauthorized` | Clé absente, invalide ou révoquée (en-tête `WWW-Authenticate`). |
| 402 | `insufficient_credits` | Crédits épuisés (production ; phase 6). |
| 404 | `not_found` | Ressource inconnue. |
| 405 | `method_not_allowed` | Méthode HTTP non autorisée (en-tête `Allow`). |
| 409 | `idempotency_in_progress` | Même `Idempotency-Key` encore en cours (`Retry-After`). |
| 413 | `payload_too_large` | Corps de plus de 64 Kio. |
| 415 | `unsupported_media_type` | `Content-Type` différent de `application/json`. |
| 422 | `validation_failed` | Voir `details`. |
| 422 | `idempotency_key_reused` | Même clé d'idempotence avec un autre corps. |
| 429 | `rate_limited` | Trop de requêtes (`Retry-After`, `X-RateLimit-Limit`, `X-RateLimit-Remaining`). |
| 429 | `email_locked` | Trop d'échecs de vérification pour cette adresse en 24 h (`Retry-After`). |
| 500 | `server_error` | Erreur interne. |

Codes possibles dans `details` :

| Code | Signification |
|---|---|
| `required` | Champ absent. |
| `invalid` | Valeur invalide. `min_age` doit être un entier parmi 16, 18 et 21. |
| `unsupported` | Langue non disponible. |
| `unknown_field` | Champ non prévu, par exemple une faute de frappe. |
| `invalid_url` | URL mal formée. |
| `insecure_scheme` | L'URL n'est pas en https. |
| `credentials_not_allowed` | L'URL contient un identifiant ou un mot de passe. |
| `fragment_not_allowed` | L'URL contient un fragment `#…`. |
| `private_address` | L'URL pointe vers une adresse IP privée ou réservée. |
| `origin_not_allowed` | Le domaine ne fait pas partie de vos domaines autorisés. |

Motifs d'échec, dans `failure_reason` :

| Motif | Signification |
|---|---|
| `code_attempts_exceeded` | Trop de codes erronés pour le contrôle de l'adresse. |
| `mock_failure` | Échec simulé (sandbox). |
| `document_unreadable` | Pièce d'identité + visage : MRZ illisible ou chiffres de contrôle faux. |
| `document_inconsistent` | Les photos ne forment pas une même pièce : recto sans document, portrait absent de sa place, champs imprimés différents de la MRZ, type de document ≠ type de MRZ. |
| `document_unsupported` | Document non pris en charge (ancienne carte d'identité française) : passeport ou autre méthode. |
| `document_expired` | Document expiré. |
| `liveness_failed` | Contrôle du vivant échoué (défis non réalisés, visage changé, rejeu suspecté). |
| `face_not_found` | Aucun visage sur la photo du document. |
| `face_mismatch` | Le visage ne correspond pas à la photo du document. |
| `capture_rejected` | Images refusées par l'analyse. |
| `capture_attempts_exceeded` | Trop de tirages de défis pour la session. |

Une panne du service d'analyse (arrêté, saturé, délai dépassé) n'est **pas** un échec : aucune décision,
aucun webhook ; la session reste ouverte et la personne recommence (dans la limite des tirages de défis).

D'autres motifs arriveront avec l'eID (phase 4).

### 9. Widget

```html
<script src="https://verify.veriage.eu/widget/verify.js" data-session="vs_…" data-lang="auto"></script>
```

**Attributs du script :**

- `data-session` : identifiant de la session à ouvrir.
- `data-mode` : `modal` par défaut, ou `popup`, `iframe`, `redirect`.
- `data-lang` : langue de la page, `auto` ou un code de langue.
- `data-autoopen="false"` : la page ne s'ouvre qu'au clic.
- `data-target` : conteneur du mode iframe (sélecteur CSS).
- `data-trigger` : élément qui ouvre la page au clic (sélecteur CSS).

**API JavaScript :** `VeriAge.open({session, mode, lang, target})` et `VeriAge.close()`.

**Événements, émis sur `window` :**

- `veriage:opened`
- `veriage:completed` : `detail` contient `session_id`, `status`, `is_adult`, `min_age`,
  `verified_at`, `expires_at`, `method` et `token`.
- `veriage:failed`
- `veriage:closed`

**Contraintes pour votre page :**

- **CSP** : autorisez l'hôte du module dans `script-src`, `connect-src` et `frame-src`.
- **Permissions-Policy** : incluez `camera=(self "https://verify.veriage.eu")`. Sinon la caméra sera
  refusée dans l'iframe (phase 3).
- **Popup** : n'utilisez pas `Cross-Origin-Opener-Policy: same-origin` ; préférez
  `same-origin-allow-popups`. Sinon le popup ne peut pas vous répondre.
- **Domaines autorisés** : votre page doit être servie depuis l'un de vos domaines autorisés, sinon le
  cadrage est refusé et aucun événement n'arrive.

**Comportement du widget :**

- Sur mobile, la modale et l'iframe s'affichent en plein écran.
- La modale est accessible au clavier : focus confiné, reste de la page inerte, Échap pour fermer,
  focus rendu à l'élément d'origine.
- Le popup reste ouvert jusqu'à ce que la personne le ferme.

---

## English

### 1. Overview

1. **Your server** creates a session with your secret key (`POST /api/v1/sessions`).
2. **The user's browser** opens the verification page: widget (modal, popup, iframe) or redirect to
   `verify_url`.
3. **Your server** receives the result through a **signed webhook**, and can query it at any time
   (`GET /api/v1/verifications` or `POST /api/v1/verifications/lookup`).

**Golden rule: never trust the browser.** Widget events and the return token are for display only.
Only the webhook, once its signature is checked, and the API, called with your secret key, are
authoritative.

You never receive a name, photo or date of birth. Only the following is sent to you: the email address,
`is_adult`, `min_age`, `verified_at`, `method` and `expires_at`.

### 2. Keys, modes and secrets

| Item | Form | Use |
|---|---|---|
| Sandbox key | `sk_test_…` | Testing. Simulated method (you pick the result), no email sent, never billed. |
| Live key | `sk_live_…` | Real verifications. |
| Signing secret | `whsec_…` (one per mode) | Verify webhooks (HMAC) and the return token (JWT HS256). |

- Keys are **secret**. Use them server-side only, never in a web page.
- Authentication header: `Authorization: Bearer sk_…` (the word `Bearer` may be upper or lower case).
- Sandbox data and live data are strictly separated.

### 3. Create a session: `POST /api/v1/sessions`

Request body fields:

- `email`: required.
- `min_age`: 16, 18 or 21. Optional; defaults to the project's minimum age.
- `return_url`: required in redirect mode. It must use https and belong to one of your allowed domains.
- `lang`: optional. Language code, e.g. `fr`.
- `external_ref`: optional. Your internal identifier, 1 to 64 characters from `A-Za-z0-9_.:-`. Put
  **no personal data** in it: it is sent back in the webhook and the token.
- Unknown fields are rejected (`422` error, code `unknown_field`).
- `Idempotency-Key` header: optional but recommended (1 to 255 characters from `A-Za-z0-9_.:-`, e.g. a
  UUID). For 24 h, resending the same request with the same key returns the same session, with the
  `Idempotent-Replayed: true` header. Same key with another body: `422 idempotency_key_reused`; original
  request still running: `409 idempotency_in_progress`. Errors are not remembered: the key can be retried.
  Keys are scoped to each project and each mode.

Responses:

- `201`: session created, to be followed up.
- `200`: **immediate reuse**. This address was already verified for you, the result has not expired
  and it covers the requested age. The response contains `"status": "verified"`, `"reused": true` and
  a `verification` object. This verification is not billed.

### "Identity document + face" method (`id_document_face`): what it guarantees, and what it does not

**Assurance level: low to moderate.** 100 % local analysis (no third-party provider), not certified.

It checks:
- an identity document (ID-1 card or TD3 passport page) detected in the photo, with the portrait in its place;
  the MRZ (ICAO 9303 check digits); that the printed side matches the MRZ (date of birth and document number or
  expiry date): front and back must come from the same document;
- the exact age and the document's validity;
- that the filmed face matches the document portrait;
- a liveness check with random challenges (turn your head, close your eyes, open your mouth; 108 sequences).

It stops in particular: a plain photo instead of the front, two different documents combined (a minor's card
with the back of a parent's), a still or rotated photo, a replayed video, the document portrait animated.

It **does not guarantee**:
- **no certification** under ISO/IEC 30107-3 (presentation attack detection) or ISO/IEC 19795;
- **no injection detection** (virtual camera, video stream replaced in the browser) **and no deepfake
  detection**: another photo of the person, animated and injected, or a real-time face swap, **passes**;
- **no check of the document's security features** (holograms, OVI, NFC chip): a consistent forged document
  (computed MRZ, matching printed fields) passes;
- resemblance between relatives (an adult sibling) is not measured: false acceptance rate unknown.

Do not present this method as compliant with a framework that requires injection resistance or a
certification ⚖️. For a high assurance need, prefer the Belgian eID (phase 4).

**Below the match threshold, the verification fails** (no manual review in V1). Human intervention (GDPR
Art. 22): the person can always choose another method on the page, or contact you; you can start a new
verification.

### 4. Query a result

- `GET /api/v1/verifications?email=…&min_age=18`. **URL-encode the address**. Without encoding, `+`
  becomes a space and the request fails with a 422.
- `POST /api/v1/verifications/lookup` with the body `{"email": "…", "min_age": 18}`. **Recommended**:
  the address then appears neither in the URL nor in logs.

Values of `status`:

| `status` | Meaning |
|---|---|
| `verified` | The verification succeeded. `is_adult` is `true` or `false`: a person who is too young is "verified" with `is_adult: false`. |
| `pending` | A session is in progress (`session_id`, `session_expires_at`). |
| `failed` | The last session failed. Reason in `failure_reason` (see §8). |
| `not_verified` | No valid result. This also covers an expired result, and a result that does not settle the requested `min_age`. If the last session completed but its result can no longer be reused (e.g. a negative result with a 0 h validity), it is given in `last_session` (`session_id`, `status`, `is_adult`, `min_age`, `verified_at`, `expires_at`, `method`): information only, not a valid verification. |

**How long a result stays valid:**

- A **positive** result (`is_adult: true`) stays valid for the project's validity period, 365 days by
  default.
- A **negative** result (`is_adult: false`) stays valid only briefly: **24 h by default**, configurable
  per project from 0 to 720 h. The date of birth is never stored, so we cannot know when the person will
  reach the required age: they must be able to get verified again. With 0, a negative result is never
  reused. It is then only available through the webhook and the token.
- A **technical failure** is never reused: every new session offers a new verification.

`expires_at` is the same in the API, the webhook and the return token.

Requested age: a verification done for 18 also proves 16, but not 21. A person who is "not adult at 18"
is not adult at 21 either.

### 5. Erasure: `DELETE /api/v1/verifications?email=…`

This request erases the result, the sessions and the webhooks tied to that address, for your project
and for the mode of the key used. It is idempotent and returns `{"deleted": true|false}`.

### 6. Webhooks

Two events are sent:

- `verification.completed`: the result is known. It is adult or not, according to `is_adult`.
- `verification.failed`: the verification failed.

Headers sent with each webhook:

- `X-VeriAge-Signature: t=<unix>,v1=<hex>`. During a secret rotation, the header holds **two** `v1`
  values: accept the request if either one matches.
- `X-VeriAge-Event-Id`
- `X-VeriAge-Delivery-Attempt`

The signature is `v1 = HMAC-SHA256(secret, t + "." + raw_body)`, where the body is taken **byte for
byte, before any JSON parsing**.

For each webhook received, your server must:

1. check the signature, in constant time;
2. reject it if the timestamp `t` is more than **300 s** away from the current time (replay protection);
3. deduplicate by `id`. Our retries send the same event again;
4. answer `2xx` quickly.

Without a `2xx`, we retry: 30 s, 1 min, 2 min…, capped at 6 h between attempts, then we give up after
10 attempts. PHP and Node.js examples are in §6 of the French section above.

### 7. Return token

- **Where to find it.** In redirect mode, it is appended to the return address:
  `return_url?session_id=vs_…&token=<JWT>`. With the widget, it is in `detail.token`.
- **Signature.** It is a JWT HS256 signed with the mode's signing secret. Reject any other algorithm.
- **Server-side checks.** Check `aud` (your `prj_…` project ID), `sub` (the `session_id`) and `exp`
  (5 minutes).
- **No email.** The token never contains an email address.
- **Issuing window.** A token is only issued for **10 minutes** after the session ends. The operator can
  change this delay. After it, the page shows the result, but without a token.
- **Two rules to apply on your side:**
  1. Tie each `session_id` to the user who created it, at creation time, and accept the return only for
     that user.
  2. Consume each `jti` **only once**. The token may be issued again: page reload, `postMessage` then
     redirect.

  The demo (`/demo`) applies both rules.

### 8. Error codes

The error format, the codes, the `details` codes and the `failure_reason` values are the same as in §8
of the French section above. Codes are stable; messages are translated according to `Accept-Language`.

### 9. Widget

- **Script attributes:** the same as in §9 of the French section above.
- **Events, emitted on `window`:** `veriage:opened`, `veriage:completed`, `veriage:failed` and
  `veriage:closed`. For `veriage:completed`, `detail` contains `session_id`, `status`, `is_adult`,
  `min_age`, `verified_at`, `expires_at`, `method` and `token`.
- **Constraints for your page:**
  - **CSP**: allow the module host in `script-src`, `connect-src` and `frame-src`.
  - **Permissions-Policy**: include `camera=(self "https://verify.veriage.eu")`.
  - **Popup**: do not use `Cross-Origin-Opener-Policy: same-origin`; prefer `same-origin-allow-popups`.
  - **Allowed domains**: your page must be served from one of your allowed domains.
- **Widget behaviour:**
  - On mobile, the modal and the iframe are shown full screen.
  - The modal is keyboard accessible: focus is contained, the rest of the page is inert, Escape closes
    it, and focus returns to where it was.
