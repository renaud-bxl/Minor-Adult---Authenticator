VERDICT : REJETÉ

# Phase 2 (API et module) : audit du critique

Date : 2026-09-27. Périmètre : `git diff 0acf602..10909ab` (créateur `3fee3ad`, contrôleur jusqu'à `10909ab`),
rapport `tasks/reviews/phase-2-controle.md`, au regard de `CLAUDE.md`, `tasks/lessons.md`, `tasks/todo.md` et du
cahier des charges (sections 1, 3 et 7).

Appréciation d'ensemble : travail sérieux et, sur la sécurité, souvent remarquable (SSRF avec connexion
épinglée, page hébergée sans cookie, `postMessage` strict dans les deux sens, bail de réservation des webhooks,
idempotence sans adresse dans Redis, trousseau `CRYPTO_KEY`). Le rejet porte sur trois défauts réels, tous
vérifiés en exécution : une règle métier qui prive pendant un an un jeune devenu majeur de toute nouvelle
vérification, et deux défauts du widget (accessibilité clavier de la modale, double bouton de fermeture en mode
iframe sur mobile). Les corrections sont courtes et localisées.

## Ce qui a été vérifié (et non cru sur parole)

- `vendor/bin/phpunit` : **OK (250 tests, 1 491 assertions)**, 6,5 s, PHP 8.4.19.
- `php tools/check_translations.php` : **100 % fr (265/265) et en (265/265)**, 259 clés citées, 0 inconnue.
  Grep des vues du module, de la démo et du JS : aucune chaîne en dur (hors `×`, emoji décoratif `aria-hidden`
  et une commande shell dans la démo).
- `python3 tools/e2e_demo.py` (Chromium réel `/opt/pw-browsers/chromium-1194/chrome-linux/chrome`, captures dans
  un dossier temporaire) : **20/20**. Captures relues : consentement, code, méthode et résultat (FR/EN,
  desktop/mobile, clair/sombre), iframe mineur, popup échec, retour avec jeton, modale plein écran mobile.
- Scripts Playwright supplémentaires (hors dépôt) : parcours clavier de la modale ; captures du mode iframe
  et du mode modale à 390 px.
- Serveur `:8000` / `:8001` + worker `--id=dev` : parcours cURL complets sur un projet créé pour l'audit.
  - Erreurs : 415 (corps absent ou formulaire), 422 avec `details` (champ inconnu, `min_age` en chaîne, e-mail,
    origine non autorisée), 401 + `WWW-Authenticate`, 404 et 405 (+ `Allow`) en JSON, messages traduits
    (Accept-Language).
  - Page hébergée : CSP `frame-ancestors 'self' http://127.0.0.1:8001`, COOP `unsafe-none`, CORP `cross-origin`,
    `Permissions-Policy: camera=(self)`, aucun `Set-Cookie`, aucun `X-Frame-Options` ; origine non autorisée ou
    forgée (`"><script>`) : pas de `data-parent-origin` ; POST sans `_state` → 419 ; `/return` d'une session en
    cours → 303 vers la page ; session inconnue → 404 ; `?notice=` hors liste blanche ignoré.
  - `return_url` : `\@evil.com` → `credentials_not_allowed`, `javascript:` → refus, fragment → refus.
  - Effacement : `DELETE` → `deleted: true` ; `GET` → `not_verified` ; `/s/{id}/return` → 404.
- Lecture intégrale : `VerificationService`, `HostedPageController`, vues et layout `verify`, `verify.js`,
  `verify-page.js`, `UrlGuard`, `WebhookDispatcher`, `WebhookSignature`, `WebhookDeliveryRepository`,
  `ReturnToken`, `PageToken`, `AuthenticateApiKey`, `ThrottleVerifyPage`, `DemoController`, migrations 0008,
  0009, 0011 et 0012, `config/verification.php`, `deploy/`.
- Données de test créées par l'audit supprimées : 2 comptes, 2 projets (clés, endpoint et sessions en cascade),
  16 sessions, 7 vérifications, 9 livraisons et 50 lignes d'audit (E2E compris). Les clés Redis de la démo et
  des limiteurs expirent d'elles-mêmes, en une heure au plus.

## Grille

| # | Axe | Verdict | Commentaire |
|---|---|---|---|
| 1 | Conformité (cahier des charges + CLAUDE.md) | **À CORRIGER (bloquant)** | Contrat §3.1 respecté (sessions, 3 canaux de retour, 4 statuts). Mais un résultat « mineur » est réutilisé pendant toute la validité (365 j) : E1. Mode iframe sur mobile défectueux (§3.4) : E3. |
| 2 | Sécurité (ASVS L2) | OK | SSRF, anti-rebinding, JWT HS256 figé, HMAC des webhooks, `_state`, IDOR, force brute du code bornée. Réserves traitées en recommandations (R1 à R3). |
| 3 | RGPD | OK | Aucune donnée d'identité stockée ni transmise ; e-mail haché (sel par projet + poivre) et chiffré ; `shared_hash` seulement sur consentement ; effacement complet (livraisons comprises). À documenter : R17. |
| 4 | Qualité / architecture | OK | Découpage net, transitions atomiques, écritures conditionnées au jeton. `VerificationService` (851 lignes) reste lisible. |
| 5 | Tests | OK (avec manques) | 250 tests verts, E2E 20/20. Rien ne couvre les défauts E1 à E3 : les tests demandés sont listés dans chaque exigence. |
| 6 | i18n | OK | 100 %, aucune chaîne en dur. |
| 7 | UX / accessibilité WCAG AA / responsive | **À CORRIGER (bloquant)** | Page hébergée de bonne facture (contrastes, libellés, `fieldset`, `role=alert`). Modale du widget non conforme au clavier : E2. Double bouton de fermeture sur mobile : E3. |
| 8 | Déploiement HestiaCP isolé | OK | Unité systemd durcie et dédiée, crontab à installer via `v-add-cron-job` ; points de la phase 0 : R18. |

## Exigences bloquantes

### E1. Un résultat « âge non atteint » est réutilisé pendant toute la durée de validité (365 jours)

- **Où** : `app/Verification/VerificationService.php:209` et `:444-448` (réutilisation automatique via
  `reusableVerification`) ; `:525-526` (`expires_at` = `validityDays` pour un résultat positif comme négatif) ;
  même effet pour une preuve partagée (`:496`).
- **Constat, en exécution** : après un résultat simulé « mineur », `POST /api/v1/sessions` pour la même adresse
  répond immédiatement `200` avec `reused: true`, `is_adult: false` et `expires_at` à J+365. Aucune page n'est
  proposée à l'utilisateur.
- **Pourquoi c'est bloquant** : on ne conserve pas la date de naissance (à juste titre), donc on ne sait pas
  quand la personne devient majeure. Un jeune vérifié à 17 ans et 11 mois reste « mineur » pour ce client
  pendant un an. Il ne peut pas se faire revérifier, et le client ne peut rien y faire, sauf à deviner qu'il
  faut appeler `DELETE`. Le cahier des charges (§3.3) ne demande la réutilisation que tant que la vérification
  « n'est pas expirée » : c'est l'expiration d'un résultat négatif qui est mal réglée.
- **Attendu** :
  - donner à un résultat négatif une durée de validité courte et configurable, par exemple
    `verification.negative_result_ttl_days`, au plus 30 jours par défaut. Avec la valeur 0, un résultat
    négatif n'est jamais réutilisé automatiquement ;
  - appliquer la même règle à la copie partagée ;
  - garder `expires_at` cohérent dans l'API, le webhook et le JWT ;
  - tests d'intégration : un négatif n'est pas réutilisé au-delà du délai, un positif l'est toujours ;
  - une ligne dans `docs/api-tests.md`.

### E2. Modale du widget : aucun confinement du focus, arrière-plan actif, Échap inopérant dans le cadre

- **Où** : `public/widget/verify.js:120-174` (`openOverlay`) ; `public/assets/js/verify-page.js`, qui ne gère
  pas Échap.
- **Constat, avec Playwright (Chromium)** : modale ouverte, focus sur l'iframe. Tab parcourt la page hébergée
  puis le bouton « Fermer ». La tabulation sort ensuite de la modale et atteint `BODY`, les liens, puis
  `#demo-email`, `#demo-age`, `#demo-create` et `#demo-open` de la page du client, cachés sous le voile. Aucun
  frère de l'overlay n'est `inert` ni `aria-hidden`. Échap pressé pendant que le focus est dans l'iframe (cas
  normal) : la modale reste ouverte et aucun `veriage:closed` n'est émis. L'écouteur `keydown` est posé sur le
  document parent, qui ne reçoit pas les touches d'un cadre d'une autre origine.
- **Pourquoi c'est bloquant** : c'est le mode par défaut, embarqué tel quel par tous les clients. La modale
  annonce `aria-modal="true"`, mais le focus clavier atteint un contenu masqué. Cela contrevient à WCAG 2.4.3
  (ordre du focus) et 2.4.11 (focus masqué), ainsi qu'au motif « dialog » de l'APG, que la grille exige
  (WCAG AA).
- **Attendu** :
  - pendant l'ouverture, poser `inert` (avec `aria-hidden="true"` en repli) sur les enfants de `body` autres que
    l'overlay, et restaurer l'état d'origine au `teardown` ;
  - dans `verify-page.js`, en mode `modal` et en superposition mobile, sur Échap, envoyer
    `send({ type: 'close' })` : le widget sait déjà le traiter ;
  - contrôle E2E : après N tabulations, le focus reste dans l'overlay ; Échap pressé dans l'iframe ferme la
    modale et émet `veriage:closed`.

### E3. Mode `iframe` sur mobile : deux boutons de fermeture superposés

- **Où** : `public/widget/verify.js:99-107` et `:292-297`. L'URL est construite avec `embed=iframe` avant de
  savoir que l'affichage sera une superposition plein écran. Côté CSS, `public/assets/css/app.css` ne masque le
  bouton de la page et ne réserve la place que pour `data-embed="modal"`.
- **Constat** : à 390 px en mode `iframe`, le « × » du widget recouvre partiellement le « × » de la page, à
  droite du sélecteur de langue. La capture montre les deux boutons l'un sur l'autre. En mode `modal`,
  l'affichage est correct.
- **Pourquoi c'est bloquant** : c'est un défaut visible sur mobile dans l'un des quatre modes, alors que le
  cahier des charges (§3.4) impose le plein écran sur mobile. On obtient deux cibles tactiles chevauchantes
  pour la même action (WCAG 2.5.8). L'E2E ne le détecte pas.
- **Attendu** :
  - déterminer la présentation réelle (superposition ou cadre en ligne) avant de construire l'URL, et passer
    `embed=modal` quand le widget affiche une superposition ;
  - ou, à défaut, faire suivre ce cas par la page par un paramètre dédié ;
  - capture E2E du mode iframe à 390 px, avec une assertion : un seul contrôle de fermeture visible.

## Les trois questions ouvertes du contrôleur : décisions

### Q1. Plafond global de codes erronés par adresse, tous clients confondus

**Recommandation : oui, mais sans blocage de l'adresse. Au-delà du seuil global, on exige une preuve plus
forte. À faire avant l'ouverture de la réutilisation entre sites en production (phase 3).**

- **Menace** : s'approprier la vérification partagée d'un tiers en devinant son code, via plusieurs sites
  clients. Aujourd'hui, avec 10 codes par jour et par projet, la probabilité de succès vaut environ
  10 × K / 10⁶ par jour pour K sites qui acceptent le partage. Chaque tentative oblige aussi à faire envoyer un
  code au vrai titulaire, ce qui est bruyant. Le risque est donc faible, mais il croît avec le nombre de
  clients.
- **Pourquoi pas un blocage global** : un tiers pourrait priver une adresse de toute vérification, sur tous les
  sites, pendant 24 h. Ce déni de service est plus grave que le risque qu'il combat.
- **Mise en œuvre proposée** :
  - un compteur global, en production seulement, clé = hash partagé ou HMAC global de l'adresse ;
  - au-delà d'environ 20 codes erronés en 24 h, tous clients confondus, cette adresse ne reçoit plus un code à
    6 chiffres mais une preuve à forte entropie : lien magique à usage unique, ou code alphanumérique de
    10 caractères ;
  - pas de blocage ;
  - le plafond par projet et le verrouillage par projet actuels restent en place ;
  - une trace d'audit ou une alerte d'exploitation quand le seuil est franchi.
- **Pas bloquant pour la phase 2** : en production, aucune preuve partageable n'existe avant la phase 3.

### Q2. Jeton de retour à usage unique

**Recommandation : non, pas d'usage unique imposé côté VeriAge. En revanche, il faut borner la période
pendant laquelle un jeton peut être émis, et documenter l'usage unique côté client.**

- **Pourquoi pas l'usage unique** : il casserait les usages légitimes (rechargement de la page de résultat,
  retour arrière, envoi du même résultat par `postMessage` et par redirection). Surtout, il ne traite pas le
  vrai risque : un détenteur illégitime de l'identifiant de session peut consommer le premier jeton.
- **Le vrai risque** : un client qui attribue le résultat au navigateur qui revient, plutôt qu'à l'utilisateur
  qui a créé la session. La parade est de son côté : relier `sub` (session) à son utilisateur au moment de la
  création, et consommer chaque `jti` une seule fois.
- **À faire côté VeriAge** :
  - aujourd'hui, `/s/{id}/return` et la page de résultat émettent des jetons jusqu'à la purge de la session,
    soit 30 jours. N'émettre un jeton que pendant une courte fenêtre après la fin de la session (par exemple
    30 min, ou jusqu'à `expires_at`). Au-delà, afficher le résultat sans jeton ;
  - faire de la démo l'implémentation de référence : déduplication de `jti` et contrôle que la session
    appartient bien à l'utilisateur ;
  - documenter ces deux règles dans le guide d'intégration.

### Q3. Limite de 60 saisies de code par heure et par IP, derrière un NAT

**Recommandation : la limite est trop basse pour la production. La relever, la rendre configurable et la
relier au projet. Pas bloquant aujourd'hui, mais à régler avant l'ouverture de la production.**

- **Pourquoi elle est trop basse** : elle est globale (tous clients confondus). Or les réseaux mobiles européens
  font largement de la CGNAT IPv4 : des milliers d'abonnés partagent une même IP, et les écoles ou entreprises
  aussi. À l'échelle d'un service multi-clients, 60 saisies par heure seront atteintes par des utilisateurs
  légitimes. `verify_page_ip` (120 req/min, soit environ 15 parcours par minute et par IP) pose le même
  problème.
- **Ce que la limite protège vraiment** : la force brute par adresse est déjà bornée par la session (5 essais)
  et par l'adresse (10 en 24 h). La limite par IP ne sert qu'à freiner l'automatisation.
- **Mise en œuvre proposée** :
  - clé (IP, projet) plutôt qu'IP seule ;
  - environ 300 saisies par heure ;
  - valeurs lues depuis `.env`. Aujourd'hui elles sont figées dans `config/security.php:69-73`, et les changer
    impose un déploiement ;
  - des métriques de déclenchement pour ajuster ces seuils (phase 10).

## Recommandations non bloquantes (18)

**R1 à R3.** Les réponses aux questions Q1, Q2 et Q3 ci-dessus.

**R4. Référence de l'API pour les intégrateurs, dès maintenant.** Le contrat est désormais figé, mais seuls
`docs/api-tests.md` et les commentaires de code le décrivent. `ErrorRenderer` affirme des codes « stables et
documentés », or aucun catalogue n'existe. Il manque :
- les codes d'erreur et les codes de `details` (`unknown_field`, `origin_not_allowed`, `insecure_scheme`…) ;
- les valeurs de `failure_reason` (`mock_failure`, `code_attempts_exceeded`…) ;
- le schéma du webhook et un extrait de vérification de la signature (avec les deux `v1` pendant une rotation) ;
- les claims du JWT ;
- les attributs et événements du widget ;
- l'obligation d'encoder l'adresse dans l'URL (sinon `+` devient une espace et la requête échoue en 422).

`docs/integration.md` (phase 9) pourra partir de cette référence.

**R5. `GET /api/v1/verifications` : la sémantique de `is_adult` dépend de `min_age`.** Un client qui crée une
session à 16 ans, puis consulte l'API pour de l'alcool, lit `is_adult: true` avec `min_age: 16`. Deux
améliorations :
- ajouter un paramètre facultatif `min_age`, avec la logique de `covers()` : `not_verified` si le résultat
  stocké ne couvre pas l'âge demandé ;
- offrir une variante `POST /api/v1/verifications/lookup` avec un corps JSON. Elle retire l'adresse de l'URL,
  donc des journaux, et supprime le piège du `+`.

**R6. En-tête `Authorization` : le schéma doit être insensible à la casse** (RFC 7235). `bearer sk_…` est
aujourd'hui refusé en 401 (`AuthenticateApiKey.php`, regex `^Bearer`).

**R7. Des `details` d'erreur plus parlants.**
- `min_age` → `invalid` sans dire « entier parmi 16, 18, 21 » ;
- `http://hôte:8001:80/x` → `insecure_scheme`, trompeur : il faudrait `invalid_url` ;
- `email[]=` → `required` au lieu de `invalid`.

**R8. Accessibilité de la page hébergée.**
- Après un code erroné, poser `aria-invalid="true"` sur le champ, et relier l'erreur par `aria-describedby`.
  Aujourd'hui, `autofocus` place le lecteur d'écran sur le champ sans lui faire lire l'erreur.
- L'état « terminée » des étapes n'est transmis ni visuellement (`--brand-soft` #e7eefe, proche de la bordure
  #dde3ee) ni en texte : ajouter un texte masqué et une couleur contrastée (WCAG 1.4.11).
- Donner un `<title>` propre à chaque étape (WCAG 2.4.2).

**R9. Popup refermé 1,5 s après le résultat** (`verify.js:238-241`) : trop court pour lire le résultat,
surtout avec un lecteur d'écran. Allonger le délai, ou laisser le client fermer le popup.

**R10. Mode `redirect` sans `return_url` : la page de résultat est une impasse**, sans lien ni consigne.
Documenter que `return_url` est obligatoire dans ce mode, ou afficher « vous pouvez fermer cette page ».

**R11. Échecs silencieux côté intégrateur.**
- Origine de la page absente des domaines autorisés, en mode popup : aucun événement n'arrive. En sandbox,
  afficher un avertissement sur la page, ou dans la console côté widget.
- Même chose quand `data-target` est introuvable : le widget bascule sur la modale sans rien signaler.

**R12. Code épuisé** (`CODE_LOCKED`) : l'utilisateur voit l'échec générique sans en connaître la raison.
Afficher un message dédié.

**R13. `findShared`** (`VerificationRepository.php:36-45`) : la requête prend la preuve la plus récente
(`LIMIT 1`), puis `covers()` la filtre. Une preuve plus ancienne mais valable est donc ignorée. Filtrer l'âge
dans la requête SQL.

**R14. Événements du widget** : ajouter `min_age` au `detail` de `veriage:completed`, par cohérence avec le JWT
et l'API.

**R15. En-tête `X-RateLimit-Reset`** (ou `RateLimit-Reset`) absent : l'intégrateur ne sait pas quand la
limite se réinitialise.

**R16. Cible PHP** : la plateforme Composer est figée à 8.2, mais les tests tournent en 8.4. Ajouter une
exécution en PHP 8.2 réel (CI ou phase 10). Le changement `CURLOPT_PROTOCOLS_STR` relevé par le contrôleur
s'y rattache.

**R17. RGPD** : `external_ref` (identifiant client pseudonyme) est conservé 30 jours et transmis dans le JWT et
le webhook. Le mentionner dans le registre, et créer `docs/rgpd.md` avec la liste des points pour le juriste.
Ce fichier, exigé par le cahier des charges §7, n'existe pas encore.

**R18. Phase 0.**
- `veriage-worker@.service` : `ExecStart=/usr/bin/php` doit viser la version PHP du pool Hestia
  (`/usr/bin/php8.x`) ; `After=redis-server.service` doit viser l'instance Redis dédiée au projet.
- Purge : le cahier des charges exigera, dès la phase 3, un passage toutes les 15 minutes pour les fichiers
  temporaires.

## Pour la reprise

Corriger E1 à E3 avec leurs tests. Relancer ensuite phpunit, `check_translations`, puis l'E2E enrichi :
clavier de la modale et mode iframe à 390 px. Mettre à jour `tasks/todo.md`. Nouvel audit du critique ensuite.
