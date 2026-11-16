#!/usr/bin/env bash
# Apache only: counts responses to anonymous GET / while the home file of the active
# release is renamed aside and back in a tight loop (what the render bypass does for
# a few milliseconds per rendered route). Prints status counts. Restores the file.
# Usage: SBQA_STACK=apache bash repro-apache-move-aside.sh [seconds]
set -u
H=$(cd "$(dirname "$0")" && pwd)
. "$H/profile.sh"
[ "$SBQA_WEB" = "apache" ] || { echo "apache only"; exit 2; }
sbqa_require_stack; E=$SBQA_E
F=$E/wordpress/wp-content/static-bridge/current/index.html
[ -f "$F" ] || { echo "no active release"; exit 2; }
SECS=${1:-10}
( end=$((SECONDS+SECS)); while [ $SECONDS -lt $end ]; do mv "$F" "$F.qa-aside" && mv "$F.qa-aside" "$F"; done ) & MV=$!
OUT=$(mktemp); end=$((SECONDS+SECS))
while [ $SECONDS -lt $end ]; do for i in 1 2 3 4 5 6 7 8; do curl -s -o /dev/null -w '%{http_code}\n' "$B/" & done; wait $(jobs -p | grep -v "^$MV$") 2>/dev/null; done >> "$OUT"
wait $MV; [ -f "$F.qa-aside" ] && mv "$F.qa-aside" "$F"
sort "$OUT" | uniq -c | tr '\n' ' '; echo; rm -f "$OUT"
