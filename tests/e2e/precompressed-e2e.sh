#!/usr/bin/env bash
# Precompressed static pages (2.3.0) on the disposable stack selected by SBQA_STACK: with the option on, every page
# of the release has index.html.gz with the same content; a client that accepts gzip gets it with Content-Encoding:
# gzip, text/html and Vary: Accept-Encoding, compressed once; any other client gets the plain file. An incremental
# update rewrites both, a removal removes both, and with the option off the next write leaves no .gz behind.
# Run AFTER run-e2e.sh on the same ZIP (plugin active, release published).
# Usage: [SBQA_STACK=nginx|apache|nginx71] bash precompressed-e2e.sh
set -u
H=$(cd "$(dirname "$0")" && pwd)
. "$H/profile.sh"
sbqa_require_stack; E=$SBQA_E
PHP84="env LD_LIBRARY_PATH=$P/shared-libs $P/bin/php -n -c $E/conf/php.ini"
WP=$E/wordpress
S=$WP/wp-content/static-bridge
PASS=0; FAIL=0
ok() { if [ "$1" = "0" ]; then PASS=$((PASS+1)); echo "[PASS] $2"; else FAIL=$((FAIL+1)); echo "[FAIL] $2${3:+ :: $3}"; fi; }
jget() { php -r '$j=json_decode(stream_get_contents(STDIN),true); $v=$j; foreach(explode(".",$argv[1]) as $k){ $v=is_array($v)&&array_key_exists($k,$v)?$v[$k]:null; } echo is_scalar($v)?$v:json_encode($v);' "$1"; }
export_final() { php "$H/http-gui.php" administrator export | jget final; }
nginx_block() { [ "$SBQA_WEB" = "nginx" ] && $PHP84 "$H/wp69-harness.php" block > /dev/null && $NGX -t -p "$E/run" -c "$E/conf/nginx.conf" > /dev/null 2>&1 && $NGX -s reload -p "$E/run" -c "$E/conf/nginx.conf" && sleep 0.5; return 0; }
cron_until() { for _ in $(seq 1 36); do curl -s -o /dev/null "$B/wp-cron.php"; sleep 5; eval "$1" && return 0; done; return 1; }
T=$(mktemp -d); trap 'rm -rf "$T"' EXIT
# fetch <path> [gzip]: headers in $T/h, raw body in $T/b (curl does not decode it).
fetch() { if [ "${2:-}" = "gzip" ]; then curl -s -D "$T/h" -o "$T/b" -H 'Accept-Encoding: gzip' "$B$1"; else curl -s -D "$T/h" -o "$T/b" "$B$1"; fi; tr -d '\r' < "$T/h" | tr 'A-Z' 'a-z' > "$T/hl"; }
static_response() { grep -qE '^(x-static-bridge-route: static|x-sbqa-apache-file: static-bridge)' "$T/hl"; }
pair_ok() { [ -f "$S/current$1index.html.gz" ] && gzip -dc "$S/current$1index.html.gz" | cmp -s - "$S/current$1index.html"; }

[ -L "$S/current" ] || { echo "Run run-e2e.sh first (no published release)."; exit 2; }
: > "$E/logs/php-errors.log"

echo "== Option on, full export"
$PHP84 "$H/settings-form.php" precompressed_pages on > /dev/null; ok $? "precompressed pages switched on through the settings form"
F=$(export_final); [ "$(echo "$F" | jget status)" = "complete" ]; ok $? "full export completes ($(echo "$F" | jget saved) saved)" "$F"
nginx_block
[ "$SBQA_WEB" = "nginx" ] && { grep -q 'gzip_static on;' "$E/conf/static-bridge.conf"; ok $? "Nginx block has gzip_static on"; }
[ "$SBQA_WEB" = "apache" ] && { grep -q 'index.html.gz' "$WP/.htaccess" && grep -q 'SetEnv no-gzip 1' "$WP/.htaccess"; ok $? "Apache rules have the .gz rules and the <If> block"; }
MISSING=""; for f in $(cd "$S/current" && find . -name index.html | sed 's#^\.##; s#index.html$##'); do pair_ok "$f" || MISSING="$MISSING $f"; done
[ -z "$MISSING" ]; ok $? "every page of the release has a .gz with the same content" "missing:$MISSING"

echo "== Responses"
for path in / /hello-world/; do
    fetch "$path" gzip
    static_response && grep -q '^content-encoding: gzip' "$T/hl" && grep -qE '^content-type: text/html' "$T/hl" && grep -qE '^vary:.*accept-encoding' "$T/hl" \
        && gzip -dc "$T/b" | cmp -s - "$S/current${path}index.html"
    ok $? "$path with gzip: static, Content-Encoding gzip, text/html, Vary, compressed once and equal to the page" "$(tr '\n' ' ' < "$T/hl" | head -c 400)"
    fetch "$path"
    static_response && ! grep -q '^content-encoding:' "$T/hl" && cmp -s "$T/b" "$S/current${path}index.html"
    ok $? "$path without gzip: the plain page" "$(tr '\n' ' ' < "$T/hl" | head -c 300)"
done
curl -s -I -H 'Accept-Encoding: gzip' "$B/" | tr -d '\r' | tr 'A-Z' 'a-z' | grep -q '^content-encoding: gzip'; ok $? "HEAD with gzip answers the compressed headers"
fetch /wp-login.php gzip; ! static_response; ok $? "WordPress pages stay with WordPress"

echo "== Incremental update and removal"
MK="SBQA_GZ_$(date +%s%N)"; MKS=$(echo "$MK" | tr 'A-Z' 'a-z')  # WordPress keeps the underscores of this slug
NEW=$($PHP84 "$H/wp69-harness.php" archives publish "$MK"); NEW_ID=$(echo "$NEW" | jget post_id); [ "$(echo "$NEW" | jget result)" = "PASS" ]; ok $? "new post published"
cron_until "[ -f '$S/current/$MKS/index.html' ] && grep -q '$MK' '$S/current/index.html'"
ok $? "the incremental worker wrote the post and the home"
pair_ok / && pair_ok "/$MKS/"; ok $? "the home and the new post have a .gz equal to the new page"
fetch / gzip; gzip -dc "$T/b" | grep -q "$MK"; ok $? "a gzip client sees the new home"
OFF=$($PHP84 "$H/wp69-harness.php" archives unpublish "$NEW_ID"); [ "$(echo "$OFF" | jget result)" = "PASS" ]; ok $? "the post is moved back to draft"
cron_until "[ ! -e '$S/current/$MKS/index.html' ] && ! grep -q '$MK' '$S/current/index.html'"
[ ! -e "$S/current/$MKS/index.html.gz" ] && pair_ok /; ok $? "its page and its .gz are gone; the home and its .gz are new again"

echo "== Option off"
$PHP84 "$H/settings-form.php" precompressed_pages off > /dev/null; nginx_block
[ "$SBQA_WEB" = "nginx" ] && { ! grep -q 'gzip_static on;' "$E/conf/static-bridge.conf"; ok $? "Nginx block without gzip_static"; }
[ "$SBQA_WEB" = "apache" ] && { ! grep -q 'index.html.gz' "$WP/.htaccess"; ok $? "Apache rules without the .gz rules"; }
MK2="SBQA_GZ_$(date +%s%N)"
$PHP84 "$H/wp69-harness.php" archives publish "$MK2" > /dev/null
cron_until "grep -q '$MK2' '$S/current/index.html'"
[ ! -e "$S/current/index.html.gz" ]; ok $? "with the option off, rewriting the home leaves no .gz behind"
# The server may still compress on the fly (gzip on): whatever the encoding, the content must be the new home.
fetch / gzip; if grep -q '^content-encoding: gzip' "$T/hl"; then gzip -dc "$T/b" > "$T/d"; else cp "$T/b" "$T/d"; fi
cmp -s "$T/d" "$S/current/index.html"; ok $? "a gzip client gets the new home, not an old .gz"

NE=$(grep -cE 'wp-content/plugins/static-bridge/' "$E/logs/php-errors.log"); [ "$NE" = "0" ]; ok $? "no PHP notice/warning/error from Static Bridge ($NE lines)"
echo
echo "PRECOMPRESSED RESULT: $PASS passed, $FAIL failed — stack $SBQA_STACK"
[ "$FAIL" = "0" ]
