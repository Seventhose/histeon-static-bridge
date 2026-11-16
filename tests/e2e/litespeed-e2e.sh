#!/usr/bin/env bash
# OpenLiteSpeed (2.3.0) on the litespeed stack: the server reads .htaccess only when it starts and ignores its Header
# directives. Static Bridge must then serve current/ (no served copy, whose rules would change at every publication),
# say through the Diagnostica when a restart is due (probe rule answering 410), give the context that adds the
# headers to static pages, leave compression to the server, and remember the server for WP-CLI and the system cron.
# Resets the stack to its baseline and installs the ZIP, like run-e2e.sh. Requires the stack running (stack.sh start).
# Usage: SBQA_STACK=litespeed bash litespeed-e2e.sh <path-to-static-bridge-x.y.z.zip>
set -u
H=$(cd "$(dirname "$0")" && pwd)
. "$H/profile.sh"
sbqa_require_stack; E=$SBQA_E
[ "$SBQA_LSWS" = "1" ] || { echo "REFUSED: litespeed-e2e.sh runs on SBQA_STACK=litespeed only"; exit 2; }
PLUGIN_SRC=${SBQA_PLUGIN_SOURCE:-$SBQA_REPO/plugin}
ZIP=${1:-}
case "$ZIP" in /*) ;; *) ZIP="$PWD/$ZIP" ;; esac
[ -f "$ZIP" ] || { echo "Usage: SBQA_STACK=litespeed bash litespeed-e2e.sh <zip>"; exit 2; }
PHP84="env LD_LIBRARY_PATH=$P/shared-libs $P/bin/php -n -c $E/conf/php.ini"
MYSQL="env LD_LIBRARY_PATH=$M/lib $M/bin/mysql --no-defaults -uroot --socket=$E/run/mysql.sock"
WP=$E/wordpress
S=$WP/wp-content/static-bridge
VH=$E/conf/ols/vhosts/sbqa/vhconf.conf
PASS=0; FAIL=0
ok() { if [ "$1" = "0" ]; then PASS=$((PASS+1)); echo "[PASS] $2"; else FAIL=$((FAIL+1)); echo "[FAIL] $2${3:+ :: $3}"; fi; }
jget() { php -r '$j=json_decode(stream_get_contents(STDIN),true); $v=$j; foreach(explode(".",$argv[1]) as $k){ $v=is_array($v)&&array_key_exists($k,$v)?$v[$k]:null; } echo is_scalar($v)?$v:json_encode($v);' "$1"; }
export_final() { php "$H/http-gui.php" administrator export | jget final; }
ht() { $PHP84 "$H/wp69-harness.php" htaccess | jget "$1"; }
push_url() { curl -s -H @"$E/run/cookie-$1.txt" "$B/wp-admin/edit.php" | grep -oE "admin-post\.php\?action=static_bridge_push_post&(amp;|#038;)post_id=$2&(amp;|#038;)_wpnonce=[a-f0-9]+" | head -1 | sed 's/&amp;/\&/g; s/&#038;/\&/g'; }
cron_until() { for _ in $(seq 1 36); do curl -s -o /dev/null "$B/wp-cron.php"; sleep 5; eval "$1" && return 0; done; return 1; }
# Restart of OpenLiteSpeed: the whole container, because after many graceful restarts in a row the detached lsphp
# of the previous generations keep children busy (max children reached). PHP answers 503 until lsphp is back.
ols_restart() {
    docker restart -t 5 "$SBQA_OLS_NAME" > /dev/null; sleep 1
    for _ in $(seq 1 60); do case "$(curl -s -o /dev/null -w '%{http_code}' --max-time 3 "$B/wp-login.php")" in 000|503) sleep 0.5 ;; *) return 0 ;; esac; done; return 1
}
diag() { php "$H/http-gui.php" administrator diagnose > "$E/run/ls-diag.json"; php -r '$j=json_decode(file_get_contents($argv[1]),true); foreach((array)($j["results"]??[]) as $r){ if($r["id"]===$argv[2]){ echo $r["status"]; } }' "$E/run/ls-diag.json" "$1"; }
# rules_step pass|fail: the rules step of the Diagnostica, checked against what the server applies. The probe of the
# block now in .htaccess answers 410 only while OpenLiteSpeed applies that block. The step asks it from PHP with a 10 s
# timeout, and on a busy stack that request to the site itself can time out (warning, cURL error 28), which says
# nothing about the rules: then the step is asked again, up to three times.
rules_step() {
    local st p code
    for _ in 1 2 3; do st=$(diag rewrite-rules); [ "$st" = "warning" ] && grep -q 'cURL error 28' "$E/run/ls-diag.json" || break; done
    p=$(grep -oE 'static-bridge-rules-[a-f0-9]{12}' "$WP/.htaccess" | head -1); code=$(curl -s -o /dev/null -w '%{http_code}' "$B/$p")
    case "$1:$st:$code" in pass:pass:410) return 0 ;; fail:fail:410) ;; fail:fail:*) return 0 ;; esac
    echo "   rules step: $st, probe $p answers HTTP $code" >&2; return 1
}
option() { $MYSQL -N -e "SELECT option_value FROM wp69_options WHERE option_name='$1'" "$SBQA_DB"; }
T=$(mktemp -d); cp "$VH" "$T/vhconf.conf"
trap 'cp "$T/vhconf.conf" "$VH"; rm -rf "$T"' EXIT
# fetch <path> [curl options…]: headers in $T/hl (lower case, no CR), raw body in $T/b.
fetch() { local path=$1; shift; curl -s -D "$T/h" -o "$T/b" "$@" "$B$path"; tr -d '\r' < "$T/h" | tr 'A-Z' 'a-z' > "$T/hl"; }
is_static() { grep -q '^etag: ' "$T/hl" && ! grep -q '^x-powered-by:' "$T/hl"; }
[ "$(curl -s -o /dev/null -w '%{http_code}' --max-time 5 "$B/wp-login.php")" != "000" ] || { echo "Stack not running on $B"; exit 2; }

echo "== Reset the stack and install $(basename "$ZIP") ($(sha256sum "$ZIP" | cut -c1-16)…)"
rm -f "$E/run/fault.json" "$E/run/fault-hits.log" "$E/run/fault-counter-"* "$E/run/textdomain-trace.log"
$MYSQL -e "DROP DATABASE IF EXISTS $SBQA_DB; CREATE DATABASE $SBQA_DB CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_520_ci;"
$MYSQL $SBQA_DB < "$E/backups/00-baseline-before-activation.sql"
rm -rf "$S" "$WP/wp-content/plugins/static-bridge" "$WP/.htaccess"
unzip -q "$ZIP" -d "$WP/wp-content/plugins/"
diff -rq -x LICENSE "$PLUGIN_SRC" "$WP/wp-content/plugins/static-bridge" > /dev/null; ok $? "installed ZIP is byte-identical to the reference source"
: > "$E/logs/php-errors.log"
ols_restart

echo "== Activation (WP permalinks need one restart, as on any site already running)"
R=$($PHP84 "$H/wp69-harness.php" activate); [ "$(echo "$R" | jget result)" = "PASS" ]; ok $? "activation from the CLI without errors"
$PHP84 "$H/wp69-harness.php" users > /dev/null
for r in administrator editor author subscriber; do $PHP84 "$H/wp69-harness.php" session $r > /dev/null; done
ols_restart
php "$H/routing-check.php" expect-wordpress > /dev/null; ok $? "before any release every route is served by WP"

echo "== First release: rules on current/, applied after a restart"
F=$(export_final); [ "$(echo "$F" | jget status)" = "complete" ] && [ "$(echo "$F" | jget saved)" = "8" ]; ok $? "full export publishes 8 routes" "$F"
[ "$(option static_bridge_web_server)" = "openlitespeed" ]; ok $? "the plugin recognised OpenLiteSpeed from LSWS_EDITION and remembered it"
[ "$(ht static_bridge_block)" = "1" ] && grep -q 'wp-content/static-bridge/current/index.html -f' "$WP/.htaccess" && ! grep -q 'wp-content/static-bridge/served/' "$WP/.htaccess" \
    && { [ ! -d "$S/served" ] || [ -z "$(ls -A "$S/served")" ]; }
ok $? "the rules serve current/: no served copy, whose rules would change at every publication"
PROBE=$(grep -oE '^RewriteRule \^static-bridge-rules-[a-f0-9]{12}\$ - \[R=410,L\]$' "$WP/.htaccess" | grep -oE 'static-bridge-rules-[a-f0-9]{12}')
[ -n "$PROBE" ]; ok $? "the block carries the probe rule ($PROBE)"
OUT=$(php "$H/routing-check.php" expect-wordpress); ok $? "before the restart the server keeps its old rules: every route still WP, nothing broken" "$(echo "$OUT" | grep FAIL | head -8)"
grep -qx 'RewriteRule ^ - \[F\]' "$S/.htaccess" && [ "$(curl -s -o /dev/null -w '%{http_code}' "$B/wp-content/static-bridge/releases/$(basename "$(readlink "$S/current")")/manifest.json")" = "403" ]
ok $? "the folder's own .htaccess refuses direct requests at once, without a restart"
[ "$(curl -s -o /dev/null -w '%{http_code}' "$B/$PROBE")" = "404" ]; ok $? "the probe answers 404 before the restart"
rules_step fail && grep -q 'OpenLiteSpeed usa ancora le regole' "$E/run/ls-diag.json"; ok $? "Diagnostica: rules step fails and asks for a restart" "$(php -r '$j=json_decode(file_get_contents($argv[1]),true); foreach((array)($j["results"]??[]) as $r){ if($r["id"]==="rewrite-rules"){ echo json_encode($r, JSON_UNESCAPED_UNICODE); } }' "$E/run/ls-diag.json")"
option static_bridge_activity | grep -q 'OpenLiteSpeed le applica dopo un riavvio'; ok $? "Attività: the rules change is logged with the restart to do"
ols_restart
[ "$(curl -s -o /dev/null -w '%{http_code}' "$B/$PROBE")" = "410" ]; ok $? "after the restart the probe answers 410"
OUT=$(php "$H/routing-check.php" expect-static); ok $? "routing after the restart: $(echo "$OUT" | tail -n 1)" "$(echo "$OUT" | grep FAIL | head -8)"
rules_step pass; ok $? "Diagnostica: rules step passes"
fetch / -H 'Accept-Encoding: gzip, br'; is_static && grep -qE '^content-encoding: (gzip|br)' "$T/hl"; ok $? "OpenLiteSpeed compresses the static home itself ($(grep '^content-encoding' "$T/hl"))"

echo "== Publications without a restart"
HT0=$(sha256sum < "$WP/.htaccess"); RULES_LOGGED=$(option static_bridge_activity | grep -o 'OpenLiteSpeed le applica dopo un riavvio' | wc -l)
MK="SBQA_LS_PUSH_$(date +%s%N)"; $PHP84 "$H/wp69-harness.php" mark 1 "$MK" > /dev/null
curl -s -o /dev/null -H @"$E/run/cookie-editor.txt" "$B/wp-admin/$(push_url editor 1)"
fetch /hello-world/; is_static && grep -q "$MK" "$T/b" && cmp -s "$T/b" "$S/current/hello-world/index.html"; ok $? "a push is public at once, static and byte-identical to the release" "$(tr '\n' ' ' < "$T/hl" | head -c 300) body:$(grep -c "$MK" "$T/b") file:$(grep -c "$MK" "$S/current/hello-world/index.html")"
MK2="SBQA_LS_NEW_$(date +%s%N)"; MKS=$(echo "$MK2" | tr 'A-Z' 'a-z')
NEW=$($PHP84 "$H/wp69-harness.php" archives publish "$MK2"); NEW_ID=$(echo "$NEW" | jget post_id); [ "$(echo "$NEW" | jget result)" = "PASS" ]; ok $? "a new post is published"
cron_until "[ -f '$S/current/$MKS/index.html' ] && grep -q '$MK2' '$S/current/index.html'"; ok $? "the incremental worker writes the post and the home"
fetch "/$MKS/"; is_static && grep -q "$MK2" "$T/b"; ok $? "the new post is served static without a restart"
fetch /; is_static && grep -q "$MK2" "$T/b"; ok $? "the home with the new post is served static without a restart"
F=$(export_final); [ "$(echo "$F" | jget status)" = "complete" ]; ok $? "a second full export completes" "$F"
OUT=$(php "$H/routing-check.php" expect-static | tail -n 1); RC=$(echo "$OUT" | grep -oE '^[0-9]+/[0-9]+'); [ -n "$RC" ] && [ "${RC%/*}" = "${RC#*/}" ]; ok $? "routing still static after it ($OUT)"
[ "$(sha256sum < "$WP/.htaccess")" = "$HT0" ] && [ "$(option static_bridge_activity | grep -o 'OpenLiteSpeed le applica dopo un riavvio' | wc -l)" -le "$RULES_LOGGED" ]
ok $? "publications leave .htaccess unchanged, and ask for no restart in Attività"

echo "== WP-CLI and the system cron name no server: the remembered one is used"
SBQA_NO_SERVER=1 $PHP84 "$H/wp69-harness.php" deactivate > /dev/null; [ ! -e "$S/.enabled" ]; ok $? "deactivation from the CLI removes the routing marker"
php "$H/routing-check.php" expect-wordpress > /dev/null; ok $? "without the marker the loaded rules step aside at once: every route WP"
SBQA_NO_SERVER=1 $PHP84 "$H/wp69-harness.php" reactivate > /dev/null; [ -f "$S/.enabled" ] && [ "$(sha256sum < "$WP/.htaccess")" = "$HT0" ]; ok $? "reactivation from the CLI writes the same rules (current/, probe), not a served copy"
php "$H/routing-check.php" expect-static > /dev/null; ok $? "static again without a restart"

echo "== Strict mode is a marker file: no restart"
$PHP84 "$H/settings-form.php" strict_mode on > /dev/null && [ -f "$S/.strict" ]; ok $? "Strict on through the settings form"
fetch /route-that-does-not-exist-qa/; grep -q '^http/1.1 404' "$T/hl" && ! grep -q '^x-powered-by:' "$T/hl" && [ "$(sha256sum < "$WP/.htaccess")" = "$HT0" ]; ok $? "unknown route: 404 from the server, no PHP, rules unchanged"
$PHP84 "$H/settings-form.php" strict_mode off > /dev/null && [ ! -e "$S/.strict" ]; ok $? "Strict off"

echo "== Headers: OpenLiteSpeed ignores Header in .htaccess; the context from the settings adds them"
SBQA_FORM_EXTRA='cloudflare_preset=1' $PHP84 "$H/settings-form.php" strict_mode off > /dev/null; ok $? "Cloudflare preset saved (Cache-Control public, max-age=0, s-maxage=600)"
grep -q 'Header always set Cache-Control "public, max-age=0, s-maxage=600" env=REDIRECT_STATIC_BRIDGE_ROUTE' "$WP/.htaccess"; ok $? "the rules ask for the header, as on Apache"
rules_step fail; ok $? "the rules changed: the Diagnostica asks for a restart again"
ols_restart
fetch /; is_static && ! grep -q '^cache-control:' "$T/hl"; ok $? "after the restart the static home is still served, without the header (Header ignored)"
curl -s -H @"$E/run/cookie-administrator.txt" "$B/wp-admin/admin.php?page=static-bridge&tab=settings" > "$T/settings.html"
$PHP84 -r '$d=new DOMDocument(); @$d->loadHTML(file_get_contents($argv[1])); foreach($d->getElementsByTagName("textarea") as $t){ if(false!==strpos($t->textContent,"context /wp-content/static-bridge/")){ echo $t->textContent; } }' "$T/settings.html" > "$T/context.conf"
grep -q 'Cache-Control: public, max-age=0, s-maxage=600' "$T/context.conf" && grep -q '^context /wp-content/static-bridge/ {' "$T/context.conf"; ok $? "Impostazioni → OpenLiteSpeed shows the context with the Cache-Control" "$(head -c 300 "$T/context.conf")"
grep -q 'sb-precompressed-pages' "$T/settings.html" && $PHP84 -r '$d=new DOMDocument(); @$d->loadHTML(file_get_contents($argv[1])); $i=$d->getElementById("sb-precompressed-pages"); exit($i && $i->hasAttribute("disabled") ? 0 : 1);' "$T/settings.html"; ok $? "the precompressed pages switch is disabled on LiteSpeed"
{ echo; cat "$T/context.conf"; echo; } >> "$VH"; ols_restart
fetch /; is_static && grep -q '^cache-control: public, max-age=0, s-maxage=600' "$T/hl"; ok $? "with the context the static home carries the Cache-Control"
fetch /wp-login.php; ! grep -q 's-maxage=600' "$T/hl"; ok $? "WP pages keep their own headers"
OUT=$(php "$H/routing-check.php" expect-static); ok $? "routing with the context (private paths still refused): $(echo "$OUT" | tail -n 1)" "$(echo "$OUT" | grep FAIL | head -8)"
rules_step pass; ok $? "Diagnostica: rules step passes after the restart"

echo "== Redirects and language domains of another plugin (2.2.15): server rules, after a restart"
MU=$WP/wp-content/mu-plugins/sbqa-litespeed-redirects.php
cat > "$MU" <<'PHP'
<?php
// QA (litespeed-e2e.sh): a redirect and a language domain given as Lingua Bridge gives them.
add_filter('static_bridge_redirects', static function ($redirects) { $redirects['/sbqa-vecchio'] = array('to' => '/hello-world/', 'status' => 301); return $redirects; });
add_filter('static_bridge_hosts', static function ($hosts) { $hosts[] = 'fr.sbqa.test'; return $hosts; });
PHP
$PHP84 "$H/settings-form.php" strict_mode off > /dev/null
grep -qxF 'RewriteRule ^sbqa\-vecchio/?$ /hello-world/ [R=301,NE,END]' "$WP/.htaccess" && grep -qF 'RewriteCond %{HTTP_HOST} !^(?:fr\.sbqa\.test)(?::[0-9]+)?$ [NC]' "$WP/.htaccess"
ok $? "the rules carry the redirect and the language domain"
rules_step fail; ok $? "new rules: the Diagnostica asks for a restart"
ols_restart
fetch /sbqa-vecchio/; grep -q '^http/1.1 301' "$T/hl" && grep -qE '^location: (http://127\.0\.0\.1:[0-9]+)?/hello-world/$' "$T/hl" && ! grep -q '^x-powered-by:' "$T/hl"
ok $? "after the restart the redirect is a 301 from the server, without PHP" "$(tr '\n' ' ' < "$T/hl" | head -c 300)"
fetch / -H 'Host: fr.sbqa.test'; ! is_static; ok $? "the language domain never gets the main site's copy" "$(tr '\n' ' ' < "$T/hl" | head -c 300)"
fetch /; is_static; ok $? "the main domain still gets the static home"
rules_step pass; ok $? "Diagnostica: rules step passes again"
rm -f "$MU"

echo "== Precompressed pages: the server compresses, the plugin writes no .gz"
$PHP84 "$H/settings-form.php" precompressed_pages on > /dev/null
F=$(export_final); [ "$(echo "$F" | jget status)" = "complete" ]; ok $? "full export with the option asked for" "$F"
[ -z "$(find "$S/current/" -name '*.gz' | head -1)" ] && ! grep -q 'index.html.gz' "$WP/.htaccess"; ok $? "no .gz in the release and no .gz rule"
fetch / --compressed; is_static && cmp -s "$T/b" "$S/current/index.html"; ok $? "a client that accepts compression gets the current home"

echo "== Server tab"
curl -s -H @"$E/run/cookie-administrator.txt" "$B/wp-admin/admin.php?page=static-bridge&tab=server" > "$T/server.html"
grep -q 'OpenLiteSpeed comprime da solo HTML, CSS e JavaScript' "$T/server.html" && ! grep -q 'Attiva compressione e cache dei file' "$T/server.html"; ok $? "no .htaccess compression block offered on OpenLiteSpeed"

NE=$(grep -cE 'wp-content/plugins/static-bridge/|<code>static-bridge</code>' "$E/logs/php-errors.log"); [ "$NE" = "0" ]; ok $? "no PHP notice/warning/error from Static Bridge ($NE lines)"
cp "$T/vhconf.conf" "$VH"; ols_restart
echo
echo "LITESPEED RESULT: $PASS passed, $FAIL failed — $(basename "$ZIP") on WordPress $SBQA_WP / $($HTTPD -v | head -1 | cut -d' ' -f3)"
[ "$FAIL" = "0" ]
