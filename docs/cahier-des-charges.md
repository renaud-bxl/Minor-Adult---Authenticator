# PROMPT CLAUDE CODE : Plateforme SaaS de vérification d'âge (nom provisoire : VeriAge)

## 0. Démarrage de session (obligatoire)

1. Lire `tasks/lessons.md` et appliquer toutes les leçons. Le créer s'il n'existe pas.
2. Lire `tasks/todo.md` pour connaître l'état actuel. Le créer s'il n'existe pas.
3. Créer `CLAUDE.md` à la racine avec un résumé de ce prompt (stack, contraintes, conventions).
4. Passer en mode plan. Écrire le plan complet par phases dans `tasks/todo.md` AVANT d'écrire la moindre ligne de code.
5. Me poser en une seule fois les questions bloquantes éventuelles (nom de domaine, fournisseur de paiement, fournisseur KYC), puis ne plus m'interrompre pendant une phase.
6. Après chaque correction de ma part : ajouter une ligne dans `tasks/lessons.md` au format `[date] | ce qui a mal tourné | règle pour l'éviter`.

---

## 1. Vision du produit

Un service **totalement indépendant** qui permet à des plateformes existantes (sites de vente d'alcool, jeux, contenus adultes, forums, etc.) de savoir si un de leurs utilisateurs est **majeur**, sans jamais recevoir ses données d'identité.

Principe central :
- La plateforme cliente nous envoie un identifiant de liaison : l'**adresse e-mail** de son utilisateur.
- Nous vérifions l'âge de la personne par une des méthodes disponibles.
- Nous renvoyons uniquement : `email`, `is_adult` (true/false), `verified_at` (date ISO 8601), `method`, `expires_at`.
- **Aucune** donnée d'identité (nom, photo, numéro de document, date de naissance exacte) n'est transmise à la plateforme cliente ni conservée après la vérification.

Le produit est composé de **deux parties distinctes** :
- **Partie A : le site web** (vitrine publique + espace client + back-office admin + facturation).
- **Partie B : le module de vérification** (API REST + page de vérification hébergée + widget JS intégrable + webhooks).

**Les deux parties doivent être entièrement traduites dans les 24 langues officielles de l'Union européenne** (voir section 6).

---

## 2. Contraintes techniques (non négociables)

- **Hébergement : VPS dédié** (accès root). Tout le projet tourne sur ce VPS, aucun hébergement mutualisé.
- **Langage principal : PHP 8.2+** (application web, API, facturation, espace client).
- **Service biométrique : Python 3.11+** en microservice interne (voir 3.2), écoutant uniquement sur `127.0.0.1`, jamais exposé à Internet. PHP l'appelle en HTTP local.
- Pas de Node.js : front en **HTML + CSS + JavaScript vanilla**, aucun bundler, aucun npm.
- Base de données : **MySQL / MariaDB**, via PDO et requêtes préparées exclusivement. **Redis** pour la file d'attente, les sessions de vérification et la limitation de débit.
- Serveur web : **Apache 2.4 + mod_ssl + PHP-FPM** (Apache choisi pour l'authentification par certificat client eID). Certificats Let's Encrypt via Certbot.
- Dépendances PHP via **Composer**. Bibliothèques autorisées : `stripe/stripe-php` (ou `mollie/mollie-api-php`), `phpmailer/phpmailer`, `vlucas/phpdotenv`, `firebase/php-jwt`, `endroid/qr-code`, `predis/predis`. Toute autre dépendance doit être justifiée dans le plan.
- Architecture : MVC léger maison (routeur simple, contrôleurs, vues PHP, modèles PDO). Pas de framework lourd.
- Configuration via `.env` (jamais commité). Fournir un `.env.example` complet.
- Tâches planifiées via **cron** (purge des données, relances, renouvellement d'abonnements, alertes de crédit) et **workers gérés par systemd** (traitement des vérifications et envoi des webhooks avec relances).
- Seul `public/` est exposé au web. Pare-feu (UFW) : ports 22, 80, 443 uniquement. Fail2ban actif.
- Fournir les fichiers de configuration serveur prêts à l'emploi dans `/deploy` : vhosts Apache, pools PHP-FPM, services systemd, crontab, script de déploiement.

Arborescence cible :
```
/app
  /Controllers  /Models  /Services  /Views  /Middleware
  /Verification   (moteur de vérification, adaptateurs par méthode)
  /Billing        (crédits, abonnements, factures)
/lang
  /bg /cs /da /de /el /en /es /et /fi /fr /ga /hr /hu /it /lt /lv /mt /nl /pl /pt /ro /sk /sl /sv
/public
  index.php  .htaccess  /assets  /widget (verify.js)
/config  /database/migrations  /cron  /tests  /tasks
/biometrics     (microservice Python : MRZ, visage, liveness)
/deploy         (vhosts Apache, systemd, crontab, script de déploiement)
```

---

## 3. Partie B : le module de vérification

### 3.1 Flux d'intégration (le plus simple possible pour la plateforme cliente)

**Étape 1 : la plateforme crée une session** (appel serveur à serveur)
```
POST /api/v1/sessions
Authorization: Bearer sk_live_xxx
{
  "email": "user@exemple.be",
  "min_age": 18,
  "return_url": "https://plateforme-cliente.be/retour",
  "lang": "fr",                 // optionnel, sinon détection automatique
  "external_ref": "user_4521"   // optionnel, identifiant interne du client
}
```
Réponse :
```
{ "session_id": "vs_...", "verify_url": "https://verify.veriage.eu/s/vs_...", "expires_in": 1800 }
```

**Étape 2 : l'utilisateur est redirigé** vers `verify_url` (ou la page s'ouvre dans le widget modal). Il choisit sa méthode et se fait vérifier.

**Étape 3 : retour du résultat**, par trois canaux :
- Redirection vers `return_url?session_id=...&token=...` (token JWT signé, court).
- **Webhook** `POST` vers l'URL configurée par le client, signé en HMAC-SHA256 (en-tête `X-VeriAge-Signature` + horodatage, protection anti-rejeu).
- **Consultation** à tout moment :
```
GET /api/v1/verifications?email=user@exemple.be
→ { "email": "...", "is_adult": true, "verified_at": "2026-09-27T20:47:00+02:00",
    "method": "id_document_face", "expires_at": "2027-09-27T..." }
```

Réponse standard si aucune vérification : `{ "email": "...", "is_adult": false, "status": "not_verified" }`. Distinguer clairement `not_verified`, `pending`, `failed`, `verified`.

### 3.2 Méthodes de vérification (architecture en adaptateurs)

Créer une interface `VerificationMethodInterface` et un adaptateur par méthode, activables par client dans l'espace client.

**Méthode 1 : Carte d'identité recto-verso + reconnaissance faciale (Belgique et UE)**
- Capture du recto et du verso via la webcam ou le téléphone (JS vanilla, `getUserMedia`), avec cadrage guidé.
- Lecture et contrôle de la **MRZ** (zone lisible par machine) : calcul des chiffres de contrôle en PHP, extraction de la date de naissance et de la date d'expiration, rejet des documents expirés.
- **Selfie avec détection du vivant (liveness)** : mouvements demandés aléatoires (tourner la tête, cligner) pour empêcher l'usage d'une photo ou d'une vidéo.
- Comparaison du visage du selfie avec la photo du document.
- **Traitement local sur le VPS** via le microservice Python `biometrics/` (FastAPI) :
  - lecture de la MRZ (PassportEye ou équivalent + Tesseract),
  - détection et comparaison des visages (InsightFace ou DeepFace, modèles open source téléchargés sur le VPS),
  - contrôle du vivant (liveness) sur une courte séquence d'images (mouvements aléatoires demandés),
  - réponse à PHP : `{ age, doc_expired, face_match_score, liveness_passed }`, **jamais** d'image ni d'identité en retour.
  - Les images sont traitées en mémoire uniquement, jamais écrites sur disque.
- Seuils de décision (score de correspondance, liveness) configurables dans l'admin. En dessous du seuil : échec ou vérification manuelle selon le réglage du client.
- Garder l'interface en adaptateurs : `LocalBiometricsProvider` (par défaut), un adaptateur vers un **fournisseur européen spécialisé** (Veriff, IDnow, Sumsub, Didit) en option de secours ou pour les clients exigeant une certification, et un `MockProvider` pour le développement et les tests.
- Vérifier la licence commerciale de chaque modèle open source utilisé et la documenter dans `docs/licences.md` (certains modèles de reconnaissance faciale interdisent l'usage commercial).

**Méthode 2 : Carte eID belge avec lecteur de carte et code PIN**
- Utilise le middleware eID officiel belge + l'extension navigateur installés chez l'utilisateur.
- Authentification par **certificat client TLS** (le navigateur demande le code PIN, la puce signe). Sous-domaine dédié `eid.veriage.eu` configuré avec `SSLVerifyClient require` et la chaîne de certification Belgium Root CA.
- En PHP, lire le certificat (`$_SERVER['SSL_CLIENT_CERT']`), vérifier la chaîne et la révocation (OCSP), extraire le numéro de registre national du champ `serialNumber`, en **déduire uniquement l'âge** (les 6 premiers chiffres encodent la date de naissance, avec la règle du siècle via le chiffre de contrôle), puis **effacer immédiatement** le numéro sans jamais l'écrire en base ni dans les logs.
- Configuration sur le VPS : vhost Apache dédié au sous-domaine `eid.`, `SSLVerifyClient require`, `SSLVerifyDepth 3`, `SSLCACertificateFile` contenant les autorités belges (Belgium Root CA et Citizen CA, récupérées depuis le dépôt officiel), `SSLOptions +ExportCertData`. Forcer une négociation TLS compatible avec l'authentification client (tester Firefox, Chrome, Edge et Safari sur macOS et Windows).
- Page d'aide traduite expliquant à l'utilisateur ce qu'il doit installer (logiciel eID officiel + lecteur de carte), avec détection de l'échec et bascule proposée vers une autre méthode.
- ATTENTION juridique : l'utilisation du numéro de registre national par une entreprise privée est encadrée en Belgique. Documenter ce point dans `docs/juridique.md` comme point à valider avec un juriste avant la mise en production.

**Méthode 3 (prévue, à activer plus tard) : identité numérique**
- Adaptateur **itsme** (OpenID Connect, fournit la date de naissance, très répandu en Belgique).
- Adaptateur **portefeuille européen / application européenne de vérification d'âge** (eIDAS 2.0, preuve d'âge sans divulgation d'identité). Préparer l'interface, implémentation quand le standard est disponible.

### 3.3 Règles métier du module

- Chaque client choisit : âge minimum (16, 18, 21), méthodes autorisées, durée de validité d'une vérification (ex. 12 mois), comportement si déjà vérifié.
- **Réutilisation** : si l'e-mail a déjà été vérifié pour CE client et que la vérification n'est pas expirée, renvoyer le résultat sans nouvelle vérification ni nouvelle facturation.
- Réutilisation entre clients différents : **désactivée par défaut**, uniquement avec le consentement explicite de l'utilisateur (« réutiliser ma vérification d'âge sur ce site »).
- Vérifier que l'utilisateur contrôle bien l'adresse e-mail (lien magique ou code à 6 chiffres) avant de lier le résultat à cet e-mail.
- Limitation de débit par clé API et par adresse IP. Blocage après X échecs sur une même adresse e-mail.
- Journal d'audit (qui, quand, quel résultat, quelle méthode) sans aucune donnée d'identité.

### 3.4 Widget intégrable

- Fichier unique `public/widget/verify.js` (JS vanilla, aucune dépendance) :
```html
<script src="https://verify.veriage.eu/widget/verify.js"
        data-session="vs_..." data-lang="auto"></script>
```
- Ouvre une fenêtre modale (iframe) ou redirige en plein écran sur mobile.
- **Trois modes d'affichage au choix du client** (réglable dans l'espace client et surchargeable par l'attribut `data-mode`) :
  - `modal` : modale injectée automatiquement par le script au chargement de la page (ou au clic, via `data-autoopen="false"`), contenant une iframe.
  - `popup` : fenêtre `window.open` centrée, ouverte obligatoirement sur un clic utilisateur (sinon bloquée par les navigateurs), résultat renvoyé via `postMessage`.
  - `iframe` : cadre intégré directement dans un conteneur de la page (`data-target="#mon-div"`).
  - `redirect` : redirection pleine page puis retour sur `return_url` (mode de secours).
- Contraintes techniques à respecter :
  - Iframe avec `allow="camera; fullscreen"`, sinon la webcam est refusée.
  - En-tête `Content-Security-Policy: frame-ancestors` limité aux domaines autorisés du client (pas de X-Frame-Options global).
  - Aucune dépendance aux cookies tiers dans l'iframe (bloqués par Safari et bientôt Chrome) : l'état passe par le `session_id` dans l'URL.
  - **Méthode eID** : l'authentification par certificat client est peu fiable dans une iframe. Si l'utilisateur choisit l'eID depuis la modale ou l'iframe, basculer automatiquement vers un popup ou une redirection pour cette étape.
  - Sur mobile, la modale et l'iframe passent en plein écran.
  - Communication script ↔ page cliente via `postMessage` avec vérification stricte de l'origine, et événements JS exposés : `veriage:opened`, `veriage:completed` (avec `is_adult`, `verified_at`), `veriage:failed`, `veriage:closed`.
  - Le résultat côté navigateur est indicatif : la plateforme cliente doit toujours confirmer via le webhook ou l'API serveur (un résultat JS peut être falsifié).
- Personnalisable depuis l'espace client : logo, couleur principale, texte d'accueil (par langue).
- Fournir des exemples d'intégration copiables dans l'espace client : PHP, JavaScript, WordPress/WooCommerce (petit plugin), cURL.

---

## 4. Partie A : le site web

### 4.1 Site public (vitrine)
- Accueil, fonctionnement, méthodes de vérification, tarifs, documentation développeurs, FAQ, contact.
- Pages légales : conditions générales, politique de confidentialité, politique cookies, DPA (accord de traitement des données pour les clients).
- Page « Pour les utilisateurs finaux » expliquant ce que nous faisons de leurs données (rien de conservé).

### 4.2 Espace client
- Inscription (e-mail + mot de passe, validation par e-mail), double authentification (TOTP) proposée.
- Profil de l'entreprise : raison sociale, adresse, **numéro de TVA** (validé via VIES), pays.
- Gestion de plusieurs sites/projets par compte, avec pour chacun : clés API (test et live, régénérables), URL de webhook, domaines autorisés, âge minimum, méthodes actives, durée de validité, personnalisation du widget.
- Tableau de bord : nombre de vérifications (réussies, échouées, en attente), graphiques par jour et par méthode, solde de crédits, consommation de l'abonnement.
- Journal des vérifications (e-mail, résultat, date, méthode) avec export CSV.
- Mode test (sandbox) avec clés `sk_test_` et résultats simulés, non facturés.
- Plusieurs utilisateurs par compte client avec rôles (propriétaire, développeur, comptable).

### 4.3 Back-office administrateur
- Gestion des clients, suspension de compte, ajustement manuel de crédits.
- Configuration des offres et des prix (sans toucher au code).
- Suivi du chiffre d'affaires, des factures, des coûts fournisseurs KYC.
- Journaux système et alertes.

---

## 5. Facturation

Fournisseur : **Stripe** par défaut (abonnements, paiements uniques, portail client), avec abstraction permettant de passer à **Mollie** (très utilisé en Belgique : Bancontact). Méthodes de paiement : carte, Bancontact, SEPA, iDEAL.

### 5.1 Formule prépayée (à l'unité)
- Le client achète un pack de crédits (ex. 100 €, 250 €, 500 €, 1 000 €, montant libre au-delà d'un minimum).
- Prix unitaire par vérification configurable dans l'admin, dégressif selon le montant du pack, et pouvant varier selon la méthode (la méthode document + visage coûte plus cher que l'eID).
- Chaque vérification réussie ou échouée décompte un crédit (règle configurable). Les réutilisations ne décomptent rien.
- Alertes e-mail à 20 % et 5 % de solde restant. Option de **recharge automatique**.
- Si solde nul : l'API renvoie une erreur `402 insufficient_credits` claire.

### 5.2 Formule abonnement mensuel
- Paliers **Small, Medium, Large, Extra Large** basés sur le nombre d'utilisateurs vérifiés par mois (quotas et prix configurables dans l'admin, valeurs de départ à proposer dans le plan).
- **Montée de palier automatique** si le quota est dépassé (au prorata), ou facturation du dépassement à l'unité, au choix du client.
- Changement de palier à tout moment, prorata géré par Stripe.
- Webhooks Stripe traités de manière idempotente (paiement réussi, échec, résiliation).

### 5.3 Factures
- Factures PDF conformes à la législation belge : numérotation continue, TVA 21 %, autoliquidation intracommunautaire pour les clients UE assujettis avec numéro VIES valide, mentions légales.
- Téléchargement depuis l'espace client, envoi automatique par e-mail.
- Factures et e-mails de facturation traduits dans la langue du client.

---

## 6. Multilingue : 24 langues officielles de l'UE (site ET module)

Langues à supporter dès la V1, **sur la partie site web et sur la partie module** (page de vérification, widget, messages d'erreur, e-mails, factures, documentation) :

| Code | Langue | Code | Langue | Code | Langue |
|---|---|---|---|---|---|
| bg | Bulgare | et | Estonien | lt | Lituanien |
| cs | Tchèque | fi | Finnois | lv | Letton |
| da | Danois | fr | Français | mt | Maltais |
| de | Allemand | ga | Irlandais | nl | Néerlandais |
| el | Grec | hr | Croate | pl | Polonais |
| en | Anglais | hu | Hongrois | pt | Portugais |
| es | Espagnol | it | Italien | ro | Roumain |
| sk | Slovaque | sl | Slovène | sv | Suédois |

Règles d'implémentation :
- Fichiers de traduction PHP par langue et par domaine : `/lang/{code}/site.php`, `module.php`, `emails.php`, `billing.php`, `errors.php`. Une fonction `__('cle.de.traduction', [params])`.
- **Aucune chaîne en dur** dans les vues, le JS ou les e-mails. Le widget JS charge ses traductions depuis un endpoint JSON `/api/v1/i18n/{code}`.
- Langue source : **français**, puis anglais comme langue de repli.
- Détection : paramètre `lang` de la session > préférence du compte > en-tête `Accept-Language` > anglais.
- Site public : URL préfixées (`/fr/`, `/nl/`, `/de/`...), balises `hreflang`, sitemap multilingue, sélecteur de langue visible.
- Formats localisés : dates, nombres, devises (`IntlDateFormatter`, `NumberFormatter`).
- Script `tools/check_translations.php` qui liste les clés manquantes ou orphelines dans chaque langue. Il doit passer à 100 % avant chaque livraison.
- Génération des traductions : produire le français complet, puis générer les 23 autres langues (via l'API Claude ou DeepL) dans un script dédié. Marquer les **textes juridiques** (CGV, confidentialité, DPA) comme « à faire relire par un traducteur humain » dans `tasks/todo.md`.

---

## 7. RGPD et sécurité (priorité absolue)

- Les données biométriques utilisées pour identifier une personne sont une **catégorie particulière** (article 9 RGPD) : consentement explicite recueilli avant la capture, écran d'information clair dans la langue de l'utilisateur.
- **Minimisation** : ne jamais stocker les images des documents, les selfies, le nom, le numéro de document, le numéro de registre national ni la date de naissance exacte. On stocke uniquement : hash de l'e-mail (SHA-256 salé par client) + e-mail chiffré (AES-256, clé dans `.env`), résultat booléen, date, méthode, expiration.
- Fichiers temporaires éventuels : chiffrés, en dehors de `public/`, supprimés dès la fin de la session et au plus tard par cron toutes les 15 minutes.
- HTTPS obligatoire, en-têtes de sécurité (CSP stricte, HSTS, X-Frame-Options sauf pour l'iframe du widget sur les domaines autorisés).
- Protection CSRF sur tous les formulaires, mots de passe en `password_hash` (Argon2id), clés API stockées hashées.
- Registre des traitements et analyse d'impact (AIPD) à préparer : créer `docs/rgpd.md` avec la liste des éléments à faire valider par un juriste.
- Droit à l'effacement : endpoint client `DELETE /api/v1/verifications?email=` et formulaire public pour les utilisateurs finaux.

---

## 8. Phases de développement

0. **Serveur** : inventaire du VPS (OS, versions, ressources, présence d'un GPU), installation Apache + PHP-FPM + MariaDB + Redis + Python, pare-feu, certificats, sauvegardes automatiques.
1. **Fondations** : arborescence, routeur, `.env`, migrations, système i18n (avec FR + EN au départ), authentification client, layout.
2. **API et module** : sessions, consultation, webhooks signés, `MockProvider`, page de vérification hébergée, widget JS.
3. **Méthode document + visage** : capture webcam, microservice Python local (MRZ, visage, liveness), adaptateur fournisseur externe en option.
4. **Méthode eID belge** : vhost Apache avec certificat client sur le VPS, extraction de l'âge.
5. **Espace client complet** : projets, clés, configuration, statistiques, journaux.
6. **Facturation** : crédits prépayés, abonnements S/M/L/XL, webhooks Stripe, factures PDF.
7. **Back-office admin**.
8. **Traductions** : génération des 22 langues restantes, script de contrôle à 100 %.
9. **Site vitrine, documentation développeurs, pages légales**.
10. **Tests et audit de sécurité** puis préparation au déploiement.

---

## 9. Standard de vérification

- Aucune phase n'est marquée terminée sans preuve : tests PHPUnit passants, appels API testés (fournir une collection de requêtes cURL dans `docs/api-tests.md`), vérification visuelle des pages.
- Tests obligatoires : calcul de l'âge (anniversaire le jour même, années bissextiles, siècle du registre national), chiffres de contrôle MRZ, signature et anti-rejeu des webhooks, décompte des crédits, montée de palier, couverture des traductions.
- Se demander à chaque étape : « Est-ce qu'un staff engineer validerait ça ? »
- Simplicité d'abord, causes racines uniquement, jamais de correctif temporaire.
- Ne jamais supposer : vérifier chemins, APIs, variables et disponibilité des fonctions serveur (`intl`, `openssl`, `gd`, `SSLVerifyClient`) avant de les utiliser.

---

## 10. Livrables attendus à la fin

- Code complet déployable sur le VPS, avec le dossier `/deploy` (vhosts, systemd, crontab, script de déploiement).
- `README.md` : installation du serveur pas à pas, `.env`, migrations, services, crons, sauvegardes.
- `docs/integration.md` : guide d'intégration pour les plateformes clientes (traduit au minimum en FR, NL, EN, DE).
- `docs/juridique.md` et `docs/rgpd.md` : points à valider par un juriste.
- `tasks/todo.md` à jour et `tasks/lessons.md` alimenté.

Commence par la section 0, puis présente-moi le plan détaillé de la phase 1 avant d'implémenter.
