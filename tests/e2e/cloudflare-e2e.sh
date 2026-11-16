#!/usr/bin/env bash
# Cloudflare scenarios (Static Bridge 2.0.17) on the disposable stack selected by SBQA_STACK.
# Run AFTER run-e2e.sh on the same ZIP (it reuses its published release and QA sessions).
# A local fake Cloudflare edge (origin port + 13) and fake purge API (origin port + 14), both
# `php -S` with harness/cf-fake.php, stand in for Cloudflare; the QA mu-plugin
# sbqa-cloudflare-mu.php defines the QA credentials and diverts api.cloudflare.com to the fake
# (fails closed otherwise). For the duration of the run the site address (home/siteurl) points at
# the fake edge, as on a proxied domain; it is restored at the end. Nothing leaves 127.0.0.1.
# Usage: [SBQA_STACK=apache] bash cloudflare-e2e.sh
set -u
H=$(cd "$(dirname "$0")" && pwd)
. "$H/profile.sh"
sbqa_require_stack; E=$SBQA_E
PHP84="env LD_LIBRARY_PATH=$P/shared-libs $P/bin/php -n -c $E/conf/php.ini"
WP=$E/wordpress
S=$WP/wp-content/static-bridge
CF=$E/run/cf
EP=$((SBQA_PORT + 13)); AP=$((SBQA_PORT + 14)); EB=http://127.0.0.1:$EP
PASS=0; FAIL=0
ok() { if [ "$1" = "0" ]; then PASS=$((PASS+1)); echo "[PASS] $2"; else FAIL=$((FAIL+1)); echo "[FAIL] $2${3:+ :: $3}"; fi; }
jget() { php -r '$j=json_decode(stream_get_contents(STDIN),true); $v=$j; foreach(explode(".",$argv[1]) as $k){ $v=is_array($v)&&array_key_exists($k,$v)?$v[$k]:null; } echo is_scalar($v)?(is_bool($v)?($v?"true":"false"):$v):json_encode($v);' "$1"; }
cfqa() { $PHP84 "$H/cloudflare-qa.php" "$@"; }
export_final() { php "$H/http-gui.php" administrator export | jget final; }
push_url() { curl -s -H @"$E/run/cookie-$1.txt" "$B/wp-admin/edit.php" | grep -oE "admin-post\.php\?action=static_bridge_push_post&(amp;|#038;)post_id=$2&(amp;|#038;)_wpnonce=[a-f0-9]+" | head -1 | sed 's/&amp;/\&/g; s/&#038;/\&/g'; }
push() { curl -s -o /dev/null -H @"$E/run/cookie-administrator.txt" "$B/wp-admin/$(push_url administrator "$1")"; }
edge_mode() { printf '{"challenge":%s,"rewrite":%s,"cache":%s}' "$1" "$2" "$3" > "$CF/edge-mode.json"; }
reload_web() { if [ "$SBQA_WEB" = "nginx" ]; then "$NGX" -t -p "$E/run" -c "$E/conf/nginx.conf" > /dev/null 2>&1 && "$NGX" -s reload -p "$E/run" -c "$E/conf/nginx.conf"; sleep 0.7; fi; }
hdr() { curl -s -D - -o /dev/null "${@:2}" "$1" | tr -d '\r' ; }
edge_markup_files() { grep -rlE 'rocket-loader\.min\.js|__cf_email__|/cdn-cgi/' "$S/current/" --include=index.html 2>/dev/null | wc -l; }
api_count() { grep -c "$1" "$CF/api.log" 2>/dev/null || true; }
# Every published route answered 200 by the origin with exactly the release file.
routes_static_on_origin() {
    for r in "/:index.html" "/hello-world/:hello-world/index.html" "/sample-page/:sample-page/index.html"; do
        [ -f "$S/current/${r#*:}" ] || return 1
        [ "$(curl -s -o "$CF/route.body" -w '%{http_code}' "$B${r%%:*}")" = "200" ] || return 1
        cmp -s "$CF/route.body" "$S/current/${r#*:}" || return 1
    done
}

case "$S" in "$SBQA_HOME/$SBQA_ENV_NAME/wordpress/wp-content/static-bridge") ;; *) echo "REFUSED: unexpected static root $S"; exit 2 ;; esac
[ -L "$S/current" ] || { echo "Run run-e2e.sh first: no published release"; exit 2; }
[ -f "$E/run/cookie-administrator.txt" ] || { echo "Run run-e2e.sh first: no QA session"; exit 2; }

# ---- set-up ----------------------------------------------------------------------------
rm -rf "$CF"; mkdir -p "$CF/cache"; chmod 700 "$CF"
ZONE=$(head -c 16 /dev/urandom | od -An -tx1 | tr -d ' \n')
TOKEN="sbqa_$(head -c 24 /dev/urandom | od -An -tx1 | tr -d ' \n')"
printf '{"zone":"%s","token":"%s"}' "$ZONE" "$TOKEN" > "$CF/credentials.json"; chmod 600 "$CF/credentials.json"
echo "$AP" > "$CF/api-port"
edge_mode false false false
PIDS=""
for role in edge api; do
    PORT=$EP; [ "$role" = "api" ] && PORT=$AP
    SBQA_CF_STATE=$CF SBQA_CF_ROLE=$role SBQA_CF_ORIGIN=$B PHP_CLI_SERVER_WORKERS=8 setsid php -S 127.0.0.1:$PORT "$H/cf-fake.php" > "$CF/$role-server.log" 2>&1 < /dev/null &
    PIDS="$PIDS $!"
done
cp "$H/mu-plugins/sbqa-cloudflare-mu.php" "$WP/wp-content/mu-plugins/sbqa-cloudflare-mu.php"
cleanup() {
    # Leave the stack as run-e2e.sh left it: Strict off (section setup turns it on).
    cfqa settings render_origin= static_cache_control= cloudflare_purge=0 strict_mode=0 > /dev/null 2>&1
    cfqa home origin > /dev/null 2>&1
    reload_web
    rm -f "$WP/wp-content/mu-plugins/sbqa-cloudflare-mu.php"
    for p in $PIDS; do kill "$p" 2>/dev/null; done
}
trap cleanup EXIT
for i in $(seq 1 40); do curl -s -o /dev/null --max-time 1 "$EB/" && break; sleep 0.25; done
cfqa settings render_origin= static_cache_control= cloudflare_purge=0 strict_mode=1 > /dev/null; reload_web
cfqa home edge > /dev/null; [ "$(cfqa state | jget home)" = "$EB" ]; ok $? "(QA) site address points at the fake Cloudflare edge $EB"
H1=$(hdr "$EB/hello-world/"); echo "$H1" | grep -qi '^cf-ray: ' && echo "$H1" | grep -q ' 200'; ok $? "fake edge proxies the static route and adds cf-ray"

echo "== A: without an origin address the render goes through Cloudflare"
edge_mode false true false
: > "$CF/edge.log"
F=$(export_final); [ "$(echo "$F" | jget status)" = "complete" ]; ok $? "full export completes through the edge" "$F"
RENDERS=$(grep -c '"render":true' "$CF/edge.log"); [ "$RENDERS" -gt 0 ]; ok $? "render requests crossed the edge ($RENDERS)"
N=$(edge_markup_files); [ "$N" -gt 0 ]; ok $? "problem reproduced: Rocket Loader / Email Obfuscation markup stored in $N release files"
D=$(cfqa diagnostic cloudflare-markup cloudflare-edge)
[ "$(echo "$D" | jget steps.cloudflare-markup.status)" = "warning" ]; ok $? "Diagnostica flags edge markup in the stored release" "$(echo "$D" | jget steps.cloudflare-markup.summary)"
[ "$(echo "$D" | jget steps.cloudflare-edge.status)" = "warning" ] && echo "$D" | jget steps.cloudflare-edge.summary | grep -q 'cf-ray='; ok $? "Diagnostica detects Cloudflare (cf-ray) and recommends an origin address" "$(echo "$D" | jget steps.cloudflare-edge.summary)"

echo "== B: a Cloudflare challenge without an origin address"
edge_mode true true false
BEFORE=$(sha256sum "$S/current/hello-world/index.html" | cut -c1-64)
MKB="SBQA_CFB_$(date +%s%N)"; cfqa mark 1 "$MKB" > /dev/null
push 1
[ "$(sha256sum "$S/current/hello-world/index.html" | cut -c1-64)" = "$BEFORE" ] && ! grep -q "$MKB" "$S/current/hello-world/index.html"; ok $? "challenged push leaves the published route untouched"
cfqa state | jget activity | grep -q 'cf-mitigated'; ok $? "challenged push is logged with the cf-mitigated cause"
F=$(export_final); ST=$(echo "$F" | jget status)
routes_static_on_origin; ok $? "challenged full export ($ST) drops no route: every route still served from the release" "$F"
grep -q "$MKB" "$S/current/hello-world/index.html"; [ $? -ne 0 ]; ok $? "challenged export did not publish the challenge page or unverified content"
D=$(cfqa diagnostic cloudflare-edge); [ "$(echo "$D" | jget steps.cloudflare-edge.status)" = "fail" ] && echo "$D" | jget steps.cloudflare-edge.summary | grep -q 'cf-mitigated'; ok $? "Diagnostica reports the challenge as a failure" "$(echo "$D" | jget steps.cloudflare-edge.summary)"

echo "== C: origin address 127.0.0.1:$SBQA_PORT (challenge and HTML rewriting still on at the edge)"
R=$(cfqa settings render_origin=127.0.0.1:$SBQA_PORT); [ "$(echo "$R" | jget effective.render_origin)" = "127.0.0.1:$SBQA_PORT" ]; ok $? "render origin saved"
: > "$CF/edge.log"
F=$(export_final); [ "$(echo "$F" | jget status)" = "complete" ] && [ "$(echo "$F" | jget saved)" = "8" ]; ok $? "full export with origin address completes despite the challenge (8 routes)" "$F"
[ "$(grep -c '"render":true' "$CF/edge.log")" = "0" ]; ok $? "no render request reached the edge"
[ "$(edge_markup_files)" = "0" ]; ok $? "stored release contains no Cloudflare markup"
grep -q "$MKB" "$S/current/hello-world/index.html"; ok $? "content edited during the challenge is now published"
MKC="SBQA_CFC_$(date +%s%N)"; cfqa mark 1 "$MKC" > /dev/null; push 1
grep -q "$MKC" "$S/current/hello-world/index.html" && ! grep -q 'cdn-cgi' "$S/current/hello-world/index.html"; ok $? "push through the origin address publishes clean HTML"
D=$(cfqa diagnostic render-origin cloudflare-markup cloudflare-edge)
[ "$(echo "$D" | jget steps.render-origin.status)" = "pass" ] && echo "$D" | jget steps.render-origin.summary | grep -q "127.0.0.1:$SBQA_PORT"; ok $? "Diagnostica: render via origin address passes" "$(echo "$D" | jget steps.render-origin.summary)"
[ "$(echo "$D" | jget steps.cloudflare-markup.status)" = "pass" ]; ok $? "Diagnostica: no edge markup left in the release"
[ "$(echo "$D" | jget steps.cloudflare-edge.status)" = "fail" ]; ok $? "Diagnostica still reports that the public path is challenged (diagnostics stay public)"
edge_mode false true false
D=$(cfqa diagnostic cloudflare-edge); [ "$(echo "$D" | jget steps.cloudflare-edge.status)" = "pass" ]; ok $? "Diagnostica passes once the public path is not challenged" "$(echo "$D" | jget steps.cloudflare-edge.summary)"

echo "== D: Cache-Control, edge cache and purge through the fake API"
CC="public, max-age=0, s-maxage=600"
R=$(cfqa settings "static_cache_control=$CC" cloudflare_purge=1); reload_web
[ "$(echo "$R" | jget effective.cloudflare_purge)" = "1" ] && [ "$(cfqa state | jget purge_config.ready)" = "true" ]; ok $? "purge enabled with the QA wp-config constants"
cfqa run-purge > /dev/null; : > "$CF/api.log"
edge_mode false true true
H1=$(hdr "$B/hello-world/"); echo "$H1" | grep -qi "^cache-control: $CC\$"; ok $? "origin static route sends Cache-Control: $CC" "$(echo "$H1" | grep -i cache-control)"
H1=$(hdr "$B/cf-not-exported-qa/"); echo "$H1" | grep -q ' 404' && echo "$H1" | grep -qi '^cache-control: no-store, max-age=0$'; ok $? "origin Strict 404 sends no-store" "$(echo "$H1" | head -1; echo "$H1" | grep -i cache-control)"
H1=$(hdr "$B/hello-world/?utm_source=qa"); ! echo "$H1" | grep -qi "s-maxage"; ok $? "dynamic fallback (query string) does not get the static Cache-Control"
hdr "$EB/hello-world/" > /dev/null; H1=$(hdr "$EB/hello-world/"); echo "$H1" | grep -qi '^cf-cache-status: HIT'; ok $? "edge caches the static route (HIT)"
hdr "$EB/cf-not-exported-qa/" > /dev/null; H1=$(hdr "$EB/cf-not-exported-qa/"); ! echo "$H1" | grep -qi '^cf-cache-status: HIT'; ok $? "edge never caches the Strict 404"
MKD="SBQA_CFD_$(date +%s%N)"; cfqa mark 1 "$MKD" > /dev/null; push 1
grep -q "\"files\":\[[^]]*\"$EB/hello-world/\"" "$CF/api.log" && grep -q '"auth_ok":true' "$CF/api.log" && ! grep -q '"auth_ok":false' "$CF/api.log"; ok $? "push purges the route URL with the zone token" "$(tail -n 2 "$CF/api.log")"
H1=$(curl -s -D - "$EB/hello-world/" | tr -d '\r'); echo "$H1" | grep -qi '^cf-cache-status: MISS' && echo "$H1" | grep -q "$MKD"; ok $? "next visitor gets the pushed content from the edge (MISS, new marker)"
hdr "$EB/sample-page/" > /dev/null
MKE="SBQA_CFE_$(date +%s%N)"; cfqa mark 2 "$MKE" > /dev/null
U=1; for i in $(seq 1 18); do curl -s -o /dev/null "$B/wp-cron.php"; sleep 3; grep -q "$EB/sample-page/" "$CF/api.log" && grep -q "$MKE" "$S/current/sample-page/index.html" && { U=0; break; }; done
ok $U "incremental update through WP-Cron purges the changed page"
curl -s "$EB/sample-page/" | grep -q "$MKE"; ok $? "edge serves the incrementally updated page"
for i in $(seq 1 10); do curl -s -o /dev/null "$B/wp-cron.php"; sleep 1; [ "$(cfqa state | jget incremental_queue)" = "0" ] && break; done
BEFORE_ALL=$(api_count purge_everything)
F=$(export_final); [ "$(echo "$F" | jget status)" = "complete" ]; ok $? "full export completes" "$F"
U=1; for i in $(seq 1 10); do curl -s -o /dev/null "$B/wp-cron.php"; sleep 1; [ "$(api_count purge_everything)" -gt "$BEFORE_ALL" ] && { U=0; break; }; done
ok $U "full export purges the whole zone (purge_everything)"
[ -z "$(ls -A "$CF/cache")" ]; ok $? "edge cache empty after the zone purge"
echo '{"fail":1}' > "$CF/api-mode.json"
MKF="SBQA_CFF_$(date +%s%N)"; cfqa mark 1 "$MKF" > /dev/null; push 1
grep -q "$MKF" "$S/current/hello-world/index.html"; ok $? "a failing purge does not block the push"
ST=$(cfqa state); [ "$(echo "$ST" | jget pending.attempts)" = "1" ] && echo "$ST" | jget activity | grep -q 'ripetuto'; ok $? "purge failure is a warning in Attività and is kept for retry" "$(echo "$ST" | jget last.message)"
R=$(cfqa run-purge); [ "$(echo "$R" | jget last.status)" = "ok" ] && [ "$(echo "$R" | jget pending)" = "null" ]; ok $? "retried purge succeeds" "$R"
ALL=$(cat "$CF"/*.log "$E/logs/php-errors.log" 2>/dev/null; cfqa state)
! echo "$ALL" | grep -qF "$TOKEN"; ok $? "API token absent from Attività, purge status, fake logs and PHP error log"
PAGE=$(curl -s -H @"$E/run/cookie-administrator.txt" "$B/wp-admin/admin.php?page=static-bridge&tab=settings")
echo "$PAGE" | grep -q 'http.request.method in {&quot;GET&quot; &quot;HEAD&quot;}' && echo "$PAGE" | grep -q 'name="render_origin"' && echo "$PAGE" | grep -q 'static_bridge_cloudflare_purge' && ! echo "$PAGE" | grep -qF "$TOKEN"
ok $? "Settings show the Cache Rule expression, origin field and purge button without the token"
WS=$(curl -s -o /dev/null -w '%{http_code}' -H @"$E/run/cookie-editor.txt" -d "action=static_bridge_cloudflare_purge&_wpnonce=deadbeef00" "$B/wp-admin/admin-post.php")
[ "$WS" = "403" ]; ok $? "editor cannot trigger a Cloudflare purge (403)"

echo "== E: deactivation purges the edge"
BEFORE_ALL=$(api_count purge_everything)
cfqa home origin > /dev/null
$PHP84 "$H/wp69-harness.php" deactivate > /dev/null; ok $? "plugin deactivated"
[ "$(api_count purge_everything)" -gt "$BEFORE_ALL" ]; ok $? "deactivation purged the whole zone"
$PHP84 "$H/wp69-harness.php" reactivate > /dev/null; ok $? "plugin reactivated"

echo
echo "Cloudflare E2E ($SBQA_STACK): $PASS PASS, $FAIL FAIL"
[ "$FAIL" = "0" ]
