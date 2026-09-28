# Phase 3 : suivi de l'audit du critique (créateur, 2026-09-28)

Base : `d9b185b` (rapport du critique REJETÉ). Tests sur base jetable (`veriage_e2e`, supprimée en bloc) ;
aucune ligne du journal d'audit n'a été supprimée ni modifiée.

## Exigences bloquantes

| Réf. | Correction | Preuve |
|---|---|---|
| **E1** Liaison recto / verso / portrait | `biometrics/veriage_biometrics/document.py`, `analysis.py` : type de document transmis par PHP ; carte → MRZ lue au VERSO seulement, passeport → MRZ lue sur la page du portrait (même image, exigé) ; document détecté sur la face du portrait (quadrilatère au format ID-1 1,586 ou TD3 1,42 ± 15 %, redressé ; repli : image entière au bon format) ; portrait À L'INTÉRIEUR, à sa place (moitié gauche, taille plausible ; passeport : au-dessus de la MRZ) ; OCR des champs imprimés (Tesseract `eng` tessdata_fast, Apache-2.0, SHA-256 imposé) sur la zone à droite du portrait (MRZ exclue) et concordance avec la MRZ. **Règle : date de naissance ET (numéro OU expiration)**, justifiée en tête de `document.py` (la date de naissance est la donnée attaquée ; un 2ᵉ champ lie les faces à UNE pièce ; 2/3 et non 3/3 car l'OCR d'un petit champ sur fond guilloché échoue souvent). Formats FR/NL/DE/EN, numéro belge avec tirets, confusions O/0, I/1… Échec de liaison → motifs `document_not_detected`, `document_portrait_not_found`, `document_sides_mismatch`, `document_front_unreadable`, `document_type_mismatch`, et **aucun score** (défense en profondeur) ; PHP → `document_inconsistent` (FR/EN, `docs/integration.md` § 8). Recto illisible = échec. Consentement mis à jour (lecture des champs imprimés). | pytest `test_document.py` : A et B **échouent** ; C (portrait du document animé) **échoue** (`face_identical_to_document`, nouveau contrôle : similarité ≥ 0,92 avec le portrait) ; C' (autre photo animée) : `xfail(strict)`, limite documentée. PHP : `BiometricsServiceTest` (vrai service) A et B → `document_inconsistent`. Vraies cartes synthétiques cohérentes (plate, inclinée, petite, floue, retournée, perspective ; 3 styles de date ; passeport) : passent. Mesure `scripts/measure_binding.py` : **1,4 % de faux rejets (1/72), 100 % des pièces combinées refusées (36/36)** sur jeu synthétique. |
| **E2** Niveau d'assurance dit honnêtement | Encadré FR/EN « ce qu'elle garantit / ne garantit pas » dans `docs/integration.md` (faible à modéré, aucune certification ISO 30107-3, aucune détection d'injection ni de deepfake, aucun contrôle des éléments de sécurité, sosies non mesurés, pas de conformité à un référentiel exigeant l'injection ⚖️) ; `docs/rgpd.md` (section niveau d'assurance pour l'AIPD) ; README ; `liveness.py` (en-tête : « résiste à une photo plane PIVOTÉE, pas à une photo DÉFORMÉE ») ; `tasks/todo.md` ; en-tête de `tools/e2e_capture.py`. | E2E : scénario 1 intitulé et contrôlé comme « LIMITE CONNUE : photo animée injectée acceptée ». |
| **E3** Revue manuelle retirée de la V1 | `LocalBiometricsProvider::MANUAL_REVIEW_ENABLED = false` (code conservé, désactivé) ; `ProjectAdmin::setBelowThreshold('review')` refusé (CLI) ; `ConfigValidator` refuse `BIOMETRICS_BELOW_THRESHOLD≠fail` ; migration **0020** (neutralise : `review` → `fail`, sans DDL) ; `review` retiré de l'API et des docs ; art. 22 : autre méthode toujours proposée + contact (`docs/integration.md`, `docs/rgpd.md`, ⚖️). | `DocumentCaptureTest::testBelowThresholdAlwaysFailsManualReviewIsDisabled` (même un projet resté en `review` en base échoue), `LocalBiometricsProviderTest::testManualReviewIsDisabledInV1`, `ConfigValidatorTest`, CLI → exit 1. |

## Arbitrages traités (peu coûteux)

- **`document_unsupported`** : ancienne carte française (`IDFRA`, 2 × 36) reconnue → motif dédié, message FR/EN
  orientant vers le passeport ou une autre méthode (`test_old_french_identity_card_is_unsupported`).
- **Type choisi ↔ type de MRZ** : carte ↔ TD1/TD2, passeport ↔ TD3, sinon `document_type_mismatch`.
- **Défis élargis** : 4 actions (ajout de « ouvrir la bouche », mesurée par MediaPipe, points 13/14 et 78/308),
  4 défis, jamais deux identiques de suite : **108 suites** ; `max_frames` 80. Tests : logique simulée + mesure
  MediaPipe réelle sur bouche ouverte synthétique ; `DocumentCaptureTest` vérifie l'absence de répétition consécutive.
- **Cohérence 3D contre l'animation 2D (R2)** : **non faite**. Sans vidéo réelle de rotation de tête (volontaire
  consentant ou tête 3D rendue), impossible de calibrer un contrôle sans faux rejets ; le contrôle
  « selfie identique au portrait » ferme en revanche la variante C du critique à coût nul. Limite documentée.

## Reporté (tracé)

- R2 (cohérence 3D), R3 (anti-usurpation passif à licence commerciale), R4 (calibrage des seuils, sosies, écart
  d'âge photo/selfie), R5 (avertissement caméra virtuelle), R6 (dépôt privé, ne jamais déployer `tools/`/`tests/`),
  R7 (analyseur ancienne carte française), R8 (gabarits belges), R9 (lecture paresseuse, décision de revue en deux
  transactions), R10 (commentaire `ChallengeStore::consume`).
- Seuil « identique » (0,92) : calibré sur nos seules images (deux photos d'une même personne ≈ 0,73–0,76 ;
  portrait recopié ≈ 0,94–0,97) ; à valider sur des données réelles.

## Résultats

- `biometrics/.venv/bin/python -m pytest -q` → **129 passed, 1 xfailed** (limite connue C').
- `vendor/bin/phpunit` → **OK (332 tests, 2 048 assertions)**, dont `BiometricsServiceTest` (PHP → vrai service :
  majeur, mineur, autre visage, passeport expiré, attaques A et B).
- `php tools/check_translations.php` → **100 % fr et en** (358 clés citées, 0 inconnue).
- `tools/e2e_capture.py` (Chromium réel, base jetable) → **11/11**, captures `docs/screenshots/phase-3/` (19).
