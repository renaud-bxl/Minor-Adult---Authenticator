VERDICT : REJETÉ

# Phase 1 : audit critique

Date : 2026-09-27. Base auditée : commit `b035f16` (créateur `876931d` + contrôleur), branche `claude/great-bell-f3kkw4`.
Référentiels : `docs/cahier-des-charges.md`, `CLAUDE.md`, `tasks/lessons.md`, OWASP ASVS 4.0.3 niveau 2 (périmètre :
authentification, sessions, contrôle d'accès, validation, en-têtes, journaux), WCAG 2.1 AA.

Appréciation d'ensemble : fondations **nettement au-dessus de la moyenne** (requêtes préparées partout, CSP réellement
sans inline, jetons SHA-256 à usage unique consommés atomiquement, sessions Redis JSON en mode strict sans résurrection,
anti-énumération soignée, rate limiting à trois compteurs, journaux expurgés, i18n outillée, 118 tests pertinents).
Le rejet porte sur **quatre écarts précis et peu coûteux à corriger**, dont deux exigences normatives explicitement
visées par la grille (ASVS niveau 2, WCAG AA). Aucune refonte n'est demandée.

## Vérifications exécutées par le critique

| Contrôle | Résultat |
|---|---|
| `vendor/bin/phpunit` | OK (118 tests, 443 assertions), 2,3 s |
| `php tools/check_translations.php` | fr 100 % (135/135), en 100 % (135/135), 129 clés citées, 0 inconnue, code retour 0 |
| `php bin/migrate.php` sur base vierge `veriage_crit` | `--status` : 5 en attente ; `migrate` : 5 appliquées ; relance : « Base à jour » (idempotent) |
| Parcours cURL (`php -S`, base vierge, Redis base 7) | inscription 302 → e-mail `.eml` → validation 302 → réutilisation du lien 400 → connexion 302 (identifiant de session régénéré) → tableau de bord 200 → déconnexion sans jeton 419, en GET 405 (`Allow: POST`), avec jeton 302 → cookie volé après déconnexion 302 `/login` → mot de passe oublié (adresse connue et inconnue : même 302) → réinitialisation (confirmation différente 422, OK 302, rejeu 400) → session ouverte dans un autre navigateur invalidée (302 `/login`) → e-mail « mot de passe modifié » |
| Connexion avant validation | 403 + lien renvoyé ; le lien précédent est révoqué (400), conforme au test d'intégration |
| XSS | `company=<script>alert(1)</script> Acme "SA"` et `email="><img onerror>` : échappés partout (tableau de bord, `value=`) |
| CSRF | sans jeton 419 ; jeton d'une autre session 419 ; en-tête `X-CSRF-Token` accepté ; POST sans session 419 |
| Fixation de session | cookie `aaaa…` (64 hex) inconnu : jamais adopté, nouvel identifiant émis |
| Redirections ouvertes | `//evil.com/`, `/fr//evil.com?lang=en`, `%2F%2F`, `%5C`, `next=//evil.com` : aucune sortie du site |
| Chemins bizarres | `%0d%0a`, `%00`, `%ff`, `..%2f`, `/.env`, `/FR/`, `/xx/`, `/fr/login%20` : 404 propres, aucun 500 |
| Méthodes | PUT/DELETE/PATCH/OPTIONS/TRACE/PROPFIND : 405 + `Allow` ; HEAD 200 |
| Gros volumes | POST 9 Mo, 3 000 champs, JSON : 419 sans erreur ; Accept-Language de 100 Ko refusé par le serveur ; 5 000 entrées q : OK |
| Accept-Language malformé | `;;;,,,`, `xx`, `en;q=abc`, `fr;q=0, en;q=0`, NUL : repli EN, aucune erreur |
| Rate limiting | 5 × 422 puis 429 `Retry-After: 899` (réponse en 3 ms, sans fuite) ; bon mot de passe refusé pendant le blocage |
| Timing de connexion | adresse inconnue 0,225 / 0,217 s, connue 0,206 / 0,213 s : indiscernable |
| Clés Redis | uniquement des empreintes HMAC (`rl:login_ip:7adb…`), sessions JSON sans donnée personnelle hors `user_id` |
| Captures `docs/screenshots/phase-1/` (12) | propres, cohérentes FR/EN, responsive correct ; contrastes calculés ci-dessous |
| Contrastes (calculés) | texte atténué 6,27:1, lien/bouton 6,78:1 (OK) ; **bordure des champs 1,29:1 (clair) / 1,37:1 (sombre)**, **anneau de focus 2,15:1 sur blanc, 2,0:1 sur le fond** (KO, voir exigence 3) |

## Grille

| # | Critère | Note | Commentaire |
|---|---|---|---|
| 1 | Conformité au cahier des charges et à `CLAUDE.md` (phase 1) | **OK** | Arborescence, routeur, `.env`/`.env.example` complet, migrations, i18n FR+EN avec 24 dossiers, auth client, layout. Dépendances conformes et justifiées. Domaine jamais en dur. `LocaleNegotiator` respecte l'ordre imposé. |
| 2 | Sécurité (ASVS L2 sur le périmètre) | **À CORRIGER (bloquant)** | Très bon niveau général, mais V2.1.7 (mots de passe courants/compromis) absent et V3.3.2 (inactivité 30 min en L2) non respecté ; V5.1.3/5.1.4 (validation de la raison sociale) incomplet. Exigences 1, 2, 4. |
| 3 | RGPD et minimisation | **OK** (recommandations) | E-mails et IP jamais journalisés (vérifié), audit en /24 /48, Redis sans PII, `no-referrer`. Rétention des journaux et comptes non validés à planifier (R2, R9). Allégations de conformité prématurées sur l'accueil (R10). |
| 4 | Qualité du code, architecture prête pour les phases 2 à 10 | **OK** (recommandations) | Code simple, lisible, sans état global hors `Application::current()`, `strict_types` partout. À prévoir pour la phase 2 : routage par hôte (`verify.`, `eid.`), corps JSON, en-tête `Authorization` sous Apache/PHP-FPM (R11, R12). |
| 5 | Tests | **OK** (à compléter avec les correctifs) | Couverture réelle des cas critiques (énumération, rejeu, rotation, résurrection, IPv6, XFF, 500 générique, RGPD des journaux). Chaque exigence bloquante doit arriver avec son test (R16). |
| 6 | i18n | **OK** (recommandations) | Aucune chaîne en dur (grep des nœuds texte et attributs des vues, du JS et des flash : 0 occurrence), repli EN → clé, `hreflang` + `x-default`, `lang` du document, formats `intl`, API JSON avec ETag/304. Pluriels non gérés (R5), durée « une heure » écrite en dur dans un texte (R6). |
| 7 | UX, accessibilité, responsive | **À CORRIGER (bloquant)** | Structure sémantique, lien d'évitement, `aria-invalid`/`aria-describedby`, JS en amélioration progressive, mode sombre : bien. Mais contrastes non textuels sous 3:1 (WCAG 1.4.11) sur tous les champs et sur l'indicateur de focus. Exigence 3. |
| 8 | Préparation au déploiement HestiaCP isolé | **OK** (recommandations) | Seul `public/` exposé, `.htaccess` sans effet global, HSTS sans `includeSubDomains` par défaut, préfixe Redis, `TRUSTED_PROXIES` vide par défaut, `fastcgi_finish_request`. Points à traiter dans les templates de phase 0 (R11). |

## Exigences bloquantes

1. **Aucun contrôle des mots de passe courants ou compromis** : `app/Services/PasswordPolicy.php:32-46`.
   Pourquoi : ASVS V2.1.7 (niveau 1 !) et NIST SP 800-63B §5.1.1.2 (« SHALL compare… commonly-used, expected, or
   compromised »), alors que le docblock (l. 10) revendique la conformité NIST. Vérifié : `aaaaaaaaaaaa`, `123456789012`
   et `password1234` sont acceptés à l'inscription (302, comptes créés). La même politique s'applique à la réinitialisation.
   Attendu : liste de blocage **locale** (aucun appel externe à l'exécution ; par ex. les 100 000 mots de passe les plus
   fréquents, fichier versionné hors `public/`, source et licence documentées dans `tasks/todo.md`), comparaison insensible
   à la casse, refus des mots de passe « contextuels » (nom du service, partie locale de l'adresse, raison sociale) et des
   répétitions triviales ; message traduit FR/EN (`check_translations` à 100 %) ; tests unitaires + un test d'intégration.

2. **Délai d'inactivité de session non conforme à ASVS L2** : `config/security.php:18` et `.env.example:45`
   (`SESSION_IDLE_MINUTES=120`).
   Pourquoi : ASVS V3.3.2 niveau 2 impose une réauthentification après 30 minutes d'inactivité (12 h au plus en absolu,
   ce qui est respecté). La grille de cet audit vise explicitement le niveau 2, et l'espace client donnera bientôt accès aux
   clés API et à la facturation.
   Attendu : valeur par défaut 30 (config et `.env.example`, `README` si cité) ; test vérifiant le TTL Redis appliqué.

3. **Contrastes non textuels insuffisants (WCAG 2.1 AA, critère 1.4.11)** : `public/assets/css/app.css:9` (`--border:
   #dde3ee`, utilisé par `.field input` l. 288), `:36` (`--border` sombre `#2a3552`), `:20` (`--focus: #f59e0b`, l. 70 et 294).
   Pourquoi : les bordures qui délimitent les champs de formulaire font 1,29:1 sur fond blanc et 1,37:1 en mode sombre ;
   l'indicateur de focus fait 2,15:1 sur blanc et 2,0:1 sur le fond de page (minimum 3:1). Cela touche tous les formulaires
   de l'espace client, et ces jetons de couleur seront repris par toutes les phases suivantes (espace client, page de
   vérification hébergée utilisée par le grand public).
   Attendu : bordure de champ ≥ 3:1 contre la surface dans les deux thèmes (la bordure décorative des cartes peut rester
   distincte), indicateur de focus ≥ 3:1 contre le fond **et** la surface dans les deux thèmes ; captures régénérées
   incluant un état focus, un état d'erreur de formulaire et le mode sombre.

4. **Validation incomplète de la raison sociale** : `app/Controllers/AuthController.php:29-41`.
   Pourquoi (reproduit) : (a) une valeur non UTF-8 (`company=Bad\xFF\xFECo`) passe la validation, l'utilisateur reçoit
   « Merci ! … vous allez recevoir un e-mail », puis la tâche reportée échoue en `PDOException SQLSTATE 22007`
   (journal `deferred_task_failed`) : aucun compte, aucun e-mail, échec silencieux ; (b) les caractères de contrôle et de
   mise en forme sont stockés tels quels (`U+202E` inversion bidirectionnelle, `\x01`, `\x07`, retours à la ligne :
   `hex(name) = E280AE6576696C0107`), alors que ce champ apparaîtra dans les factures, le back-office et les e-mails
   (usurpation visuelle, injection dans des formats texte). ASVS V5.1.3 / V5.1.4.
   Attendu : contrôle `mb_check_encoding`, normalisation NFC, refus (422, message traduit) des catégories Unicode `Cc`
   et `Cf` et des sauts de ligne, espaces internes compactés ; même garde d'encodage sur tout champ texte libre persistant ;
   tests unitaires et d'intégration (dont le cas non UTF-8 qui ne doit plus produire de faux succès).

## Recommandations non bloquantes

1. **Lien de validation consommé par les scanners de liens** (Outlook Safe Links, passerelles antivirus, fréquents chez des
   clients B2B) : l'utilisateur voit « lien invalide » alors que son adresse est validée. Afficher le succès si le jeton est
   déjà utilisé et l'adresse vérifiée, ou passer par une page de confirmation en POST. (`AuthService.php:137-150`)
2. **Comptes jamais validés** : conservés indéfiniment, avec une raison sociale choisie par le premier inscrit (pré-détournement :
   le titulaire légitime qui réinitialise hérite des données saisies par un tiers). Purge par cron après 7 jours sans validation
   et/ou réinitialisation des données du compte lors de la première validation par réinitialisation. Minimisation RGPD.
3. **Une session Redis par GET anonyme de formulaire** (`/fr/login`, `/fr/register` : TTL 2 h, vérifié) : un robot peut remplir
   la mémoire de Redis. TTL court pour les sessions anonymes (ex. 30 min, cohérent avec l'exigence 2), `maxmemory` +
   politique d'éviction sur l'instance Redis dédiée.
4. **`?lang=` en GET modifie `accounts.locale`** (`SetLocale.php:43-48`) : changement d'état par GET, déclenchable depuis un site
   tiers. Déplacer la préférence du compte vers un formulaire POST de l'espace client (phase 5) ; garder `?lang=` pour la session.
5. **Pluriels** : `Translator::get()` ne fait que du `strtr`. Pour pl, cs, sk, lt, lv, sl, ga, mt, les textes à nombre
   (`{minutes}`, `{hours}`, `{min}`, `{max}`) seront faux. Adopter `MessageFormatter` (intl, déjà requis) avant la phase 8 ;
   `check_translations` devra alors valider la syntaxe ICU.
6. **Durée écrite en dur dans un texte** : `site.forgot.sent` dit « valable une heure » alors que `password_reset_ttl` est
   configurable ; passer `{minutes}` comme dans l'e-mail.
7. **Validation de la configuration au démarrage en production** : `APP_URL` vide produit des liens relatifs dans les e-mails ;
   vérifier `APP_URL` en https, `APP_KEY`/`CRYPTO_KEY`, `MAIL_FROM_ADDRESS`, `SESSION_SECURE_COOKIE=true`, et journaliser un
   avertissement si `fastcgi_finish_request` est absent (l'anti-énumération par le temps en dépend).
8. **E-mails** : définir `PHPMailer::$Hostname` à partir de `APP_DOMAIN` (le Message-ID porte `@127.0.0.1` / le nom de la
   machine, et le HELO SMTP aussi) ; ajouter `Auto-Submitted: auto-generated`.
9. **Journaux** : aucune rotation ni rétention de `storage/logs` (RGPD) ; `Logger::exceptionContext` écarte tout le message des
   `PDOException` (« SQLSTATE 0 » dans le journal existant, inexploitable) : conserver le SQLSTATE réel et `errorInfo[1]`.
10. **Allégations de l'accueil** : « Conforme au RGPD », « disponible dans les langues de l'Union européenne », « avec la carte eID
    belge » ne sont pas vraies à ce stade (2 langues, pas d'eID, conformité non validée par un juriste). Marquer ⚖️ et nuancer
    avant toute mise en ligne sur `compose-web.net`.
11. **HestiaCP (templates de phase 0)** : `.htaccess:7` bloque aussi `/.well-known/` (ACME si servi par Apache, futur
    `security.txt`) : exempter `.well-known` ; prévoir le passage de l'en-tête `Authorization` à PHP-FPM (`CGIPassAuth On` ou
    `SetEnvIf`) pour l'API Bearer de la phase 2 ; `open_basedir` du pool dédié doit couvrir la racine applicative (docroot
    `app/public`, code dans `app/`) ; poser les en-têtes de sécurité sur les fichiers statiques dans le template.
12. **Préparer la phase 2** : le routeur n'a pas de routage par hôte (`verify.`, `eid.`), `Request` ne lit ni corps JSON ni
    `Authorization` ; `ErrorRenderer` sait déjà produire du JSON, bonne base.
13. **Accessibilité (mineur)** : le nom accessible du sélecteur de langue (« Choisir la langue ») ne contient pas le libellé
    visible « FR » (WCAG 2.5.3) ; l'erreur de connexion n'est pas reliée au champ (`aria-invalid`/`aria-describedby`) ; le
    bouton « Afficher » change à la fois de libellé et d'`aria-pressed` (garder l'un des deux).
14. **Pages 404 du routeur** (`/fr/inconnu`) : rendues sans `StartSession`, elles affichent l'en-tête « visiteur » à un utilisateur
    connecté. Mineur.
15. **Captures** : ajouter tableau de bord, mot de passe oublié, réinitialisation, page d'erreur, état d'erreur de formulaire,
    focus clavier et mode sombre (seules accueil/inscription/connexion claires sont fournies).
16. **Tests à ajouter avec les correctifs** : liste de blocage, TTL d'inactivité, encodage et caractères de contrôle, refus de
    `MAIL_DRIVER=log` en production, déconnexion sans jeton → 419.
17. **URL canoniques** : `/fr/login/` et `/fr/login` servent le même contenu ; `<link rel="canonical">` (ou 301) avec le sitemap
    de la phase 9. Détail de développement : sous `php -S`, les variables d'environnement du shell ne priment sur `.env` qu'avec
    `-d variables_order=EGPCS` (phpdotenv ne lit pas `getenv()`), à documenter dans le README.

## Conditions de levée du rejet

Les exigences 1 à 4 corrigées, chacune avec son test ; `vendor/bin/phpunit` et `php tools/check_translations.php` verts ;
captures régénérées (exigence 3). Les recommandations peuvent être planifiées dans `tasks/todo.md` (phases 0, 2, 5, 8, 9, 10).
