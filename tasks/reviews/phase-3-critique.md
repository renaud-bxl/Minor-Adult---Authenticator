VERDICT : REJETÉ

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
