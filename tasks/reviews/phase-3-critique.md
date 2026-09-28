VERDICT : APPROUVÉ

(Ré-audit du 2026-09-28 sur `12e0c1b`, voir la section « Ré-audit » en fin de document. Premier audit du
2026-09-27 : REJETÉ, exigences E1 à E3, conservé ci-dessous.)

# Phase 3 : audit du critique

Date : 2026-09-27. Base auditée : `76dba7d..24d819e` (créateur `070e51a` + contrôleur `24d819e`), arbre propre.
Décisions du propriétaire respectées et non reprochées : aucun service externe de vérification ; la MRZ ne sort
jamais du microservice.

## Résumé

L'ingénierie est de très bon niveau : transport HMAC mutuel lié au nonce, entrées bornées avant décodage,
chiffrement de l'envoi, aucune image persistée, pannes distinguées des décisions. Je n'ai trouvé **aucun défaut
de sécurité technique** dans le transport ni dans le traitement des entrées.

Le rejet porte sur la **valeur de protection réelle**, qui est le produit lui-même. Aujourd'hui, **un mineur qui a
accès à la carte d'identité d'un parent passe, sans outil et sans compétence**. Le recto et le verso ne sont pas
liés : le visage est lu au recto, la MRZ au verso, et rien ne vérifie qu'ils viennent de la même pièce, ni même que
le recto est une pièce d'identité. Je l'ai démontré sur le vrai pipeline. Par ailleurs, les limites ne sont pas
dites aux clients, et la « vérification manuelle » proposée ne vérifie rien.

## Preuves exécutées

| Commande | Résultat |
|---|---|
| `vendor/bin/phpunit` | OK (324 tests, 2020 assertions) |
| `biometrics/.venv/bin/python -m pytest -q` | 103 passed |
| `php tools/check_translations.php` | 100 % fr, en (355 clés, 0 inconnue) |
| `tools/e2e_capture.py` (Chromium réel, service 127.0.0.1:8765) | 9/9 |
| Attaques, en mémoire, vrais modèles (YuNet, SFace, MediaPipe, Tesseract), `analysis.analyze` | voir ci-dessous |

Attaques démontrées (script du scratchpad : cartes fictives « Utopie », visages du domaine public ; « mineur » =
obama.jpg, « parent » = biden.jpg, MRZ TD1 d'un adulte né en 1975) :

| # | Scénario | Moyens | Réponse du service |
|---|---|---|---|
| A | Recto = **un simple selfie** du mineur (aucun document). Verso = photo du verso de la carte du parent. Selfie du mineur. | Aucun outil, aucune compétence | `age 51, face_match 0.989, liveness_passed true, reasons []` → **vérifié majeur** |
| B | Recto = **la vraie carte du mineur**. Verso = carte du parent. Selfie du mineur. | Deux vraies cartes, aucun outil | `age 51, face_match 0.939, liveness true` → **vérifié majeur** |
| C | **Carte du parent seule.** Le « selfie » est le portrait de la carte (228 × 301 px), recentré puis animé en 2D (`synth.yaw_warp` + `close_eyes`, une vingtaine de lignes) | Script copié du dépôt, puis caméra virtuelle ou `getUserMedia` remplacé | `age 51, face_match 0.939, liveness true` → **vérifié majeur** |
| C' | Même principe dans un vrai navigateur : l'E2E du projet (scénario 1). Une seule photo fixe, animée, injectée à la place de la caméra | `tools/e2e_capture.py` | `verified`, `is_adult: true` (9/9) |

La variante C' est l'E2E livré avec le projet. Le « visage simulé qui suit les consignes » y est **exactement
l'attaque par photo** que le cahier des charges demande d'empêcher (§ 3.2, méthode 1 : « pour empêcher l'usage d'une
photo ou d'une vidéo »). Le commentaire de `liveness.py:8` (« ce défi résiste aux photos ») et `tasks/todo.md:178`
disent le contraire de ce que prouve l'E2E. Une photo plane **pivotée** échoue bien, mais une photo **déformée** passe.

## Un mineur motivé peut-il passer ? Réponse sans complaisance

Oui, et le plus souvent sans effort. Voici les attaquants, du moins équipé au plus équipé.

1. **Mineur qui a sa propre carte (obligatoire dès 12 ans en Belgique) et accès à celle d'un parent :** il passe
   aujourd'hui à 100 % (scénarios A et B), avec son téléphone seulement. **C'est le cas le plus probable, et il est
   corrigeable localement** (E1).
2. **Mineur qui n'a que la carte d'un parent ou d'un aîné, avec des notions techniques :** il anime le portrait de
   la carte (C) ou une photo trouvée sur un réseau social, puis l'envoie par caméra virtuelle (OBS) ou par injection
   JS. Il passe à 100 %. Le nombre d'ordres de défis n'y change rien : le défi est lu à l'écran (ou dans la réponse
   de `/document/start`) avant la capture.
3. **Mineur outillé :** échange de visage en temps réel (outils open source grand public de type Deep-Live-Cam ou
   LivePortrait) + OBS. Il passe, et **aucune parade locale réaliste n'existe en V1**. Même les fournisseurs
   certifiés n'y résistent qu'imparfaitement : détection d'injection, attestation de l'appareil, NFC.
4. **Faux document fabriqué** (MRZ calculée par un générateur en ligne, sa propre photo, envoyé en fichier) : il
   passe. Après E1, il faut en plus un recto cohérent (mêmes date et numéro). Un faussaire soigneux le fait
   (spécimens PRADO publics), un adolescent pressé beaucoup moins.
5. **Frère ou sœur majeur qui se ressemblent :** cela dépend du seuil (0,40, juste au-dessus du 0,363 publié pour
   SFace). Taux de fausse acceptation non mesuré.

**Valeur réelle pour un site client, après E1 :** assurance **faible à modérée**. La méthode arrête le mineur
opportuniste : celui qui montre une photo, qui utilise la carte d'un parent avec son propre visage, ou qui mélange
deux cartes. Elle n'arrête pas un mineur déterminé et un peu technique. C'est défendable pour une V1 d'un produit
100 % local, **à condition de le dire aux clients** (E2). Ce ne l'est pas si on le tait.

## Grille

| # | Critère | État |
|---|---|---|
| 1 | Conformité (cahier + CLAUDE.md) | **À CORRIGER (bloquant)** : l'objectif « empêcher l'usage d'une photo » n'est pas atteint (E2E), recto et verso ne sont pas liés (E1), la « vérification manuelle » n'en est pas une (E3). Le reste est conforme : MRZ et chiffres de contrôle, expiration, 100 % local, réponse minimale, adaptateurs, licences. « Chiffres de contrôle en PHP » : écarté par l'arbitrage du propriétaire. |
| 2 | Sécurité ASVS L2 | Transport, cryptographie, entrées et journaux : **OK**, vérifié à la lecture (HMAC ± 30 s + nonce, réponse signée, base64 strict, dimensions lues avant décodage, AES-GCM à clé unique par capture, défis tirés côté serveur et consommés atomiquement). Logique métier (ASVS V11) : **À CORRIGER** (E1). |
| 3 | RGPD | **OK**. Aucune image, date ni numéro persisté. Consentement art. 9 dédié. À mettre à jour avec E1 : le texte du consentement dit « nous lisons la zone MRZ » ; il faudra ajouter la lecture des champs du recto. Art. 22 : si E3 retire la revue, il faut indiquer la voie d'intervention humaine (autre méthode, contact) ⚖️. |
| 4 | Qualité et architecture | **OK** : code clair, bornes explicites, codes de motif stables, bonne séparation entre PHP et Python. |
| 5 | Tests | **OK** en nombre et en rigueur. Manquent des tests de **non-régression anti-fraude** (A, B), à ajouter avec E1. Le scénario E2E 1 démontre une attaque, pas un usage légitime (E2). |
| 6 | i18n | **OK** (100 %, aucune chaîne en dur trouvée dans `capture.js`). |
| 7 | UX, accessibilité, responsive | **OK** : captures relues (défi desktop, consentement mobile sombre), région ARIA, focus, autre méthode toujours proposée. |
| 8 | Déploiement HestiaCP | **OK sur papier** : systemd durci, utilisateur dédié, ACL documentées. Non éprouvé sur le VPS (phase 0). |

## Exigences bloquantes

**E1. Lier le recto, le verso et le portrait à une même pièce, dans le service, sans rien en sortir.**
- **Où :** `biometrics/veriage_biometrics/analysis.py:100-121`. La MRZ est lue sur une image, le portrait sur une
  autre, sans aucun contrôle entre les deux.
- **Pourquoi :** contournement sans compétence (A et B), démontré. Il vise exactement la fraude attendue, et il est
  corrigeable localement pour un coût raisonnable.
- **Attendu :**
  - **Cohérence entre les champs imprimés (VIZ) et la MRZ :** OCR du côté qui porte le portrait. Il faut un modèle
    Tesseract générique à licence compatible (par exemple `tessdata_fast`, Apache-2.0, somme SHA-256 imposée comme
    pour les autres modèles). On y recherche la **date de naissance** de la MRZ (formats JJ MM AAAA, JJ.MM.AAAA,
    JJ MMM AAAA en FR/NL/DE/EN) et le **numéro du document** (alphanumérique normalisé, confusions O/0 et I/1,
    format belge `xxx-xxxxxxx-xx` contre MRZ 9 caractères + débordement). La date d'expiration sert de troisième
    champ. Il faut au moins deux champs concordants. Le règlement (UE) 2019/1157 et l'ICAO 9303 placent ces champs
    sur la face des données : la règle est donc générique, sans gabarit par pays.
  - **Portrait dans le document :** le visage retenu doit se trouver dans le document détecté. Pour une carte ID-1,
    quadrilatère de rapport ≈ 1,586 (± 15 %), le visage en occupant une part plausible. Pour un passeport, la page
    TD3 avec le portrait au-dessus de la MRZ. Un recto sans document détecté est refusé.
  - **Sortie :** nouveaux codes de motif seulement (par exemple `document_sides_mismatch`,
    `document_front_unreadable`, `document_not_detected`). Le contrat de six champs reste inchangé, et aucune valeur
    lue ne sort. Un recto illisible est un **échec** (sinon il suffirait de flouter le recto), avec un nouvel essai
    possible. Côté PHP : motif d'échec `document_inconsistent` (ou équivalent), textes FR/EN, `docs/integration.md` § 8.
  - **Tests :** A et B en pytest et dans un test d'intégration PHP (doivent échouer). Cartes synthétiques cohérentes
    (`synth.card_front` doit imprimer la date de naissance et le numéro) dans les variantes inclinée, floue et en
    perspective (doivent passer). Mesure et publication du taux de faux rejets sur ce jeu.
  - **Consentement :** mise à jour de `module.biometric.what_document` (FR/EN).

**E2. Dire honnêtement le niveau d'assurance, aux clients et dans l'AIPD, et corriger les affirmations fausses.**
- **Où :**
  - `docs/integration.md` : aucune mention des limites ;
  - `docs/rgpd.md` ;
  - `README.md` (section Biométrie) ;
  - `biometrics/veriage_biometrics/liveness.py:8` (« résiste aux photos ») ;
  - `tasks/todo.md:178` ;
  - en-tête de `tools/e2e_capture.py`.
- **Pourquoi :** un client s'appuie sur cette méthode pour ses obligations légales. Taire que la méthode cède à une
  photo animée (preuve : l'E2E du projet), à une caméra virtuelle, à un échange de visage en temps réel et à un faux
  document cohérent, c'est l'induire en erreur.
- **Attendu :**
  - Un encadré FR/EN « Ce que la méthode garantit / ne garantit pas » : aucune certification ISO 30107-3, aucune
    détection d'injection ni de deepfake, aucun contrôle des éléments de sécurité du document. Niveau d'assurance
    « faible à modéré ». Ne pas présenter la méthode comme conforme à un référentiel qui exige la résistance à
    l'injection ⚖️.
  - Des commentaires exacts (« résiste à une photo plane pivotée, pas à une photo animée »).
  - L'E2E décrit pour ce qu'il est : une attaque qui réussit.

**E3. Retirer de la V1 la « revue manuelle » sans image.**
- **Où :** `bin/project.php:105` (`set-below-threshold --mode=review`), `app/Services/ProjectAdmin.php`,
  `LocalBiometricsProvider.php:115`, `docs/integration.md` (`review: true`).
- **Pourquoi :** l'opérateur ne voit que le score, déjà comparé au seuil, et « vivant : oui ». Approuver revient à
  abaisser le seuil à 0,30, **sous** le seuil « même personne » de SFace (0,363). On accepterait donc des paires que
  le modèle juge de deux personnes (fraude de l'aîné qui ressemble), sous l'étiquette de « vérification manuelle »
  promise au client. Conserver les images est exclu par CLAUDE.md sans décision juridique.
- **Attendu :**
  - Le mode `review` est refusé (CLI et `ConfigValidator`) tant que le propriétaire n'a pas tranché la conservation
    chiffrée des images ⚖️. Le code et ses tests peuvent rester en place, désactivés.
  - `docs/integration.md` et `docs/rgpd.md` sont alignés.
  - La voie d'intervention humaine au sens de l'art. 22 est indiquée : autre méthode, contact.

## Arbitrage des propositions ouvertes du contrôleur

**(a) Authenticité du document.**
- **Maintenant (E1) :** cohérence VIZ/MRZ et portrait situé dans le document. C'est le meilleur rapport entre coût
  et protection, et cela ferme le contournement sans compétence.
- **Plus tard (V1.1) :** gabarits des cartes belges : position du portrait, image fantôme, MRZ 3 × 30, rapport des
  dimensions. Cela écarte « n'importe quelle image avec un visage et une MRZ », mais ne gêne guère un faussaire
  qui part d'un spécimen PRADO public. Même chose pour la cohérence entre le type choisi (carte ou passeport) et le
  type de MRZ (TD1 ou TD3) : faible coût, faible gain.
- **Plus tard, sans garantie :** détection d'une photo d'écran ou d'un papier imprimé. Sans modèle entraîné, les
  heuristiques de moiré sont peu fiables et coûtent en faux rejets. À réévaluer avec un modèle de détection passive
  à licence commerciale.
- **Hors de portée :** contrôle des éléments de sécurité (OVI, hologrammes) et lecture NFC dans un navigateur.
- **Envoi de fichiers pour le document :** à conserver, car les webcams lisent mal la MRZ. Après E1, il faut
  retoucher le recto pour frauder, ce qui demande un effort réel.

**(b) Six ordres de défis.**
- **Non bloquant.** Agrandir l'espace des ordres ne gêne que l'attaquant le plus faible : une seule vidéo préparée.
  Celui qui prépare six montages sait aussi changer de clip avec des raccourcis dans OBS, ou animer une photo
  (scénario C). Il passe alors à 100 %, quel que soit le nombre d'ordres.
- **Amélioration à faible coût, en V1.1 :**
  - 4 défis, répétitions permises mais jamais deux défis identiques consécutifs ;
  - une 4ᵉ action (ouvrir la bouche, mesurable avec les points MediaPipe) ;
  - des fenêtres de durée aléatoire.
  Cela donne plus de 100 ordres et environ 3 % de réussite par session avec des vidéos préparées.
- **Plus utile (R2) :** faire échouer l'animation 2D par des contrôles de cohérence 3D.

**(c) Revue manuelle sans image :** retirée de la V1 (E3).

**(d) Anciennes cartes françaises (2 × 36) :**
- **Non bloquant, V1.1 :** analyseur dédié. Date de naissance et chiffres de contrôle sont présents, mais pas
  l'expiration dans la MRZ : il faut la déduire de la date de délivrance (AAMM du numéro) + 10 ou 15 ans, avec la
  prolongation de 2014 pour les majeurs.
- **À faire dès E1 :** reconnaître le format et renvoyer un motif `document_unsupported`, avec un message qui
  propose le passeport ou une autre méthode, plutôt que « MRZ illisible ». Cela évite de faire recommencer trois
  fois une personne qui ne pourra jamais réussir.

## Recommandations non bloquantes

1. **R1, défis :** voir le point (b) ci-dessus.
2. **R2, animation 2D :** contrôles de cohérence 3D pendant les rotations. Matrice de transformation faciale de
   MediaPipe, raccourcissement de l'écart des yeux et de la largeur du visage en fonction du lacet, occultation de
   la joue éloignée. Un test où `synth.yaw_warp` doit **échouer**. L'E2E devra alors simuler une personne autrement
   (vidéo réelle d'un volontaire consentant, ou tête 3D rendue).
3. **R3, détection passive :** modèle anti-usurpation à licence commerciale (par exemple MiniFASNet,
   Silent-Face-Anti-Spoofing, Apache-2.0 ; licence et données d'entraînement à vérifier), calibré, contre le rejeu
   sur écran.
4. **R4, calibrage des seuils :** mesurer la fausse acceptation entre frères et sœurs ou sosies, et l'écart d'âge
   entre la photo du document et le selfie. Envisager un seuil d'acceptation au-dessus de 0,40 selon les données
   (déjà un point ⚖️ de `docs/rgpd.md`).
5. **R5, caméras virtuelles :** avertir lorsque le libellé du périphérique est connu (OBS, ManyCam…). Contournable
   et purement dissuasif, à faible coût.
6. **R6, outils de test :** `tools/e2e_capture.py` et `biometrics/tests/synth.py` forment un kit de contournement
   prêt à l'emploi. Garder le dépôt privé et ne jamais déployer `tools/` ni `tests/` dans un dossier exposé.
7. **R7, anciennes cartes françaises :** voir le point (d) ci-dessus.
8. **R8, gabarits belges et cohérence du type de document :** voir le point (a) ci-dessus.
9. **R9, propositions 5 et 6 du contrôleur :** lecture paresseuse du corps binaire, décision de revue en deux
   transactions. D'accord pour les reporter (phases 7 et 10).
10. **R10 :** `ChallengeStore::consume` détruit le défi même si l'identifiant de capture est faux. Seul l'auteur de
    la requête en pâtit : acceptable, à commenter.

## Données de test

Mes deux passages de l'E2E ont créé 5 sessions (id 179 à 183), 1 vérification (83), 3 livraisons de webhook (104 à
106), 23 lignes `audit_log` (590 à 612) et leurs clés Redis. **Tout a été supprimé.** Les sessions 175 à 178
(23:12, antérieures à mon audit) n'ont pas été touchées. Images de test générées dans le scratchpad, hors du dépôt.

---

# Ré-audit (2026-09-28)

Base : `git diff 34fdd94..12e0c1b` : corrections du créateur (`26872f8`) et contrôle des corrections
(`12e0c1b`). Décisions de Renaud (CLAUDE.md) prises comme cadre, et non comme des défauts :
- V1 annoncée avec une assurance **faible à modérée** ;
- l'eID est la méthode forte ;
- aucune image conservée ;
- pas de revue manuelle.

Méthode, conforme à la fiche mise à jour :
- **base jetable** `veriage_crit` : copie de la base de dev **sans les lignes de `audit_log`** (table vide), puis
  recréée vierge pour le test des migrations, et enfin supprimée en bloc ;
- **Redis n° 13**, vidé à la fin ;
- mon propre microservice (port 8766, code courant, secret neuf) et mon propre serveur PHP (port 8010), tous deux
  arrêtés à la fin ;
- **aucune ligne d'aucun journal d'audit touchée**, base de dev jamais écrite.

## Résultats exacts

| Vérification | Résultat |
|---|---|
| `vendor/bin/phpunit` | OK (333 tests, 2055 assertions) |
| `biometrics/.venv/bin/python -m pytest -q` | 133 passed, 1 xfailed (limite C', documentée, `xfail(strict)`) |
| `php tools/check_translations.php` | 100 % fr, en |
| Migrations sur base **vierge** (0001 → 0020) | OK, puis `--status` : aucune en attente |
| `tools/e2e_capture.py`, vrai Chromium, base jetable, service au code courant | **11/11** : attaques A et C refusées dans le navigateur, limite « photo animée injectée » acceptée et intitulée comme telle |
| `bin/biometrics.php health` (PHP → mon service) | `ok`, tesseract, mediapipe |
| SHA-256 de `eng.traineddata` | identique au script (`7d4322bd…`) |
| `bin/project.php set-below-threshold --mode=review` | refusé, code de sortie 1, message explicite |
| `bin/review.php approve` | refusé (aucune revue ; l'approbation est bloquée même pour une revue restée « pending », test du contrôleur) |

## Attaques rejouées sur le vrai pipeline

En mémoire, vrais modèles (YuNet, SFace, MediaPipe, Tesseract `mrz` + `eng`), `analysis.analyze`. « Mineur » :
carte obama2, selfie obama (deux photos distinctes). « Parent » : carte biden, né le 05.05.1975.

| # | Attaque | Moyens | Avant | Maintenant |
|---|---|---|---|---|
| A | Selfie nu au lieu du recto + verso du parent | aucun | vérifié majeur | **refusé** (`document_not_detected`, aucun score) |
| B | Vraie carte du mineur + verso du parent | aucun | vérifié majeur | **refusé** (`document_sides_mismatch`, aucun score) |
| T | Témoin : carte cohérente du parent + selfie du mineur | aucun | refusé | refusé (score 0,05) |
| C | Carte du parent seule, portrait de la carte animé en 2D | script | vérifié majeur | **refusé** (`face_identical_to_document`, 0,927) |
| C' | Idem, portrait **flouté** (σ = 2, 3 ou 4) avant l'animation | script + 1 ligne | – | **passe** (0,907 / 0,811 / 0,692) : limite documentée (« un simple flou ») |
| D | Photo 16:9 du mineur + deux dates écrites (attaque du contrôleur) | aucun | vérifié majeur | **refusé** (`document_not_detected`) |
| E1 | **« Carte maison »** : rectangle blanc au format ID-1, photo du mineur à gauche, **deux dates tapées** (naissance et expiration du parent), rien d'autre (ni titre, ni nom, ni numéro), photographié sur une table ; verso réel du parent | éditeur d'images + impression (ou écran) | – | **passe** (0,755) |
| E2 | Même image envoyée **en fichier** (repli « image entière au format carte ») | éditeur d'images | – | **passe** (0,759) |
| E3 | **Selfie recadré en 16:10** (1,60, dans la tolérance de ± 4 % du repli) + deux dates tapées ; verso du parent | éditeur de photos de téléphone | – | **passe** (0,739) |

Lecture :
- **E1 est satisfaite pour ce qu'elle visait** : aucun contournement **sans outil**. A, B et D sont fermés, avec une
  règle générique (date de naissance ET numéro ou expiration) qui ne dépend pas d'un gabarit de pays. Le taux de
  faux rejets mesuré (1,4 % sur un jeu synthétique, 36/36 pièces combinées refusées) est plausible. Il reste à
  confirmer sur de vraies pièces, ce qui est déjà un prérequis de production dans `docs/rgpd.md`.
- **Ce qui reste, et que j'ai vérifié :** le « document » détecté n'est qu'**un rectangle au format carte, avec un
  visage à gauche et deux dates concordantes**. E3 est l'attaque D du contrôleur **plus un recadrage** : le correctif
  du repli (± 4 %) ne tient donc que face à quelqu'un qui ne recadre pas. C'est la catégorie « recto fabriqué / faux
  document cohérent », **annoncée** aux clients (`docs/integration.md` : « Un recto fabriqué (photo au format carte
  avec les dates du parent) relève du faux document »). Elle ne se ferme vraiment qu'avec des gabarits de pièces
  (R8), prévus en V1.1.
- **Pourquoi je ne rejette pas pour E1–E3 :**
  - le mécanisme demandé est en place et prouvé ;
  - la limite est inhérente à une V1 sans gabarit ni contrôle des éléments de sécurité ;
  - elle est dite aux clients, dans le cadre « faible à modéré » décidé par Renaud ;
  - retirer le repli ne ferait que remplacer l'éditeur de photos par une impression ou un second écran (E1 passe
    aussi).
  - Voir néanmoins la recommandation **N1**.

## Vérification des exigences

- **E1 : SATISFAITE** (voir ci-dessus).
  - `document.py` et `analysis.py` : le type de document vient de PHP ; pour une carte, la MRZ est lue au verso
    seulement ; pour un passeport, la MRZ et le portrait sont sur la même page.
  - Portrait à sa place, OCR de la zone VIZ (MRZ exclue de la zone lue).
  - Liaison ratée → **aucun score** : le résultat ne peut pas être positif, en défense en profondeur.
  - Type de document ↔ type de MRZ contrôlé.
  - `document_unsupported` pour l'ancienne carte française.
  - Motifs stables, contrat de six champs inchangé, rien de lu ne sort.
  - Consentement FR/EN mis à jour (lecture des champs imprimés).
- **E2 : SATISFAITE.**
  - Encadré FR/EN « garantit / ne garantit pas » dans `docs/integration.md`, section AIPD de `docs/rgpd.md`,
    README, en-tête de `liveness.py`, E2E.
  - Le niveau « faible à modéré », l'absence de certification et l'absence de détection d'injection ou de deepfake
    sont dits. La photo animée injectée est reconnue comme passant (l'E2E le montre et le contrôle comme tel).
  - Le contrôle naïf du portrait recopié est annoncé comme tel. Le faux recto est annoncé.
  - Honnêteté : bonne. Deux imprécisions, non bloquantes (N2).
- **E3 : SATISFAITE.**
  - `MANUAL_REVIEW_ENABLED = false` : sous le seuil, la vérification échoue toujours. Refus en ligne de commande
    (vérifié), `ConfigValidator` n'accepte que `fail`.
  - Migration 0020 (données seulement), vérifiée sur base vierge.
  - L'approbation d'une ancienne revue « pending » est bloquée.
  - `review` est retiré de l'API et des documents. La voie d'intervention humaine au sens de l'art. 22 est
    indiquée (⚖️).

## Régressions

Aucune trouvée :
- suites PHP et Python vertes ;
- E2E complet vert (parcours légitime simulé, passeport, vidéo fixe, sans caméra) ;
- traductions à 100 % ;
- migrations vierges OK ;
- défis élargis vérifiés dans le code (4 actions, 4 défis, jamais deux identiques de suite = 108 suites ; bouche
  mesurée sur MediaPipe).

## Grille (ré-audit)

| # | Critère | État |
|---|---|---|
| 1 | Conformité | **OK** dans le cadre décidé par Renaud. Le cahier des charges demandait d'« empêcher l'usage d'une photo ou d'une vidéo » : ce n'est atteint que contre la photo fixe ou pivotée et contre la vidéo rejouée, pas contre une photo animée injectée. L'écart est assumé par décision du propriétaire et annoncé aux clients. |
| 2 | Sécurité ASVS L2 | **OK**. Transport inchangé et sain. Logique métier : les contournements sans outil sont fermés, la limite résiduelle est documentée. |
| 3 | RGPD | **OK**. Rien de lu ne sort ni n'est conservé ; consentement mis à jour ; plus de revue sans image ; art. 22 ⚖️ tracé. |
| 4 | Qualité et architecture | **OK**. `document.py` est clair ; sa règle et ses tolérances sont justifiées en en-tête. |
| 5 | Tests | **OK**. Non-régression A, B, C, D (pytest, PHP → vrai service, E2E) ; limite C' en `xfail(strict)` ; formats d'appareil photo testés. |
| 6 | i18n | **OK** (100 %). |
| 7 | UX et accessibilité | **OK**. Messages d'échec clairs (`document_inconsistent`, `document_unsupported`, qui oriente vers le passeport ou une autre méthode). Voir N4 pour les captures. |
| 8 | Déploiement | **OK sur papier** : modèle `eng` ajouté avec somme SHA-256. Rien de nouveau côté serveur. |

## Exigences bloquantes restantes

Aucune.

## Recommandations non bloquantes (ré-audit)

1. **N1, repli « image entière » :** il accepte n'importe quelle image au format carte (± 4 %). Un selfie recadré en
   16:10 avec deux dates tapées passe (E3), sans aucun objet physique. Deux options :
   - retirer ce repli : exiger un quadrilatère détecté avec du fond autour, et demander de photographier la carte
     posée sur une table ;
   - ou, en repli seulement, exiger les trois champs (naissance, numéro, expiration) et un minimum de texte
     imprimé.
   Gain modeste, puisque E1 (carte maison imprimée) passe aussi, mais le repli redeviendrait plus strict que le
   chemin principal (leçon du 2026-09-28). À faire avant la production, ou avec les gabarits de pièces (R8, V1.1),
   qui sont la vraie parade.
2. **N2, précision des textes :**
   - `docs/integration.md` (FR/EN), « Elle vérifie : une pièce d'identité … détectée sur la photo » : écrire plutôt
     « une forme au format d'une carte (ou d'une page de passeport), portrait à gauche », et préciser qu'**un recto
     fabriqué en quelques minutes avec un éditeur d'images, accompagné du vrai verso d'un parent, passe**.
     L'expression « faux document » suggère un effort de faussaire qui n'est pas nécessaire.
   - Même précision dans la section AIPD de `docs/rgpd.md`.
   - `liveness.py` et le contrôleur citent « σ = 3 » pour le flou qui contourne `face_identical_to_document` : **σ = 2
     suffit** (mesuré : 0,907 < 0,92). Écrire « un léger flou ».
3. **N3, message d'échec `document_inconsistent` :** il indique au fraudeur exactement quels champs recopier.
   C'est acceptable pour l'UX d'un utilisateur légitime. À garder à l'esprit si l'on ajoute des gabarits : les
   motifs fins doivent rester côté service.
4. **N4, captures versionnées :** le masque couvre toute la zone vidéo, y compris la **bannière de consigne**
   (`capture-fr-desktop-8-defi.png` ne montre plus le défi). Il faudrait masquer seulement les pixels du flux et de
   l'aperçu, pas la surimpression de l'interface.
5. **N5, suite des recommandations du premier audit :**
   - R2 (cohérence 3D) ;
   - R3 (anti-usurpation passif) ;
   - R4 (calibrage des seuils, frères et sœurs) ;
   - R5 (avertissement caméra virtuelle) ;
   - R6 (ne jamais déployer `tools/`/`tests/`, dépôt privé : `tools/e2e_capture.py` + `synth.py` restent un kit de
     contournement) ;
   - R7 (analyseur de l'ancienne carte française) ;
   - R8 (gabarits, qui fermeraient aussi N1) ;
   - R9 et R10.
   Tous sont tracés dans `phase-3-critique-suivi.md`, report accepté.
6. **N6 :** les photos de test anciennes restent dans l'historique git (signalé par le contrôleur). Réécrire
   l'historique relève d'une décision de Renaud. Sans urgence, puisque le dépôt est privé.

Nombre de recommandations non bloquantes du ré-audit : 6.
