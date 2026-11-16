#!/usr/bin/env bash
# End-to-end Static Bridge QA on a disposable stack built by setup-stack.sh: by default $SBQA_HOME/wp69
# (WordPress 6.9, PHP-FPM 8.4.18, Nginx 1.26.1, private MySQL 8.4 socket, loopback only);
# with SBQA_STACK=apache the Apache 2.4.43 variant ($SBQA_HOME/wp69a), where the plugin's own
# .htaccess rules do the routing; SBQA_STACK=nginx71 is WordPress 7.1.3 on Nginx (see profile.sh).
#
# Resets ONLY the selected stack to its clean DB baseline, installs the given ZIP and runs
# every scenario. Requires the stack services to be running (stack.sh start).
# Usage: [SBQA_STACK=apache|nginx71] bash run-e2e.sh <path-to-static-bridge-x.y.z.zip> [--skip-kill]
set -u
H=$(cd "$(dirname "$0")" && pwd)
. "$H/profile.sh"
sbqa_require_stack; E=$SBQA_E
PROJECT=$SBQA_REPO
# Reference source the ZIP must match (override to compare an older build, e.g. a snapshot of 2.0.11).
PLUGIN_SRC=${SBQA_PLUGIN_SOURCE:-$PROJECT/plugin}
ZIP=${1:-}
SKIP_KILL=${2:-}
PHP84="env LD_LIBRARY_PATH=$P/shared-libs $P/bin/php -n -c $E/conf/php.ini"
MYSQL="env LD_LIBRARY_PATH=$M/lib $M/bin/mysql --no-defaults -uroot --socket=$E/run/mysql.sock"
WP=$E/wordpress
S=$WP/wp-content/static-bridge
PASS=0; FAIL=0
ok() { if [ "$1" = "0" ]; then PASS=$((PASS+1)); echo "[PASS] $2"; else FAIL=$((FAIL+1)); echo "[FAIL] $2${3:+ :: $3}"; fi; }
jget() { php -r '$j=json_decode(stream_get_contents(STDIN),true); $v=$j; foreach(explode(".",$argv[1]) as $k){ $v=is_array($v)&&array_key_exists($k,$v)?$v[$k]:null; } echo is_scalar($v)?$v:json_encode($v);' "$1"; }
push_url() { curl -s -H @"$E/run/cookie-$1.txt" "$B/wp-admin/edit.php" | grep -oE "admin-post\.php\?action=static_bridge_push_post&(amp;|#038;)post_id=$2&(amp;|#038;)_wpnonce=[a-f0-9]+" | head -1 | sed 's/&amp;/\&/g; s/&#038;/\&/g'; }
export_final() { php "$H/http-gui.php" administrator export | jget final; }

case "$ZIP" in /*) ;; *) ZIP="$PWD/$ZIP" ;; esac
[ -f "$ZIP" ] || { echo "Usage: bash run-e2e.sh <zip> [--skip-kill]"; exit 2; }
[ "$(curl -s -o /dev/null -w "%{http_code}" --max-time 5 $B/wp-login.php)" != "000" ] || { echo "Stack not running on $B"; exit 2; }
case "$S" in "$SBQA_HOME/$SBQA_ENV_NAME/wordpress/wp-content/static-bridge") ;; *) echo "REFUSED: unexpected static root $S"; exit 2 ;; esac

echo "== Reset disposable stack to clean baseline and install $(basename "$ZIP") ($(sha256sum "$ZIP" | cut -c1-16)…)"
rm -f "$E/run/fault.json" "$E/run/fault-hits.log" "$E/run/fault-counter-"* "$E/run/textdomain-trace.log"
$MYSQL -e "DROP DATABASE IF EXISTS $SBQA_DB; CREATE DATABASE $SBQA_DB CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_520_ci;"
$MYSQL $SBQA_DB < "$E/backups/00-baseline-before-activation.sql"
rm -rf "$S" "$WP/wp-content/plugins/static-bridge"
# Apache: no .htaccess until WordPress writes its own block (permalinks saved during activation).
[ "$SBQA_WEB" = "apache" ] && rm -f "$WP/.htaccess"
unzip -q "$ZIP" -d "$WP/wp-content/plugins/"
diff -rq -x LICENSE "$PLUGIN_SRC" "$WP/wp-content/plugins/static-bridge" > /dev/null; ok $? "installed ZIP is byte-identical to the reference source ($(basename "$(dirname "$PLUGIN_SRC")")/$(basename "$PLUGIN_SRC"))"
if [ "$SBQA_WEB" = "nginx" ]; then
    echo 'location / { try_files $uri $uri/ /index.php$is_args$args; }' > "$E/conf/static-bridge.conf"
    $NGX -s reload -p "$E/run" -c "$E/conf/nginx.conf"; sleep 0.5
fi
# Header proving a static response (see profile.php: sbqa_route).
STATIC_HDR="x-static-bridge-route: static"; [ "$SBQA_WEB" = "apache" ] && STATIC_HDR="x-sbqa-apache-file: static-bridge"
# OpenLiteSpeed: a file it serves has an ETag, a WordPress page none (see profile.php).
[ "$SBQA_LSWS" = "1" ] && STATIC_HDR="^etag: "
# .htaccess state as JSON from the harness (Apache only).
ht() { $PHP84 "$H/wp69-harness.php" htaccess | jget "$1"; }
: > "$E/logs/php-errors.log"

echo "== Gate B: activation and permissions (WordPress 6.9 / PHP 8.4.18)"
R=$($PHP84 "$H/wp69-harness.php" activate); [ "$(echo "$R" | jget result)" = "PASS" ] && [ "$(echo "$R" | jget php_notices_warnings)" = "[]" ]; ok $? "activation without errors; routing marker absent before first release"
if [ "$SBQA_WEB" = "nginx" ]; then
    $NGX -t -p "$E/run" -c "$E/conf/nginx.conf" > /dev/null 2>&1 && $NGX -s reload -p "$E/run" -c "$E/conf/nginx.conf"; ok $? "generated Nginx block accepted by nginx -t and loaded"; sleep 0.5
else
    $HTTPD -t -f "$E/conf/httpd.conf" > /dev/null 2>&1 && [ "$(ht wordpress_block)" = "1" ] && [ "$(ht static_bridge_block)" = "" ] && [ "$(ht archive_protection_block)" = "" ]
    ok $? "Apache: httpd -t OK; WordPress wrote its .htaccess block; no Static Bridge rules before the first release"
fi
$PHP84 "$H/wp69-harness.php" users > /dev/null
for r in administrator editor author subscriber; do $PHP84 "$H/wp69-harness.php" session $r > /dev/null; done
php "$H/routing-check.php" expect-wordpress > /dev/null; ok $? "before any release every route is served by WordPress"

echo "== Gate C/D: first export and real HTTP routing"
# 8 routes: home, the post, the sample page, its category and author, and (2.2.0) its year, month and day archives.
F=$(export_final); [ "$(echo "$F" | jget status)" = "complete" ] && [ "$(echo "$F" | jget saved)" = "8" ]; ok $? "full export over GUI endpoints publishes 8 routes" "$F"
if [ "$SBQA_WEB" = "apache" ]; then
    [ "$(ht static_bridge_block)" = "1" ] && [ "$(ht archive_protection_block)" = "1" ] && [ "$(ht static_bridge_before_wordpress)" = "1" ] && [ "$(ht wordpress_block)" = "1" ] && [ -f "$S/.enabled" ]
    ok $? "Apache: first release writes Static Bridge + archive protection blocks before '# BEGIN WordPress' and arms .enabled"
fi
OUT=$(php "$H/routing-check.php" expect-static); ok $? "routing: $(echo "$OUT" | tail -1)" "$(echo "$OUT" | grep FAIL | head -8)"

echo "== Gate B: capability and nonce refusals over HTTP"
GOOD=$(push_url administrator 1)
[ "$(curl -s -o /dev/null -w '%{http_code}' -H @"$E/run/cookie-subscriber.txt" "$B/wp-admin/$GOOD")" = "403" ]; ok $? "subscriber cannot reuse a push URL (403)"
[ "$(curl -s -o /dev/null -w '%{http_code}' -H @"$E/run/cookie-author.txt" "$B/wp-admin/admin-post.php?action=static_bridge_push_post&post_id=2&_wpnonce=deadbeef00")" = "403" ]; ok $? "author cannot push another user's page (403)"
[ "$(curl -s -o /dev/null -w '%{http_code}' -H @"$E/run/cookie-administrator.txt" "$B/wp-admin/admin-post.php?action=static_bridge_push_post&post_id=1&_wpnonce=deadbeef00")" = "403" ]; ok $? "push with invalid nonce refused (403)"
[ "$(php "$H/http-gui.php" editor call static_bridge_start | jget http)" = "403" ]; ok $? "editor cannot start a full export (403)"
[ "$(php "$H/http-gui.php" administrator call static_bridge_start nonce=bad-nonce | jget http)" = "403" ]; ok $? "admin AJAX with invalid nonce refused (403)"
[ "$(curl -s -o /dev/null -w '%{http_code}' -H @"$E/run/cookie-editor.txt" "$B/wp-admin/admin.php?page=static-bridge")" = "403" ]; ok $? "editor cannot open the Static Bridge admin page"

echo "== Gate C: single-route push parity"
MK="SBQA_PUSH_$(date +%s%N)"; $PHP84 "$H/wp69-harness.php" mark 1 "$MK" > /dev/null
[ "$(curl -s $B/hello-world/ | grep -c "$MK")" = "0" ]; ok $? "edited content is not public before push (static copy still served)"
curl -s -o /dev/null -H @"$E/run/cookie-editor.txt" "$B/wp-admin/$(push_url editor 1)"
BODY=$(mktemp); curl -s -D "$BODY.h" -o "$BODY" $B/hello-world/
grep -q "$MK" "$BODY" && grep -qi "$STATIC_HDR" "$BODY.h" && cmp -s "$BODY" "$S/current/hello-world/index.html"; ok $? "editor push: public page static, updated and byte-identical to the release"; rm -f "$BODY" "$BODY.h"
for k in 1 2; do
    OUT=$(bash "$H/repro-push-after-swap.sh" | head -1)
    [ "$OUT" = "push attempt 1: public_has_new_content=1 written_into_non_active_release=0" ]; ok $? "push immediately after another worker published a release lands on the active release (run $k)" "$OUT"
done

echo "== Gate C: per-route faults during export"
fault() { echo "$1" > "$E/run/fault.json"; rm -f "$E/run/fault-counter-"*; F=$(export_final); rm -f "$E/run/fault.json"; }
OLD=$(sha256sum < "$S/current/sample-page/index.html"); MK2="SBQA_SP_$(date +%s%N)"; MK3="SBQA_HW_$(date +%s%N)"
$PHP84 "$H/wp69-harness.php" mark 2 "$MK2" > /dev/null; $PHP84 "$H/wp69-harness.php" mark 1 "$MK3" > /dev/null
fault '{"/sample-page/": {"mode": "stale"}}'
[ "$(echo "$F" | jget status)" = "complete" ] && [ "$(echo "$F" | jget preserved)" = "1" ] && [ "$(sha256sum < "$S/current/sample-page/index.html")" = "$OLD" ] && ! grep -q SBQA_STALE_CACHE_BODY "$S/current/sample-page/index.html" && grep -q "$MK3" "$S/current/hello-world/index.html"
ok $? "stale cache without render proof: route skipped, previous copy kept, others updated, release published" "$F"
MK4="SBQA_T_$(date +%s%N)"; $PHP84 "$H/wp69-harness.php" mark 1 "$MK4" > /dev/null
fault '{"/hello-world/": {"mode": "500-then-ok", "count": 2}}'
[ "$(echo "$F" | jget skipped)" = "0" ] && grep -q "$MK4" "$S/current/hello-world/index.html"; ok $? "two transient 500s recovered within retries" "$F $($PHP84 "$H/wp69-harness.php" inspect | jget job.issues | head -c 800)"
OLDH=$(sha256sum < "$S/current/hello-world/index.html")
fault '{"/hello-world/": {"mode": "500"}}'
[ "$(echo "$F" | jget status)" = "complete" ] && [ "$(echo "$F" | jget preserved)" = "1" ] && [ "$(sha256sum < "$S/current/hello-world/index.html")" = "$OLDH" ]; ok $? "persistent 500: single route error, previous copy kept, export completes" "$F"
fault '{"/sample-page/": {"mode": "404"}}'
[ "$(echo "$F" | jget status)" = "complete" ] && [ "$(echo "$F" | jget message)" = "Versione pubblicata." ] && [ ! -e "$S/current/sample-page/index.html" ]; ok $? "404 is an expected skip (no route error) and the route leaves the release" "$F"
F=$(export_final); [ "$(echo "$F" | jget saved)" = "8" ]; ok $? "clean export restores all 8 routes"

echo "== Gate C/F: fatal storage failures keep the active release"
BEFORE=$(readlink "$S/current")
chmod 555 "$S/releases"; R=$(php "$H/http-gui.php" administrator export); chmod 755 "$S/releases"
[ "$(echo "$R" | jget kick.0.http)" = "500" ] && [ "$(readlink "$S/current")" = "$BEFORE" ]; ok $? "unwritable releases directory: start refused with explicit error, nothing published"
ls "$S/releases" > "$E/run/rel-before"; echo '{"/hello-world/": {"mode": "slow", "seconds": 4}}' > "$E/run/fault.json"
php "$H/http-gui.php" administrator export > "$E/run/midfail.json" & G=$!
NEW=""; for i in $(seq 1 50); do NEW=$(ls "$S/releases" | grep -vxFf "$E/run/rel-before" | head -1); [ -n "$NEW" ] && break; sleep 0.1; done
[ -n "$NEW" ] && chmod -R a-w "$S/releases/$NEW"; wait $G; [ -n "$NEW" ] && chmod -R u+w "$S/releases/$NEW"; rm -f "$E/run/fault.json" "$E/run/rel-before"
[ "$(jget final.status < "$E/run/midfail.json")" = "failed" ] && [ "$(readlink "$S/current")" = "$BEFORE" ]; ok $? "staging becomes unwritable mid-export: job failed (not masked as skip), active release untouched"; rm -f "$E/run/midfail.json"
OUT=$(php "$H/routing-check.php" expect-static | tail -1); RC=$(echo "$OUT" | grep -oE '^[0-9]+/[0-9]+'); [ -n "$RC" ] && [ "${RC%/*}" = "${RC#*/}" ]; ok $? "site still served statically after failures ($OUT)"

echo "== Gate D: Strict mode through the real settings form"
$PHP84 "$H/settings-form.php" strict_mode on > /dev/null && [ -f "$S/.strict" ]; ok $? "Strict enabled via settings form creates the marker"
A=$(php "$H/http-gui.php" anonymous get /route-that-does-not-exist-qa/); L=$(php "$H/http-gui.php" anonymous get /wp-login.php)
if [ "$SBQA_WEB" = "apache" ]; then
    # .htaccess answers R=404 itself: Apache's own error page, PHP never runs.
    [ "$(echo "$A" | jget status)" = "404" ] && [ "$(echo "$A" | jget php)" = "" ] && [ "$(echo "$A" | jget wordpress_body)" = "" ] && [ "$(echo "$L" | jget status)" = "200" ] && [ "$(echo "$L" | jget route)" = "" ]
    ok $? "Strict: unknown public route is a 404 from Apache (no PHP), backend stays dynamic"
else
    [ "$(echo "$A" | jget status)" = "404" ] && [ "$(echo "$A" | jget route)" = "static" ] && [ "$(echo "$L" | jget status)" = "200" ] && [ "$(echo "$L" | jget route)" = "" ]; ok $? "Strict: unknown public route is a static 404, backend stays dynamic"
fi
$PHP84 "$H/settings-form.php" strict_mode off > /dev/null && [ ! -e "$S/.strict" ]; ok $? "Strict disabled via settings form removes the marker"

echo "== Gate F: deactivate / reactivate through the Plugins screen"
plugin_link() { curl -s -H @"$E/run/cookie-administrator.txt" "$B/wp-admin/plugins.php" | grep -oE "plugins\.php\?action=$1&(amp;|#038;)plugin=static-bridge%2Fstatic-bridge\.php[^\"']*" | head -1 | sed 's/&amp;/\&/g; s/&#038;/\&/g'; }
REL=$(readlink "$S/current"); MH=$(sha256sum < "$S/current/manifest.json")
curl -s -o /dev/null -H @"$E/run/cookie-administrator.txt" "$B/wp-admin/$(plugin_link deactivate)"
[ ! -e "$S/.enabled" ] && [ "$(readlink "$S/current")" = "$REL" ] && [ "$(sha256sum < "$S/current/manifest.json")" = "$MH" ]; ok $? "deactivation removes routing marker and keeps the release untouched"
if [ "$SBQA_WEB" = "apache" ]; then
    [ "$(ht static_bridge_block)" = "" ] && [ "$(ht archive_protection_block)" = "1" ] && [ "$(ht wordpress_block)" = "1" ]
    ok $? "Apache: deactivation removes the Static Bridge .htaccess block, keeps archive protection and the WordPress block"
fi
php "$H/routing-check.php" expect-wordpress > /dev/null; ok $? "after deactivation every route returns to WordPress"
curl -s -o /dev/null -H @"$E/run/cookie-administrator.txt" "$B/wp-admin/$(plugin_link activate)"
[ -f "$S/.enabled" ] && php "$H/routing-check.php" expect-static > /dev/null; ok $? "reactivation restores static routing on the same release"
if [ "$SBQA_WEB" = "apache" ]; then
    [ "$(ht static_bridge_block)" = "1" ] && [ "$(ht static_bridge_before_wordpress)" = "1" ] && [ "$(grep -c '^# BEGIN Static Bridge$' "$WP/.htaccess")" = "1" ] && [ "$(grep -c '^# BEGIN Static Bridge Archive Protection$' "$WP/.htaccess")" = "1" ]
    ok $? "Apache: reactivation rewrites exactly one Static Bridge block (and one archive protection block) before WordPress"
fi

echo "== Gate F: concurrent readers during publication"
$PHP84 "$H/concurrent-readers.php" 8 > "$E/run/readers.json" & RD=$!
for k in 1 2 3; do sleep 0.4; $PHP84 "$H/wp69-harness.php" mark 1 "SBQA_C${k}_$(date +%s%N)" > /dev/null; export_final > /dev/null; done
wait $RD; ok $? "anonymous readers during 3 publications: $(cat "$E/run/readers.json")"; rm -f "$E/run/readers.json"

echo "== Gate F: automatic incremental update (REST save + system cron)"
NONCE=$(curl -s -H @"$E/run/cookie-editor.txt" "$B/wp-admin/admin-ajax.php?action=rest-nonce")
MK5="SBQA_REST_$(date +%s%N)"
RAW=$(curl -s -H @"$E/run/cookie-editor.txt" -H "X-WP-Nonce: $NONCE" "$B/wp-json/wp/v2/posts/1?context=edit" | jget content.raw)
php -r 'echo json_encode(["content"=>$argv[1]."\n<!-- wp:paragraph -->\n<p>".$argv[2]."</p>\n<!-- /wp:paragraph -->"]);' "$RAW" "$MK5" > "$E/run/rest.json"
[ "$(curl -s -o /dev/null -w '%{http_code}' -X POST -H @"$E/run/cookie-editor.txt" -H "X-WP-Nonce: $NONCE" -H 'Content-Type: application/json' --data @"$E/run/rest.json" "$B/wp-json/wp/v2/posts/1")" = "200" ]; ok $? "editor saves the post through the REST API"; rm -f "$E/run/rest.json"
[ "$(curl -s $B/hello-world/ | grep -c "$MK5")" = "0" ]; ok $? "static copy unchanged until the incremental worker runs"
U=1; for i in $(seq 1 18); do curl -s -o /dev/null "$B/wp-cron.php"; sleep 5; [ "$(curl -s $B/hello-world/ | grep -c "$MK5")" = "1" ] && { U=0; break; }; done
ok $U "wp-cron.php (system-cron style) publishes the post and its archives incrementally"
[ "$(curl -s $B/category/uncategorized/ | grep -c "$MK5")" = "1" ]; ok $? "related category archive refreshed too"

if [ "$SKIP_KILL" != "--skip-kill" ]; then
    echo "== Gate F: all PHP-FPM workers SIGKILLed mid-export (≈95 s)"
    BEFORE=$(readlink "$S/current"); echo '{"/hello-world/": {"mode": "slow", "seconds": 30}}' > "$E/run/fault.json"; rm -f "$E/run/fault-hits.log"
    php "$H/http-gui.php" administrator export > "$E/run/kill.json" & G=$!
    for i in $(seq 1 50); do grep -q slow "$E/run/fault-hits.log" 2>/dev/null && break; sleep 0.2; done; sleep 0.5
    pkill -9 -P "$(cat "$E/run/php-fpm.pid")"; rm -f "$E/run/fault.json"; wait $G
    [ "$(jget final.status < "$E/run/kill.json")" = "complete" ] && [ "$(readlink "$S/current")" != "$BEFORE" ] && [ -e "$S/current/.ready" ]; ok $? "worker kill: lock expires, job resumes and publishes a complete release"; rm -f "$E/run/kill.json"
    php "$H/routing-check.php" expect-static > /dev/null; ok $? "routing intact after worker kill"
fi

echo "== Logs"
# Match the installed plugin path or its text domain (the project folder is also named static-bridge).
N=$(grep -cE 'wp-content/plugins/static-bridge/|<code>static-bridge</code>' "$E/logs/php-errors.log"); [ "$N" = "0" ]; ok $? "no PHP notice/warning/error from the plugin in php-errors.log ($N lines; WordPress.org update-check warnings are expected offline)"
[ ! -s "$E/run/textdomain-trace.log" ]; ok $? "no early textdomain loading (WordPress 6.7+ notice)"

echo
if [ "$SBQA_WEB" = "apache" ]; then WEBV=$($HTTPD -v | head -1 | cut -d' ' -f3); else WEBV=$($NGX -v 2>&1 | cut -d' ' -f3); fi
echo "E2E RESULT: $PASS passed, $FAIL failed — $(basename "$ZIP") on WordPress $(grep "wp_version =" "$WP/wp-includes/version.php" | grep -oE "[0-9.]+") / PHP $($PHP84 -r 'echo PHP_VERSION;') FPM / $WEBV / MySQL 8.4.0"
exit $([ "$FAIL" = "0" ] && echo 0 || echo 1)
