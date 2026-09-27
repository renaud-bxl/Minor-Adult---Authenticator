#!/usr/bin/env bash
# VeriAge biométrie : téléchargement des modèles, UNE FOIS, au déploiement (jamais à l'exécution).
#
#   biometrics/scripts/fetch_models.sh [dossier_cible]      (défaut : biometrics/models)
#
# Chaque fichier est téléchargé depuis une URL figée (commit ou version), en HTTPS seulement, puis
# contrôlé par sa somme SHA-256 AVANT d'être mis en place. Une somme différente arrête le script :
# le fichier n'est jamais installé. Un fichier déjà présent et intègre n'est pas retéléchargé.
# Licences, sources et sommes : docs/licences.md (à tenir à jour avec ce script).
set -euo pipefail

TARGET="${1:-$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)/models}"
mkdir -p "$TARGET"
chmod 0750 "$TARGET"

# nom|sha256|url
MODELS=(
  "face_detection_yunet_2023mar.onnx|8f2383e4dd3cfbb4553ea8718107fc0423210dc964f9f4280604804ed2552fa4|https://media.githubusercontent.com/media/opencv/opencv_zoo/47534e27c9851bb1128ccc0102f1145e27f23f98/models/face_detection_yunet/face_detection_yunet_2023mar.onnx"
  "face_recognition_sface_2021dec.onnx|0ba9fbfa01b5270c96627c4ef784da859931e02f04419c829e83484087c34e79|https://media.githubusercontent.com/media/opencv/opencv_zoo/47534e27c9851bb1128ccc0102f1145e27f23f98/models/face_recognition_sface/face_recognition_sface_2021dec.onnx"
  "face_landmarker.task|64184e229b263107bc2b804c6625db1341ff2bb731874b0bcc2fe6544e0bc9ff|https://storage.googleapis.com/mediapipe-models/face_landmarker/face_landmarker/float16/1/face_landmarker.task"
  "tessdata/eng.traineddata|7d4322bd2a7749724879683fc3912cb542f19906c83bcc1a52132556427170b2|https://raw.githubusercontent.com/tesseract-ocr/tessdata_fast/87416418657359cb625c412a48b6e1d6d41c29bd/eng.traineddata"
  "tessdata/mrz.traineddata|e44f5b7a6bdd3f382ef3bfa84ee0057f5897946a84a094c26910e0a124f3a9bd|https://raw.githubusercontent.com/DoubangoTelecom/tesseractMRZ/1e7adfecda5f3c9ae1fb12cf6b4b8c3958c63e46/tessdata_best/mrz.traineddata"
)

sha256() { sha256sum "$1" | cut -d' ' -f1; }

status=0
for entry in "${MODELS[@]}"; do
  IFS='|' read -r name expected url <<<"$entry"
  dest="$TARGET/$name"
  mkdir -p "$(dirname "$dest")"
  if [[ -f "$dest" && "$(sha256 "$dest")" == "$expected" ]]; then
    echo "déjà présent et intègre : $name"
    continue
  fi
  tmp="$(mktemp "$TARGET/.download.XXXXXX")"
  trap 'rm -f "$tmp"' EXIT
  echo "téléchargement : $name"
  if ! curl --fail --silent --show-error --location --proto '=https' --proto-redir '=https' --tlsv1.2 \
       --retry 3 --max-time 600 --output "$tmp" "$url"; then
    echo "ÉCHEC du téléchargement : $name" >&2
    rm -f "$tmp"; status=1; continue
  fi
  actual="$(sha256 "$tmp")"
  if [[ "$actual" != "$expected" ]]; then
    echo "SOMME SHA-256 INVALIDE pour $name : attendu $expected, obtenu $actual (fichier rejeté)" >&2
    rm -f "$tmp"; status=1; continue
  fi
  chmod 0640 "$tmp"
  mv -f "$tmp" "$dest"
  trap - EXIT
  echo "installé : $name ($expected)"
done

exit "$status"
