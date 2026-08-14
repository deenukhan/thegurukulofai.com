#!/usr/bin/env bash
# Upload portfolio ad videos to Bunny Storage under portfolio/ and print CDN URLs.
# Usage: scripts/bunny-upload-portfolio.sh <local-folder>
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
set -a && . "$ROOT/.env" && set +a

DIR="${1:?Usage: bunny-upload-portfolio.sh <local-folder>}"

echo "Uploading '$DIR' -> portfolio/ ..."
find "$DIR" -type f ! -name ".DS_Store" | sort | while read -r f; do
  rel="$(basename "$f")"
  code=$(curl -s -o /dev/null -w "%{http_code}" --upload-file "$f" \
    -H "AccessKey: $BUNNY_STORAGE_PASSWORD" \
    "https://$BUNNY_STORAGE_HOST/$BUNNY_STORAGE_ZONE/portfolio/$rel")
  if [ "$code" = "201" ]; then
    echo "ok   https://$BUNNY_CDN_HOST/portfolio/$rel"
  else
    echo "FAIL ($code): $rel" >&2
  fi
done
