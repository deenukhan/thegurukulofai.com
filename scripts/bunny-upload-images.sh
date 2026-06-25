#!/usr/bin/env bash
# Upload a folder of images/files to Bunny Storage under blog/<slug>/ and print CDN URLs.
# Usage: scripts/bunny-upload-images.sh <post-slug> <local-folder>
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
set -a && . "$ROOT/.env" && set +a

SLUG="${1:?Usage: bunny-upload-images.sh <post-slug> <local-folder>}"
DIR="${2:?Usage: bunny-upload-images.sh <post-slug> <local-folder>}"

echo "Uploading '$DIR' -> blog/$SLUG/ ..."
echo "----- CDN URLs -----"
find "$DIR" -type f ! -name ".DS_Store" | while read -r f; do
  rel="${f#"$DIR"/}"
  remote="blog/$SLUG/$rel"
  code=$(curl -s -o /dev/null -w "%{http_code}" --upload-file "$f" \
    -H "AccessKey: $BUNNY_STORAGE_PASSWORD" \
    "https://$BUNNY_STORAGE_HOST/$BUNNY_STORAGE_ZONE/$remote")
  if [ "$code" = "201" ]; then
    echo "https://$BUNNY_CDN_HOST/$remote"
  else
    echo "FAILED ($code): $rel" >&2
  fi
done
