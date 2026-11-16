#!/usr/bin/env bash
# Fluent Forms on static pages, end to end, on one disposable stack (SBQA_STACK=ff-nginx|ff-apache):
# $SBQA_HOME/wpff (Nginx :18721) or $SBQA_HOME/wpffa (Apache :18723). Nothing else is touched.
#
# Resets the stack to its clean DB baseline, installs the ZIP, activates Fluent Forms 6.2.15
# (copied read only from the LocalWP "test" site), turns on token-based spam protection and the
# submit-nonce check, prints form #2 in the global footer of every page, and gives the three
# Fluent Forms nonce actions a 60 s lifetime (QA mu-plugin) so the nonces in the exported HTML
# really expire. Then:
#   - control: after the lifetime, the nonces in the static HTML are rejected by WordPress;
#   - a real headless Chrome submits the in-content form and the footer form from the static
#     pages (same HTML, expired nonces) and Fluent Forms stores the entries;
#   - drift: when fluentFormVars lacks token_nonce the routes fall back to WordPress and recover;
#   - the Moduli switch over HTTP restores the 2.0.15 behaviour and back.
# Mail goes to run/mail-sink.log only; synthetic entries are deleted at the end.
# Usage: SBQA_STACK=ff-nginx|ff-apache [SBQA_PLUGIN_SOURCE=<plugin dir in the repo>] bash ff-e2e.sh <zip>
set -u
H=$(cd "$(dirname "$0")" && pwd)
. "$H/profile.sh"
sbqa_require_stack; E=$SBQA_E
PROJECT=$SBQA_REPO
export SBQA_PLUGIN_SOURCE=${SBQA_PLUGIN_SOURCE:-$PROJECT/plugin}
ZIP=${1:-}
LIFE=60
PHP84="env LD_LIBRARY_PATH=$P/shared-libs $P/bin/php -n -c $E/conf/php.ini"
MYSQL="env LD_LIBRARY_PATH=$M/lib $M/bin/mysql --no-defaults -uroot --socket=$E/run/mysql.sock"
WP=$E/wordpress
S=$WP/wp-content/static-bridge
RUN_ID=$(date +%s)
PASS=0; FAIL=0
ok() { if [ "$1" = "0" ]; then PASS=$((PASS+1)); echo "[PASS] $2"; else FAIL=$((FAIL+1)); echo "[FAIL] $2${3:+ :: $3}"; fi; }
jget() { php -r '$j=json_decode(stream_get_contents(STDIN),true); $v=$j; foreach(explode(".",$argv[1]) as $k){ $v=is_array($v)&&array_key_exists($k,$v)?$v[$k]:null; } echo is_scalar($v)?(is_bool($v)?($v?"true":"false"):$v):json_encode($v);' "$1"; }
export_final() { php "$H/http-gui.php" administrator export | jget final; }
static_header() { curl -s -o /dev/null -D - "$B$1" | tr 'A-Z' 'a-z' | awk 'BEGIN { n = 0 } /^(x-static-bridge-route: static|x-sbqa-apache-file: static-bridge)/ { n = 1 } END { print n }'; }
nginx_block() { [ "$SBQA_WEB" = "nginx" ] && $PHP84 "$H/wp69-harness.php" block > /dev/null && $NGX -s reload -p "$E/run" -c "$E/conf/nginx.conf" && sleep 0.5; return 0; }
ajax() { curl -s -X POST "$B/wp-admin/admin-ajax.php" "$@"; }

case "$ZIP" in /*) ;; *) ZIP="$PWD/$ZIP" ;; esac
[ -f "$ZIP" ] || { echo "Usage: bash ff-e2e.sh <zip>"; exit 2; }
[ "$(curl -s -o /dev/null -w "%{http_code}" --max-time 5 $B/wp-login.php)" != "000" ] || { echo "Stack not running on $B"; exit 2; }
case "$S" in "$SBQA_HOME/$SBQA_ENV_NAME/wordpress/wp-content/static-bridge") ;; *) echo "REFUSED: unexpected static root $S"; exit 2 ;; esac

echo "== Reset $SBQA_ENV_NAME to its clean baseline, install $(basename "$ZIP") ($(sha256sum "$ZIP" | cut -c1-16)…)"
rm -f "$E/run/fault.json" "$E/run/ff-"* "$E/run/mail-sink.log"
$MYSQL -e "DROP DATABASE IF EXISTS $SBQA_DB; CREATE DATABASE $SBQA_DB CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_520_ci;"
$MYSQL $SBQA_DB < "$E/backups/00-baseline-before-activation.sql"
rm -rf "$S" "$WP/wp-content/plugins/static-bridge" "$WP/wp-content/uploads/fluentform"
[ "$SBQA_WEB" = "apache" ] && rm -f "$WP/.htaccess"
unzip -q "$ZIP" -d "$WP/wp-content/plugins/"
diff -rq -x LICENSE "$SBQA_PLUGIN_SOURCE" "$WP/wp-content/plugins/static-bridge" > /dev/null; ok $? "installed ZIP is byte-identical to the plugin source"
if [ "$SBQA_WEB" = "nginx" ]; then
    echo 'location / { try_files $uri $uri/ /index.php$is_args$args; }' > "$E/conf/static-bridge.conf"
    $NGX -s reload -p "$E/run" -c "$E/conf/nginx.conf"; sleep 0.5
fi
: > "$E/logs/php-errors.log"
R=$($PHP84 "$H/wp69-harness.php" activate); [ "$(echo "$R" | jget result)" = "PASS" ]; ok $? "Static Bridge $(echo "$R" | jget version) activated"
[ "$SBQA_WEB" = "nginx" ] && { $NGX -s reload -p "$E/run" -c "$E/conf/nginx.conf"; sleep 0.5; }
$PHP84 "$H/wp69-harness.php" session administrator > /dev/null
R=$($PHP84 "$H/ff-qa.php" setup); [ "$(echo "$R" | jget result)" = "PASS" ] && [ "$(echo "$R" | jget token_protection)" = "yes" ] && [ "$(echo "$R" | jget forms)" = "[1,2]" ]
ok $? "Fluent Forms $(echo "$R" | jget fluentform_version) active, forms #1 and #2, token-based spam protection on"
CONTATTI_ID=$(echo "$R" | jget pages.contatti-qa); NOTIZIA_ID=$(echo "$R" | jget pages.notizia-qa)
echo 2 > "$E/run/ff-footer-form"; echo "$LIFE" > "$E/run/ff-nonce-life"; touch "$E/run/ff-nonce-verify"
echo "   QA: form #2 in the global footer of every page; Fluent Forms nonces live ${LIFE}s; submit nonce verified; mail sink only"
T=$(php "$H/ff-gui.php" tab); [ "$(echo "$T" | jget switch_present)" = "true" ] && [ "$(echo "$T" | jget switch_checked)" = "true" ]
ok $? "Moduli tab shows the nonce refresh switch, on by default"

echo "== Export with token protection on: pages with forms stay static"
F=$(export_final); EXPORTED_AT=$(date +%s)
[ "$(echo "$F" | jget status)" = "complete" ] && [ "$(echo "$F" | jget skipped)" = "0" ]; ok $? "full export completes, no route left dynamic ($(echo "$F" | jget saved) saved)" "$F"
C=$S/current/contatti-qa/index.html; N=$S/current/notizia-qa/index.html
[ -f "$C" ] && [ -f "$N" ] && [ -f "$S/current/hello-world/index.html" ]; ok $? "/contatti-qa/ (form in content), /notizia-qa/ and /hello-world/ (footer form only) are in the release"
[ "$(grep -c 'id="static-bridge-fluent-forms"' "$C")" = "1" ] && [ "$(grep -c 'id="static-bridge-fluent-forms"' "$N")" = "1" ] && grep -q 'id="static-bridge-fluent-forms"' "$S/current/hello-world/index.html"
ok $? "the nonce refresher is injected once per page, including footer-only pages"
grep -q "name='__fluent_form_embded_post_id' value='$CONTATTI_ID'" "$C" && grep -q "name='__fluent_form_embded_post_id' value='$NOTIZIA_ID'" "$N" && grep -q 'fluent-form-token-field' "$C"
ok $? "per-page hidden fields stay as rendered (embed post id $CONTATTI_ID / $NOTIZIA_ID) and the empty token field is kept"
[ "$(static_header /contatti-qa/)" = "1" ] && [ "$(static_header /notizia-qa/)" = "1" ]; ok $? "both pages are answered from the static release"
[ "$($PHP84 "$H/ff-qa.php" inspect | jget static_bridge_fluent_forms_dynamic)" = "[]" ]; ok $? "no route recorded as dynamic fallback"

echo "== Control: the nonces frozen in the static HTML really expire"
STALE_TOKEN_NONCE=$(grep -oE '"token_nonce":"[0-9a-f]+"' "$C" | tail -1 | cut -d'"' -f4)
STALE_SUBMIT=$(grep -oE 'name="_fluentform_1_fluentformnonce" value="[0-9a-f]+"' "$C" | grep -oE '[0-9a-f]{10}"$' | tr -d '"')
[ -n "$STALE_TOKEN_NONCE" ] && [ -n "$STALE_SUBMIT" ]; ok $? "static HTML carries token_nonce $STALE_TOKEN_NONCE and submit nonce $STALE_SUBMIT"
[ "$(ajax --data "action=fluentform_generate_protection_token&form_id=1&nonce=$STALE_TOKEN_NONCE" | jget success)" = "true" ]; ok $? "right after export the static token_nonce is still accepted"
WAIT=$((EXPORTED_AT + LIFE + 8 - $(date +%s))); [ "$WAIT" -gt 0 ] && { echo "   waiting ${WAIT}s for the original nonce lifetime to pass…"; sleep "$WAIT"; }
R=$(ajax --data "action=fluentform_generate_protection_token&form_id=1&nonce=$STALE_TOKEN_NONCE"); [ "$(echo "$R" | jget success)" = "false" ]; ok $? "after ${LIFE}s WordPress rejects the static token_nonce ($(echo "$R" | jget data.message))"
HDRS=$(mktemp); FRESH=$(ajax -D "$HDRS" --data "action=static_bridge_public_form_nonces")
FRESH_TOKEN_NONCE=$(echo "$FRESH" | jget data.token_nonce); FRESH_SUBMIT=$(echo "$FRESH" | jget data.submit_nonce)
[ "$(echo "$FRESH" | jget success)" = "true" ] && [ -n "$FRESH_TOKEN_NONCE" ] && [ "$FRESH_TOKEN_NONCE" != "$STALE_TOKEN_NONCE" ] && grep -qi '^cache-control:.*no-store' "$HDRS"
ok $? "the public endpoint returns a new token_nonce and submit nonce with Cache-Control no-store" "$FRESH"; rm -f "$HDRS"
TOKEN=$(ajax --data "action=fluentform_generate_protection_token&form_id=1&nonce=$FRESH_TOKEN_NONCE" | jget data.token)
INNER="names%5Bfirst_name%5D=QA&email=stale-$RUN_ID%40example.invalid&subject=stale&message=x&__fluent_form_embded_post_id=$CONTATTI_ID&_fluentform_1_fluentformnonce=$STALE_SUBMIT&_wp_http_referer=%2Fcontatti-qa%2F&__fluent_protection_token_1=$(php -r 'echo rawurlencode($argv[1]);' "$TOKEN")"
CODE=$(ajax -o "$E/run/stale-submit.json" -w '%{http_code}' --data-urlencode "data=$(php -r 'echo rawurldecode($argv[1]);' "$INNER")" --data "action=fluentform_submit&form_id=1")
[ "$CODE" = "422" ] && grep -q '_fluentformnonce' "$E/run/stale-submit.json"; ok $? "after ${LIFE}s a submission with the static submit nonce is refused (HTTP $CODE, nonce error)"; rm -f "$E/run/stale-submit.json"

echo "== Real browser on the static pages, after the original nonce lifetime"
check_browser() { # $1 json, $2 stale token nonce, $3 form id, $4 expected embed id
    php -r '
        $r = json_decode(stream_get_contents(STDIN), true); [$stale, $form, $embed] = array_slice($argv, 1);
        $h = array_change_key_case((array) ($r["documentHeaders"] ?? array()));
        $static = ("static" === strtolower($h["x-static-bridge-route"] ?? "")) || ("static-bridge" === strtolower($h["x-sbqa-apache-file"] ?? ""));
        $by = array(); foreach ((array) ($r["ajax"] ?? array()) as $a) { $by[$a["action"]][] = $a; }
        parse_str($by["fluentform_generate_protection_token"][0]["body"] ?? "", $tok);
        parse_str($by["fluentform_submit"][0]["body"] ?? "", $sub); parse_str($sub["data"] ?? "", $fields);
        $checks = array(
            "served static" => $static,
            "page still carries the expired token_nonce" => ($r["page"]["staticTokenNonce"] ?? "") === $stale,
            "refresher loaded, no request before interaction" => !empty($r["page"]["refresherState"]) && 0 === ($r["page"]["refresherRequestsBeforeInteraction"] ?? -1),
            "one refresh request" => 1 === count($by["static_bridge_public_form_nonces"] ?? array()),
            "token request used a fresh nonce" => isset($tok["nonce"]) && $tok["nonce"] !== $stale && false !== strpos($by["fluentform_generate_protection_token"][0]["response"] ?? "", "\"success\":true"),
            "submit carried a refreshed submit nonce" => isset($fields["_fluentform_{$form}_fluentformnonce"]) && $fields["_fluentform_{$form}_fluentformnonce"] !== ($r["page"]["staticSubmitNonce"] ?? ""),
            "per-page embed id kept" => ($fields["__fluent_form_embded_post_id"] ?? "") === $embed,
            "submit HTTP 200" => 200 === ($by["fluentform_submit"][0]["status"] ?? 0),
            "Fluent Forms success message" => !empty($r["outcome"]["success"]),
        );
        $bad = array_keys(array_filter($checks, static fn ($v) => !$v));
        echo $bad ? "FAILED: " . implode(", ", $bad) . " | outcome: " . json_encode($r["outcome"] ?? $r["error"] ?? null) : "ok: " . ($r["outcome"]["message"] ?? "");
        exit($bad ? 1 : 0);' "$2" "$3" "$4" <<< "$1"
}
BR=$(node "$H/ff-browser.js" "$B/contatti-qa/" '#fluentform_1' "contatti-$RUN_ID@example.invalid" "QA contatti $RUN_ID"); echo "$BR" > "$E/run/browser-contatti.json"
OUT=$(check_browser "$BR" "$STALE_TOKEN_NONCE" 1 "$CONTATTI_ID"); ok $? "in-content form #1 submitted from the static page: $OUT"
EN=$($PHP84 "$H/ff-qa.php" entries); [ "$(echo "$EN" | jget forms.1.count)" = "1" ] && [ "$(echo "$EN" | jget forms.1.last_email)" = "contatti-$RUN_ID@example.invalid" ] && [ "$(echo "$EN" | jget forms.1.last_embed_post_id)" = "$CONTATTI_ID" ]
ok $? "Fluent Forms stored exactly that entry for form #1 (embed post $CONTATTI_ID)" "$EN"
BR=$(node "$H/ff-browser.js" "$B/notizia-qa/" '#fluentform_2' "footer-$RUN_ID@example.invalid" "QA footer $RUN_ID"); echo "$BR" > "$E/run/browser-footer.json"
FOOT_STALE=$(grep -oE '"token_nonce":"[0-9a-f]+"' "$N" | tail -1 | cut -d'"' -f4)
OUT=$(check_browser "$BR" "$FOOT_STALE" 2 "$NOTIZIA_ID"); ok $? "global footer form #2 submitted from a static page without forms in its content: $OUT"
EN=$($PHP84 "$H/ff-qa.php" entries); [ "$(echo "$EN" | jget forms.2.count)" = "1" ] && [ "$(echo "$EN" | jget forms.2.last_email)" = "footer-$RUN_ID@example.invalid" ] && [ "$(echo "$EN" | jget forms.2.last_embed_post_id)" = "$NOTIZIA_ID" ]
ok $? "Fluent Forms stored exactly that entry for form #2 (embed post $NOTIZIA_ID)" "$EN"

echo "== Drift: markup the refresher does not recognize falls back to WordPress, then recovers"
touch "$E/run/ff-drift"
REL_BEFORE=$(readlink "$S/current")
F=$(export_final)
INS=$($PHP84 "$H/ff-qa.php" inspect)
# Form #2 is in the footer of every page, so every route falls back and nothing can be saved: the
# existing rule refuses an empty release and keeps the previous one; routing must still go dynamic.
[ "$(echo "$F" | jget status)" = "failed" ] && [ "$(echo "$F" | jget saved)" = "0" ] && [ "$(echo "$F" | jget skipped)" = "$(echo "$F" | jget processed)" ] && [ "$(echo "$F" | jget processed)" -ge 7 ] && [ "$(readlink "$S/current")" = "$REL_BEFORE" ] \
    && echo "$INS" | jget static_bridge_fluent_forms_dynamic | grep -q 'contatti-qa' && echo "$INS" | jget static_bridge_fluent_forms_dynamic | grep -q 'notizia-qa' && echo "$INS" | jget static_bridge_fluent_forms_dynamic | grep -q 'token_nonce'
ok $? "every route falls back and is recorded with the reason; links still crawled ($(echo "$F" | jget processed) processed); empty release refused, previous release kept" "$F"
T=$(php "$H/ff-gui.php" tab); echo "$T" | php -r '$t=json_decode(stream_get_contents(STDIN),true); foreach($t["pages"] as $p){ if($p["path"]==="/contatti-qa/"){ exit($p["state"]==="dynamic" && $p["select_disabled"] && false!==strpos($p["note"],"token_nonce") ? 0 : 1);} } exit(1);'
ok $? "Moduli tab shows /contatti-qa/ as WordPress-dynamic with the drift reason"
if [ "$SBQA_WEB" = "nginx" ]; then
    echo "   INFO Nginx before reinstalling the routing block: /contatti-qa/ -> HTTP $(curl -s -o /dev/null -w '%{http_code}' $B/contatti-qa/), static=$(static_header /contatti-qa/) (the previous release's copy is served if it exists; with Strict and no copy, 404)"
    nginx_block
else
    grep -q 'contatti\\-qa\|contatti-qa' "$WP/.htaccess"; ok $? "Apache: the export rewrote .htaccess with the fallback routes although it could not publish"
fi
[ "$(curl -s -o /dev/null -w '%{http_code}' $B/contatti-qa/)" = "200" ] && [ "$(static_header /contatti-qa/)" = "0" ] && [ "$(static_header /notizia-qa/)" = "0" ]; ok $? "fallback routes are answered by WordPress (200, not static)"
rm -f "$E/run/ff-drift"
BR=$(node "$H/ff-browser.js" "$B/contatti-qa/" '#fluentform_1' "dynamic-$RUN_ID@example.invalid" "QA dynamic $RUN_ID")
echo "$BR" | php -r '$r=json_decode(stream_get_contents(STDIN),true); $h=array_change_key_case((array)($r["documentHeaders"]??[])); exit(!empty($r["outcome"]["success"]) && empty($h["x-static-bridge-route"]) && empty($h["x-sbqa-apache-file"]) && empty($r["page"]["refresherScript"]) ? 0 : 1);'
ok $? "a fallback route still submits normally (dynamic page, no refresher)" "$(echo "$BR" | jget outcome)"
F=$(export_final); nginx_block
[ "$(echo "$F" | jget status)" = "complete" ] && [ "$($PHP84 "$H/ff-qa.php" inspect | jget static_bridge_fluent_forms_dynamic)" = "[]" ] && grep -q 'id="static-bridge-fluent-forms"' "$S/current/contatti-qa/index.html" && [ "$(static_header /contatti-qa/)" = "1" ]
ok $? "after the markup is recognized again the next export publishes static copies and clears the fallback list"

echo "== Moduli switch over HTTP: off restores the 2.0.15 behaviour, on returns to static"
R=$(php "$H/ff-gui.php" refresh off); [ "$(echo "$R" | jget result)" = "PASS" ] && [ "$($PHP84 "$H/ff-qa.php" inspect | jget static_bridge_nonce_refresh)" = "0" ]; ok $? "switch off saved through admin-post (capability + nonce)"
nginx_block
[ "$(static_header /contatti-qa/)" = "0" ] && [ "$(static_header /notizia-qa/)" = "0" ] && [ "$(curl -s -o /dev/null -w '%{http_code}' $B/contatti-qa/)" = "200" ]
ok $? "switch off: token-protected pages (all of them, through the footer form) are routed to WordPress, as in 2.0.15"
F=$(export_final)
[ "$(echo "$F" | jget status)" = "failed" ] && [ "$(echo "$F" | jget saved)" = "0" ]; ok $? "switch off: as in 2.0.15 a footer form on every page leaves nothing to export (empty release refused)" "$F"
[ "$(ajax --data 'action=static_bridge_public_form_nonces' | jget success)" = "false" ]; ok $? "switch off: the public nonce endpoint answers nothing"
R=$(php "$H/ff-gui.php" refresh on); F=$(export_final); nginx_block
[ "$(echo "$R" | jget result)" = "PASS" ] && [ "$(echo "$F" | jget status)" = "complete" ] && [ "$(static_header /contatti-qa/)" = "1" ]; ok $? "switch on again: the page is static after the next export"

echo "== Cleanup and logs"
R=$($PHP84 "$H/ff-qa.php" cleanup); [ "$(echo "$R" | jget result)" = "PASS" ]; ok $? "synthetic Fluent Forms entries deleted (left: $(echo "$R" | jget submissions_left))"
echo "   mail sink: $([ -f "$E/run/mail-sink.log" ] && wc -l < "$E/run/mail-sink.log" || echo 0) message(s) captured, none sent"
rm -f "$E/run/ff-"*
NE=$(grep -cE 'wp-content/plugins/static-bridge/' "$E/logs/php-errors.log"); [ "$NE" = "0" ]; ok $? "no PHP notice/warning/error from Static Bridge ($NE lines)"

echo
if [ "$SBQA_WEB" = "apache" ]; then WEBV=$($HTTPD -v | head -1 | cut -d' ' -f3); else WEBV=$($NGX -v 2>&1 | cut -d' ' -f3); fi
echo "FF E2E RESULT: $PASS passed, $FAIL failed — $(basename "$ZIP") + Fluent Forms on WordPress $(grep "wp_version =" "$WP/wp-includes/version.php" | grep -oE "[0-9.]+") / PHP $($PHP84 -r 'echo PHP_VERSION;') FPM / $WEBV"
exit $([ "$FAIL" = "0" ] && echo 0 || echo 1)
