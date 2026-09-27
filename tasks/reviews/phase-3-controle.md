# Phase 3 : rapport du contrôleur

Date : 2026-09-27. Base contrôlée : commit `070e51a` (créateur), diff `76dba7d..070e51a` (124 fichiers).
Corrections du contrôleur laissées dans l'arbre de travail : ni commit, ni push.

Périmètre relu : tout le diff : `biometrics/` (service, scripts, tests), `app/` (client, transport, fournisseur,
contrôleur de capture, service, modèles, validation de configuration), migrations 0016 à 0019, `capture.js`,
vues, `lang/`, `deploy/`, `README.md`, `docs/`. Le tout au regard de `CLAUDE.md`, `tasks/lessons.md`,
`tasks/todo.md` et du cahier des charges (§ 3, méthode 1, et § 7).

Appréciation générale : phase de très bon niveau. Le HMAC mutuel est lié au nonce, le contrat de réponse
est vérifié strictement des deux côtés, les images ne sont jamais écrites par l'application (Tesseract lit
stdin et écrit stdout, preuve par strace), les dimensions sont lues avant tout décodage, les défis sont
tirés côté serveur avec des tirages bornés, et les licences sont documentées avec honnêteté. Défauts
trouvés : une fuite possible dans les journaux d'Uvicorn, une absence de budget de temps (analyse de
80 s), une panne technique traitée comme un échec métier, du code PHP mort, et des durcissements.

## Problèmes trouvés et corrigés

| # | Gravité | Emplacement (dans `070e51a`) | Problème | Correction |
|---|---|---|---|---|
| 1 | Élevée (RGPD) | `biometrics/veriage_biometrics/api.py:67`, `__main__.py:61` | Le gestionnaire global `unexpected` répond bien 500 en ne journalisant que le type. Mais le `ServerErrorMiddleware` de Starlette **relance toujours** l'exception, et Uvicorn journalise alors `Exception in ASGI application` avec **la pile complète et le message**, lequel peut recopier une valeur lue (texte OCR, champ reçu). Démontré avec un vrai Uvicorn : pile complète dans le journal. | Toute exception de `/v1/analyze` est rattrapée dans la route : type seul dans le journal, réponse 500 **signée avec le nonce** (PHP la classe en panne et non en réponse forgée). Filtre `NoTraceback` sur le gestionnaire racine : il retire `exc_info` et `stack_info` et ne garde que le type. Uvicorn passe par ce gestionnaire (`log_config=None`). Démontré : `ERROR uvicorn.error Exception in ASGI application (AttributeError)`, sans pile. |
| 2 | Élevée (disponibilité) | `mrz_ocr.py:38-39, 81-101`, `analysis.py:97-104` | Aucun budget global de l'OCR : jusqu'à 24 appels de 15 s au plus **par face**. Mesure : une image de bruit (2000×1500) prend **39 s** par lecture, soit ≈ 80 s pour une carte (verso, puis recto). Le délai de PHP est de 45 s : l'utilisateur échoue, et la place d'analyse (2 au total) reste occupée. Deux envois de documents illisibles saturent le service. | `MrzReader.read(…, deadline)` : arrêt dès que le budget est épuisé, délai de chaque appel à Tesseract borné par le temps restant. `analysis.MRZ_BUDGET_S = 20` s, partagé entre les deux faces. Mesure : 2,6 s avec un budget de 3 s, sans appel à Tesseract si le budget est déjà épuisé (test). |
| 3 | Moyenne | `LocalBiometricsProvider.php:79-87` | Une panne technique (service arrêté, 503 « busy », délai dépassé, réponse non signée ou hors contrat) devenait un **échec de session** `biometrics_unavailable` : webhook `failed` au client, +1 au compteur de blocage de l'adresse, session perdue (il fallait repasser par le site, et peut-être payer un nouveau crédit en phase 6). Avec 2 places d'analyse, un troisième utilisateur simultané échouait. | Panne : `BiometricsException` relancée. Le contrôleur répond `503 {"error":"biometrics_unavailable"}`, **la session reste ouverte** (ni échec, ni webhook, ni blocage). `capture.js` propose de recommencer (libellé FR/EN). Les tirages de défis ne sont **pas** remboursés : sinon, un fraudeur qui sature le service obtiendrait des tirages illimités. Un refus 422 (`capture_rejected`) reste un échec. |
| 4 | Moyenne (charge) | `api.py:99` | Place d'analyse indisponible : 503 immédiat (`acquire(blocking=False)`), donc au moindre pic de charge. | `asyncio.Semaphore` avec attente bornée (`Settings.queue_wait` = 10 s), puis 503. Le délai de PHP (45 s) couvre attente + analyse (≈ 2,5 s) + budget OCR. |
| 5 | Faible (sécurité) | `ConfigValidator.php:120` | Seuil d'acceptation accepté dès qu'il dépasse 0. Or 0,363 est le seuil « même personne » publié par OpenCV pour SFace. Fixé en dessous, VeriAge accepterait automatiquement des paires que le modèle juge de deux personnes (la fraude visée : la pièce d'un aîné). `biometricsProblems()` n'avait aucun test. | Plancher : acceptation ≥ 0,363 (en dessous, c'est la bande de revue qui s'applique). Test `testBiometricsSettingsAreChecked` : valeurs livrées valides, 7 problèmes détectés, aucun secret dans les messages. |
| 6 | Faible (qualité) | `app/Verification/Biometrics/Mrz.php` (285 lignes) | Code mort : jamais appelé par l'application, seulement par ses propres tests. Selon l'arbitrage, la MRZ ne sort jamais du service : cette classe ne servirait jamais en production. La garder, c'est entretenir une seconde implémentation qui dériverait sans que rien ne le détecte. | Suppression de `Mrz.php` et de `MrzTest.php`. `AgeCalculator` reste (eID, phase 4). Nouveau `AgeCalculatorTest::testSameAgeRuleAsTheBiometricsService` : même règle d'âge et d'expiration sur les vecteurs de `mrz_vectors.json`. Les contrôles MRZ restent testés côté Python (41 tests). |
| 7 | Faible (déploiement) | `deploy/systemd/veriage-biometrics.service:50` | `SystemCallFilter=~@resources …` sans `SystemCallErrorNumber` : un appel refusé tue le processus (SIGSYS). OpenMP/XNNPACK (MediaPipe, Tesseract) peuvent tenter `sched_setaffinity` : le service mourrait en pleine analyse. | `SystemCallErrorNumber=EPERM` : l'appel échoue proprement. `systemd-analyze verify` passe (seul le chemin de l'exécutable, absent ici, est signalé). |
| 8 | Faible (déploiement) | `README.md` | Le code et les modèles sont dans le home Hestia de `veriage`, que l'utilisateur `veriage-bio` ne peut pas traverser. Le service ne démarrerait pas (modèles illisibles). | Procédure `setfacl` (traversée seule, puis lecture de `biometrics/`) et commande de vérification. |

## Arbitrage MRZ (orchestrateur) appliqué

La MRZ ne sort jamais du microservice, et la minimisation prime. Choix le plus propre : **supprimer** la
classe PHP `Mrz` (point 6), plutôt que la garder « pour les tests de parité ». Une parité ne protège que
du code exécuté. La seule règle que PHP réutilisera (l'âge, en phase 4) est vérifiée sur les mêmes vecteurs.

## Points vérifiés sans anomalie

- **Aucune donnée d'identité persistée.**
  - PHP : corps lu depuis `php://input`, déchiffré et validé en mémoire (`getimagesizefromstring`, sans
    décodage). Journal : codes stables seulement (`biometrics_call_failed` + motif). Piles sans arguments
    (`Logger::exceptionContext`, `zend.exception_ignore_args=On`).
  - Redis : défi (identifiant, ordre, heure) et compteur. Base : horodatages, score, motifs et booléen
    provisoire.
  - Python : `access_log=False`, journal limité à la durée et aux motifs, Tesseract par stdin/stdout.
    Fichiers ouverts par le processus : le modèle seul.
  - Après correction : aucune pile d'exception (point 1). Journal du service après l'E2E : durée et motifs seulement.
- **HMAC** : requête signée sur (horodatage, nonce, méthode, chemin, SHA-256 du corps), vérification à temps
  constant, fenêtre de ±30 s. Nonce enregistré APRÈS la vérification, oublié au bout de 2 × la fenêtre
  (sans conséquence : un nonce oublié est de toute façon hors fenêtre). Réponse signée sur (nonce, statut,
  corps) et vérifiée par PHP avant toute lecture. Contrat de six champs strict, cohérence MRZ/âge.
  URL limitée à la boucle locale, sans proxy ni redirection, réponse lue sur 64 Kio au plus.
- **Entrées** : taille bornée avant décodage (PHP 12 Mio, Python 16 Mio lus par morceaux, sans croire
  `Content-Length`). Base64 strict. Type identifié par la signature (JPEG, PNG). Dimensions lues dans
  l'en-tête (SOFn, IHDR) puis bornées (document 4096, image du selfie 1280/1920, taille minimale 64/480).
  `OPENCV_IO_MAX_IMAGE_PIXELS` = 24 Mpx, fixé avant l'import de cv2. Une bombe PNG pèse au plus
  4096² × 3 octets. JSON à profondeur 5, clés en liste blanche des deux côtés.
- **MRZ** (relue position par position contre l'ICAO 9303) :
  - TD1, TD2 et TD3 : champs, types de caractères, composites (TD1 : `l1[5:30] + l2[0:7] + l2[8:15] +
    l2[18:29]`, qui exclut le sexe ; TD2 et TD3 conformes).
  - Numéro long en TD1 (débordement dans la zone facultative) ; chiffre de contrôle `<` d'un champ vide.
  - Sexe `<` ou `X` ; État sur une ou deux lettres (`D<<`).
  - Siècle de naissance : la date passée la plus récente (prudent : un centenaire est lu comme un enfant).
    Siècle d'expiration : ±50 ans. Parties inconnues de la date de naissance : date la plus tardive.
  - Ajout de tests : carte allemande réelle, numéro long en TD1, siècle d'expiration, date inconnue.
- **Âge** : années révolues, l'anniversaire compte le jour même, né un 29 février : un an de plus le
  1er mars. Date du jour prise à Bruxelles et comparée à ±1 jour à celle du service. Document valable
  jusqu'à sa date d'expiration incluse.
- **Liveness** :
  - Défis tirés par `random_int` (Fisher-Yates), conservés dans Redis, consommés atomiquement
    (`MULTI GET DEL`), 3 tirages par session, 300 s de validité.
  - Délai minimal côté serveur (80 % du rythme imposé). Durée couverte par les horodatages ≤ temps écoulé + 2 s.
  - Ordre et fenêtre de chaque défi, aucun mouvement contraire, un seul visage, même visage (SFace), lacet
    nez/joues (une photo plane pivotée échoue).
- **Seuils** : ordre revue ≤ acceptation imposé (constructeur + validation), revue seulement si le projet
  l'a choisie et si le contrôle du vivant est réussi.
- **AES-GCM dans le navigateur** : utile. Dans la stack Hestia, nginx en frontal met sur disque les corps
  de plus de 16 Kio (`client_body_temp`, réglage global que l'on ne peut pas toucher). PHP-FPM aussi
  (`upload_tmp_dir`). Ces fichiers ne contiennent qu'un chiffré. Clé propre à chaque capture (HMAC d'une
  clé dérivée, jamais stockée), IV aléatoire de 12 octets, **un seul chiffrement par clé** (capture à usage
  unique ; nouvelle clé à chaque essai), AAD session + capture, étiquette de 128 bits. Limite assumée :
  ce n'est pas un chiffrement de bout en bout contre le serveur (qui fournit la clé), et ce n'est pas son but.
- **Modèles** : sommes SHA-256 recalculées, identiques au script et à `docs/licences.md` (YuNet MIT,
  SFace Apache-2.0, Face Landmarker Apache-2.0, MRZ OCR-B BSD-3). URL figées, HTTPS seul. Réserve sur les
  données d'entraînement documentée (⚖️).
- **systemd** : utilisateur dédié, `ProtectSystem=strict`, `PrivateTmp`, `NoNewPrivileges`, aucune
  capacité, `IPAddressDeny=any`, `LimitCORE=0`, `MemoryMax=2G`, `UMask=0077`.
- **Accessibilité** :
  - Région `aria-live="assertive"` (consigne + progression), bannière visuelle `aria-hidden`.
  - Focus déplacé sur le titre de chaque étape et sur l'erreur (`role="alert"`, `tabindex=-1`).
  - Libellés de la vidéo et du champ fichier, statut de caméra en `role="status"`.
  - Le défi minuté relève de l'exception « essentiel » de WCAG 2.2.1 (la limite de temps est la mesure elle-même).
  - Autre méthode toujours proposée.

## Avis : performances et file d'attente

Mesure réelle : 2,1 à 2,7 s par analyse (journal du service pendant l'E2E). **Pas de file d'attente
maintenant.** Le synchrone est correct à ce volume : ≈ 2 analyses simultanées × 0,4 par seconde, soit
≈ 2 800 par heure. Il est maintenant protégé par l'attente bornée (point 4), le budget OCR (point 2) et
la session laissée ouverte en cas de panne (point 3). Une file + un worker n'apporteraient qu'une page
d'attente avec interrogation, pour une latence identique. Deux points à surveiller (phase 10) :

- le worker PHP-FPM reste occupé pendant l'analyse. Dimensionner `pm.max_children` du pool VeriAge, ou
  donner un pool distinct à `/s/*/document/submit`, pour que l'API ne soit pas affamée ;
- `BIOMETRICS_CONCURRENCY` = nombre de cœurs libres.

## Propositions non faites (et pourquoi)

1. **⚠ Authenticité du document (Élevée, hors de portée d'un correctif)** : la MRZ et ses chiffres de
   contrôle ne prouvent rien contre un faux. N'importe qui peut calculer une MRZ valide d'adulte et
   l'imprimer sous sa propre photo : la comparaison des visages passe. Parades possibles : lecture de la
   puce NFC (impossible dans un navigateur), contrôle des éléments de sécurité (hologrammes, OVI),
   cohérence VIZ/MRZ par OCR du recto. À faire figurer explicitement dans les limites et dans l'AIPD. Le
   critique et Renaud décident du niveau d'assurance visé.
2. **Espace des défis trop petit** : 3 actions dans un ordre aléatoire = **6** ordres. Avec 3 tirages
   par session, une vidéo préparée pour chaque ordre et une caméra virtuelle, la probabilité de succès est
   d'environ 50 % par session. Amélioration peu coûteuse : répétitions autorisées sans deux défis
   identiques consécutifs (12 suites avec 3 défis, 24 avec 4), durées de fenêtre aléatoires. Non fait :
   touche au rythme de la capture, à l'E2E et aux captures d'écran. À décider avec l'audit.
3. **Revue manuelle sur scores seulement** : sans image, l'opérateur ne dispose que du score (0,30–0,40) et
   de « vivant : oui ». Sa décision n'est pas mieux informée que le seuil : c'est un arbitrage
   discrétionnaire. Soit on conserve les images de façon chiffrée et limitée pour la revue (décision
   juridique, `docs/rgpd.md`), soit on retire l'option. Le réglage par défaut `fail` limite le risque.
4. **Anciennes cartes d'identité françaises** (format national 2 × 36, non ICAO, en circulation jusqu'en
   2034-2036) : elles sont lues comme des TD2 et refusées (`document_unreadable`). Il faudrait un analyseur
   dédié. Fonctionnalité, pas correctif.
5. **Corps binaire lu pour toute route** (`Request.php:72`) : tout POST `application/octet-stream`, sur
   n'importe quelle route, est lu en mémoire jusqu'à 16 Mio (`post_max_size` le borne déjà). La lecture
   paresseuse dans `binaryBody()` serait plus sobre. Gain faible, touche au noyau et à `HttpClient` des tests.
6. `decideReview()` : la décision de revue et la clôture de la session sont deux transactions. Une
   session modifiée entre les deux laisserait une revue « approved » sans résultat. Fenêtre infime (outil
   en ligne de commande). À reprendre avec l'interface d'administration (phase 7).

## Tests ajoutés ou modifiés

- Python (+7) : `test_internal_error_is_signed_with_the_nonce_and_never_echoes_data`,
  `test_busy_service_waits_briefly_then_answers_503`, `test_log_filter_drops_tracebacks_and_exception_messages`,
  `test_mrz_reading_stops_at_the_deadline`, `test_real_layout_german_id_with_filler_sex_and_short_state`,
  `test_td1_long_document_number_overflows_into_the_optional_field`,
  `test_expiry_century_and_unknown_birth_parts_are_prudent`.
- PHP :
  - `DocumentCaptureTest::testServiceOutageOrForgedResponseLeavesTheSessionOpen` (remplace l'ancien test
    d'échec technique : service arrêté, réponse forgée, champ en trop → 503, session `pending`, aucun
    webhook, rien dans le journal) ;
  - `DocumentCaptureTest::testCaptureSucceedsAfterATransientOutage` ;
  - `LocalBiometricsProviderTest::testServiceFailureIsATechnicalFailure` (exception attendue) ;
  - `ConfigValidatorTest::testBiometricsSettingsAreChecked` ;
  - `AgeCalculatorTest::testSameAgeRuleAsTheBiometricsService` ;
  - `MrzTest` supprimé (22 tests, code supprimé).

## Résultats exacts

- `vendor/bin/phpunit` → **OK (324 tests, 2020 assertions)**, dont `BiometricsServiceTest` (PHP → vrai
  service lancé par le test, nouveau code) : OK (2 tests, 23 assertions). Avant le contrôle : 343 tests ;
  −22 (`MrzTest`), +3.
- `biometrics/.venv/bin/python -m pytest -q` → **103 passed** (96 avant).
- `php tools/check_translations.php` → **100 % fr, en** (355 clés citées, 0 inconnue).
- `python3 tools/e2e_capture.py` (Chromium réel, service redémarré avec le nouveau code) → **9/9**. Captures
  régénérées dans le scratchpad pour contrôle, non recopiées : l'interface n'a pas changé visuellement.
- cURL : `POST /v1/analyze` non signé → `401 {"error":"unauthorized"}` ; `/docs` et `/openapi.json` → 404 ;
  `php bin/biometrics.php health` → `status ok, mrz, tesseract, mediapipe`.
- `systemd-analyze verify` : syntaxe valide.

Leçons ajoutées à `tasks/lessons.md` : piles d'exception d'Uvicorn et budget de temps global ; une panne
technique n'est pas une décision.
