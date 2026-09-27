#!/usr/bin/env bash
# VeriAge biométrie : photos de TEST (visages du domaine public), téléchargées à la demande pour les
# tests et l'E2E, jamais versionnées (biometrics/tests/assets/ est ignoré par git) ni déployées.
#
#   biometrics/scripts/fetch_test_assets.sh
#
# - astronaut.png : Eileen Collins, photo officielle de la NASA (œuvre du gouvernement fédéral
#   américain, domaine public), distribuée par scikit-image (skimage/data) ;
# - obama.jpg, biden.jpg : portraits officiels de la Maison-Blanche (œuvres du gouvernement fédéral
#   américain, domaine public), distribués comme exemples du projet face_recognition.
# Les sommes SHA-256 sont contrôlées avant installation ; aucune vraie pièce d'identité n'est utilisée.
set -euo pipefail

TARGET="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)/tests/assets"
mkdir -p "$TARGET"

ASSETS=(
  "astronaut.png|88431cd9653ccd539741b555fb0a46b61558b301d4110412b5bc28b5e3ea6cb5|https://raw.githubusercontent.com/scikit-image/scikit-image/v0.24.0/skimage/data/astronaut.png"
  "obama.jpg|0930e3aa8cae5920329c0c8cbc6a2ab70f47b0e67b432875beaa95cbf7e741f6|https://raw.githubusercontent.com/ageitgey/face_recognition/9f3061aaeed9a8756d2c970f5dfe066617a8281d/examples/obama.jpg"
  "biden.jpg|3c17508bb91554c637a2eabddfae790e5bb1caba93130814fc2ac50be9760c4c|https://raw.githubusercontent.com/ageitgey/face_recognition/9f3061aaeed9a8756d2c970f5dfe066617a8281d/examples/biden.jpg"
)

status=0
for entry in "${ASSETS[@]}"; do
  IFS='|' read -r name expected url <<<"$entry"
  dest="$TARGET/$name"
  if [[ -f "$dest" && "$(sha256sum "$dest" | cut -d' ' -f1)" == "$expected" ]]; then
    echo "déjà présent : $name"; continue
  fi
  tmp="$(mktemp "$TARGET/.download.XXXXXX")"
  if curl --fail --silent --show-error --location --proto '=https' --tlsv1.2 --retry 3 --output "$tmp" "$url" \
     && [[ "$(sha256sum "$tmp" | cut -d' ' -f1)" == "$expected" ]]; then
    mv -f "$tmp" "$dest"; echo "installé : $name"
  else
    rm -f "$tmp"; echo "ÉCHEC (téléchargement ou somme SHA-256) : $name" >&2; status=1
  fi
done
exit "$status"
