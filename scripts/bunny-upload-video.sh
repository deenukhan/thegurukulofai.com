#!/usr/bin/env bash
# Upload a video to Bunny Stream and print the iframe embed URL + IDs.
# Usage: scripts/bunny-upload-video.sh "Video Title" <local-file.mp4>
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
set -a && . "$ROOT/.env" && set +a

TITLE="${1:?Usage: bunny-upload-video.sh \"Title\" <file.mp4>}"
FILE="${2:?Usage: bunny-upload-video.sh \"Title\" <file.mp4>}"
LIB="$BUNNY_STREAM_LIBRARY_ID"

# 1) create the video object
GUID=$(curl -s -X POST "https://video.bunnycdn.com/library/$LIB/videos" \
  -H "AccessKey: $BUNNY_STREAM_API_KEY" -H "Content-Type: application/json" \
  -d "{\"title\":$(python3 -c "import json,sys;print(json.dumps(sys.argv[1]))" "$TITLE")}" \
  | python3 -c "import sys,json;print(json.load(sys.stdin)['guid'])")

# 2) upload the file
code=$(curl -s -o /dev/null -w "%{http_code}" -X PUT \
  "https://video.bunnycdn.com/library/$LIB/videos/$GUID" \
  -H "AccessKey: $BUNNY_STREAM_API_KEY" --upload-file "$FILE")

if [ "$code" = "200" ]; then
  echo "Uploaded. Bunny is now encoding (adaptive HLS) in the background."
  echo "library_id: $LIB"
  echo "video_guid: $GUID"
  echo "embed_url:  https://iframe.mediadelivery.net/embed/$LIB/$GUID"
  echo "thumbnail:  https://$BUNNY_STREAM_CDN_HOST/$GUID/thumbnail.jpg"
else
  echo "Upload FAILED (HTTP $code)" >&2; exit 1
fi
