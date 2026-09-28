# Licences des modèles et composants de la biométrie locale

Règle (CLAUDE.md, propriétaire) : **aucun service externe** de vérification, de reconnaissance ou d'IA à
l'exécution ; uniquement des modèles sous licence **compatible avec un usage commercial**, téléchargés
**une fois, au déploiement**, par `biometrics/scripts/fetch_models.sh`, qui vérifie leur somme SHA-256
avant de les installer (une somme différente : fichier rejeté, code retour 1). Ce document et le script
doivent rester synchronisés.

## Modèles (téléchargés dans `biometrics/models/`, jamais versionnés)

| Fichier | Rôle | Licence | Source figée | SHA-256 |
|---|---|---|---|---|
| `face_detection_yunet_2023mar.onnx` | Détection de visage (OpenCV Zoo **YuNet**) | **MIT** (Shiqi Yu) | `opencv/opencv_zoo` @ `47534e27c985…`, `models/face_detection_yunet/` | `8f2383e4dd3cfbb4553ea8718107fc0423210dc964f9f4280604804ed2552fa4` |
| `face_recognition_sface_2021dec.onnx` | Comparaison de visages (OpenCV Zoo **SFace**) | **Apache-2.0** | `opencv/opencv_zoo` @ `47534e27c985…`, `models/face_recognition_sface/` | `0ba9fbfa01b5270c96627c4ef784da859931e02f04419c829e83484087c34e79` |
| `face_landmarker.task` | Points du visage : rotation de la tête, ouverture des yeux (**MediaPipe Face Landmarker**, float16, v1) | **Apache-2.0** (Google) | `storage.googleapis.com/mediapipe-models/face_landmarker/face_landmarker/float16/1/` | `64184e229b263107bc2b804c6625db1341ff2bb731874b0bcc2fe6544e0bc9ff` |
| `tessdata/eng.traineddata` | OCR générique de la face imprimée (liaison recto/verso) | **Apache-2.0** (Google, Tesseract) | `tesseract-ocr/tessdata_fast` @ `87416418657359cb…` | `7d4322bd2a7749724879683fc3912cb542f19906c83bcc1a52132556427170b2` (identique au paquet Debian/Ubuntu `tesseract-ocr-eng`) |
| `tessdata/mrz.traineddata` | OCR de la MRZ (police OCR-B) pour Tesseract | **BSD-3-Clause** (DoubangoTelecom) | `DoubangoTelecom/tesseractMRZ` @ `1e7adfecda5f…`, `tessdata_best/` | `e44f5b7a6bdd3f382ef3bfa84ee0057f5897946a84a094c26910e0a124f3a9bd` |

Les sommes de YuNet et SFace sont aussi celles publiées par le dépôt (pointeurs Git LFS, champ `oid`).
Repli si `mrz.traineddata` est absent : modèle `eng` de Tesseract (Apache-2.0) avec liste blanche de
caractères, moins précis.

## Logiciels

| Composant | Rôle | Licence | Installation |
|---|---|---|---|
| Tesseract OCR 5 | OCR (appelé en sous-processus, image sur l'entrée standard) | Apache-2.0 | `apt install tesseract-ocr` |
| Leptonica | Bibliothèque d'images de Tesseract | BSD-2-Clause | dépendance apt |
| OpenCV (`opencv-python-headless` 4.13) | Traitement d'images, `FaceDetectorYN`, `FaceRecognizerSF` | Apache-2.0 (roues : FFmpeg LGPL-2.1, liaison dynamique) | pip, `requirements.txt` |
| MediaPipe 1.0.1 | Exécution du Face Landmarker | Apache-2.0 | pip, `requirements-mediapipe.txt` (sans dépendances) |
| NumPy, FastAPI, Starlette, Uvicorn, Pydantic, AnyIO, h11, click, idna, typing-extensions, annotated-types, absl-py | Service HTTP, calcul | BSD-3 / MIT / Apache-2.0 | pip |
| flatbuffers | Dépendance de MediaPipe | Apache-2.0 | pip |
| matplotlib (+ contourpy, cycler, fonttools, kiwisolver, pyparsing, python-dateutil, six, pillow, packaging) | Importé par MediaPipe au chargement (aucun usage direct) | PSF-like (matplotlib), BSD / MIT / HPND / Apache-2.0 | pip |
| `libegl1`, `libgles2` | Bibliothèques système chargées par MediaPipe (même sans GPU) | MIT-like (libglvnd, Mesa) | `apt install libegl1 libgles2` |

Versions et sommes de toutes les roues : `biometrics/requirements*.txt` (installés avec
`--require-hashes`). Tests seulement : pytest (MIT), httpx (BSD-3).

## Écartés

- **InsightFace** : ses modèles pré-entraînés sont réservés à la recherche non commerciale. Exclu.
- **DeepFace** et ses poids (VGG-Face, ArcFace InsightFace…) : licences des poids hétérogènes ou non
  commerciales. Non utilisé.
- **pytesseract** (Apache-2.0) : écarté pour une raison de conception, pas de licence : il écrit l'image
  dans un fichier temporaire. Le service appelle Tesseract directement, image sur l'entrée standard.
- **PassportEye** (MIT) : non retenu (dépend de pytesseract et de scikit-image) ; la localisation de la
  MRZ est réimplémentée avec OpenCV.

## Données de test (jamais déployées, jamais versionnées)

`biometrics/scripts/fetch_test_assets.sh` télécharge, avec contrôle SHA-256, dans `biometrics/tests/assets/` :

| Fichier | Contenu | Statut |
|---|---|---|
| `astronaut.png` | Eileen Collins, photo officielle de la NASA (distribuée par scikit-image) | Domaine public (œuvre du gouvernement fédéral américain) |
| `obama.jpg`, `obama2.jpg`, `biden.jpg` | Portraits et photo officiels de la Maison-Blanche (distribués par le projet face_recognition) ; obama2 sert de portrait du document, obama de selfie (deux photos distinctes d'une même personne) | Domaine public (œuvres du gouvernement fédéral américain) |

Documents : uniquement **synthétiques**, générés par `biometrics/tests/synth.py` (État fictif « Utopie »,
code `UTO` des spécimens ICAO 9303, mention « SPECIMEN – NOT A REAL DOCUMENT »), MRZ rendue avec la police
**OCR-B de Matthew Skala** (domaine public, paquet Debian `fonts-ocr-b`). Aucune vraie pièce d'identité.

## ⚖️ Points à faire valider par un juriste

1. **Données d'entraînement** : les poids sont sous licence permissive, mais YuNet est entraîné sur
   WIDER FACE (jeu de données distribué « à des fins de recherche ») et SFace sur des jeux de visages
   publics (CASIA-WebFace, VGGFace2, MS-Celeb-1M selon la publication) dont les conditions sont
   restrictives ou contestées. La jurisprudence sur la transmission de ces restrictions aux poids n'est pas
   fixée. Idem pour le jeu d'entraînement de `mrz.traineddata` (images collectées en ligne, selon son
   auteur). À trancher avant la production ; alternative : entraîner nos propres modèles.
2. **Droit à l'image** des photos de test (personnalités publiques, domaine public) : usage limité aux
   tests internes, non publiées, non versionnées.
3. **Certification** : aucun de ces composants n'est certifié (ISO/IEC 30107-3 pour la détection
   d'attaque, ISO/IEC 19795 pour les performances). Taux d'erreur à mesurer sur un jeu réel avant la
   production (voir `docs/rgpd.md`, AIPD).
