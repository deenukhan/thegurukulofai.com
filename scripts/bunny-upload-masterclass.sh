#!/usr/bin/env bash
# Upload masterclass reels, loop clips and posters to Bunny Storage under masterclass/.
# Usage: scripts/bunny-upload-masterclass.sh <local-folder>
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
set -a && . "$ROOT/.env" && set +a

DIR="${1:?Usage: bunny-upload-masterclass.sh <local-folder>}"

echo "Uploading '$DIR' -> masterclass/ ..."
find "$DIR" -type f ! -name ".DS_Store" | sort | while read -r f; do
  rel="$(basename "$f")"
  code=$(curl -s -o /dev/null -w "%{http_code}" --upload-file "$f" \
    -H "AccessKey: $BUNNY_STORAGE_PASSWORD" \
    "https://$BUNNY_STORAGE_HOST/$BUNNY_STORAGE_ZONE/masterclass/$rel")
  if [ "$code" = "201" ]; then
    echo "ok   https://$BUNNY_CDN_HOST/masterclass/$rel"
  else
    echo "FAIL ($code): $rel" >&2
  fi
done
