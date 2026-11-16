#!/usr/bin/env bash
# Complianz on static pages, end to end, on one disposable stack (SBQA_STACK=ff-nginx|ff-apache). Nothing else is touched.
#
# Resets the stack to its clean DB baseline, installs the ZIP, copies the real Complianz in (SBQA_COMPLIANZ_SOURCE,
# default the newest free Complianz among the LocalWP sites, read only) with two probe scripts it has to block, exports. Then:
#   - a real headless Chrome, as an anonymous visitor on the static page: banner, scripts held back, "Accept", scripts run,
#     consent stored by POST /wp-json/complianz/v1/track, page still static with the cmplz_* cookies, revocation, Do Not Track;
#   - the cookie policy page and its consent form loaded over REST;
#   - real saves through Complianz's own REST routes and admin nonces (banner, consent policy, Script Center, a cookie):
#     each one has to refresh the static pages, and the worker must not requeue anything by itself;
#   - a visitor who consented under the old policy is asked again, on the static page.
# Usage: SBQA_STACK=ff-nginx|ff-apache [SBQA_PLUGIN_SOURCE=<plugin dir>] bash complianz-e2e.sh <zip>
set -u
H=$(cd "$(dirname "$0")" && pwd)
. "$H/profile.sh"
sbqa_require_stack; E=$SBQA_E
export SBQA_PLUGIN_SOURCE=${SBQA_PLUGIN_SOURCE:-$SBQA_REPO/plugin}
SOURCE=${SBQA_COMPLIANZ_SOURCE:-$(for d in "$HOME/Local Sites"/*/app/public/wp-content/plugins/complianz-gdpr; do
    [ -f "$d/complianz-gpdr.php" ] && printf '%s\t%s\n' "$(grep -m1 -oE 'Version: *[0-9.]+' "$d/complianz-gpdr.php" | grep -oE '[0-9.]+$')" "$d"
done | sort -V | tail -1 | cut -f2-)}
ZIP=${1:-}
PHP84="env LD_LIBRARY_PATH=$P/shared-libs $P/bin/php -n -c $E/conf/php.ini"
MYSQL="env LD_LIBRARY_PATH=$M/lib $M/bin/mysql --no-defaults -uroot --socket=$E/run/mysql.sock"
WP=$E/wordpress
S=$WP/wp-content/static-bridge
PAGE=/hello-world/
POLICY=/cookie-policy-eu/
PASS=0; FAIL=0
ok() { if [ "$1" = "0" ]; then PASS=$((PASS+1)); echo "[PASS] $2"; else FAIL=$((FAIL+1)); echo "[FAIL] $2${3:+ :: $3}"; fi; }
jget() { php -r '$j=json_decode(stream_get_contents(STDIN),true); $v=$j; foreach(explode(".",$argv[1]) as $k){ $v=is_array($v)&&array_key_exists($k,$v)?$v[$k]:null; } echo is_scalar($v)?(is_bool($v)?($v?"true":"false"):$v):json_encode($v);' "$1"; }
qa() { $PHP84 "$H/complianz-qa.php" "$@"; }
worker() { qa worker | jget left; }
in_page() { grep -oE "\"$2\":\"?[0-9]+" "$S/current${1}index.html" | grep -oE '[0-9]+$' | head -1; }
nonces() { curl -s -H @"$E/run/cookie-administrator.txt" "$B/wp-admin/admin.php?page=complianz" > "$E/run/cz-admin.html"
    PAIR=$(grep -oE '"nonce":"[0-9a-f]+","cmplz_nonce":"[0-9a-f]+"' "$E/run/cz-admin.html" | head -1); WPN=$(echo "$PAIR" | cut -d'"' -f4); CZN=$(echo "$PAIR" | cut -d'"' -f8); rm -f "$E/run/cz-admin.html"; }
rest() { curl -s -o "$E/run/cz-rest.json" -w '%{http_code}' -X POST -H @"$E/run/cookie-administrator.txt" -H "X-WP-Nonce: $WPN" -H 'Content-Type: application/json' -d "$2" "$B/wp-json/complianz/v1/$1"; }
cleanup() {
    rm -rf "$WP/wp-content/plugins/complianz-gdpr" "$WP/wp-content/uploads/complianz"
    rm -f "$WP/wp-content/mu-plugins/sbqa-complianz-probe.php" "$WP/wp-content/sbqa-stat.js" "$WP/wp-content/sbqa-mkt.js" "$E/run/cz-rest.json" "$E/run/cz-renew.sh"
}

case "$ZIP" in /*) ;; *) ZIP="$PWD/$ZIP" ;; esac
[ -f "$ZIP" ] || { echo "Usage: bash complianz-e2e.sh <zip>"; exit 2; }
[ -f "$SOURCE/complianz-gpdr.php" ] || { echo "No Complianz at $SOURCE"; exit 2; }
[ "$(curl -s -o /dev/null -w "%{http_code}" --max-time 5 $B/wp-login.php)" != "000" ] || { echo "Stack not running on $B"; exit 2; }
case "$S" in "$SBQA_HOME/$SBQA_ENV_NAME/wordpress/wp-content/static-bridge") ;; *) echo "REFUSED: unexpected static root $S"; exit 2 ;; esac
trap cleanup EXIT

echo "== Complianz $(grep -m1 ' \* Version:' "$SOURCE/complianz-gpdr.php" | sed 's/.*: *//') still has what Static Bridge listens to"
grep -q "do_action( 'cmplz_after_css_generation'" "$SOURCE/cookiebanner/class-cookiebanner.php"; ok $? "cmplz_after_css_generation is still fired"
grep -q "apply_filters( 'cmplz_do_action'" "$SOURCE/settings/settings.php"; ok $? "the cmplz_do_action filter still carries the admin saves"
grep -q "update_option( 'cmplz_options'" "$SOURCE/settings/settings.php" && grep -q "update_option( 'complianz_active_policy_id'" "$SOURCE/cookiebanner/class-banner-loader.php"; ok $? "settings and policy id still live in the same options"
grep -q 'cmplz_cookiebanners' "$SOURCE/cookiebanner/class-cookiebanner.php" && grep -q "'banner_version'" "$SOURCE/cookiebanner/class-cookiebanner.php" && grep -q "'current_policy_id'" "$SOURCE/cookiebanner/class-cookiebanner.php"; ok $? "banner table and front-end config keep their names"

echo "== Reset $SBQA_ENV_NAME to its clean baseline, install $(basename "$ZIP") ($(sha256sum "$ZIP" | cut -c1-16)…)"
rm -f "$E/run/fault.json" "$E/run/ff-"* "$E/run/mail-sink.log"
$MYSQL -e "DROP DATABASE IF EXISTS $SBQA_DB; CREATE DATABASE $SBQA_DB CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_520_ci;"
$MYSQL $SBQA_DB < "$E/backups/00-baseline-before-activation.sql"
rm -rf "$S" "$WP/wp-content/plugins/static-bridge"
cleanup
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
rsync -a --delete "$SOURCE/" "$WP/wp-content/plugins/complianz-gdpr/"
mkdir -p "$WP/wp-content/mu-plugins"; cp "$H/mu-plugins/sbqa-complianz-probe.php" "$WP/wp-content/mu-plugins/"
echo 'window.__sbqa=window.__sbqa||{};window.__sbqa.stat=(window.__sbqa.stat||0)+1;' > "$WP/wp-content/sbqa-stat.js"
echo 'window.__sbqa=window.__sbqa||{};window.__sbqa.mkt=(window.__sbqa.mkt||0)+1;' > "$WP/wp-content/sbqa-mkt.js"
R=$(qa activate); [ "$(echo "$R" | jget result)" = "PASS" ]; ok $? "Complianz activated" "$R"
R=$(qa setup); [ "$(echo "$R" | jget result)" = "PASS" ]; ok $? "Complianz set up: EU opt-in, records of consent, Do Not Track, two blocked probes ($R)" "$R"
curl -s -o /dev/null "$B/"; curl -s -o /dev/null "$B/"

echo "== Export: the banner and the blocks go into the static HTML"
F=$(php "$H/http-gui.php" administrator export | jget final)
[ "$(echo "$F" | jget status)" = "complete" ]; ok $? "full export completes ($(echo "$F" | jget saved) saved)" "$F"
[ "$SBQA_WEB" = "nginx" ] && { $PHP84 "$H/wp69-harness.php" block > /dev/null; $NGX -s reload -p "$E/run" -c "$E/conf/nginx.conf"; sleep 0.5; }
HTML=$S/current${PAGE}index.html
grep -q 'cmplz-cookiebanner' "$HTML" && [ "$(grep -c 'type="text/plain"[^>]*data-cmplz-src="[^"]*sbqa-' "$HTML")" = "2" ]; ok $? "the static page has the banner and both probes held back"
[ -f "$S/current${POLICY}index.html" ] && grep -q 'cmplz-document' "$S/current${POLICY}index.html"; ok $? "the cookie policy is in the release"
! grep -qE '_wpnonce|"nonce":"[0-9a-f]{10}"' "$HTML"; ok $? "no nonce in the static page"

echo "== Real browser, anonymous visitor, static page"
node "$H/consent-browser.js" "$B" "$PAGE" "$POLICY" > "$E/run/cz-browser.json" 2> "$E/run/cz-browser.err"; [ -s "$E/run/cz-browser.json" ] || cat "$E/run/cz-browser.err"
php -r '
    $j = json_decode(file_get_contents($argv[1]), true); $f = $j["fresh"] ?? array(); $d = $j["dnt"] ?? array();
    $track = static fn($s) => array_values(array_filter($s["complianz_rest"] ?? array(), static fn($r) => "POST" === $r["method"] && "track" === $r["path"]));
    $checks = array(
        "first visit: static page, banner visible" => !empty($f["first_visit"]["static"]) && !empty($f["first_visit"]["banner_visible"]),
        "first visit: both scripts held back, not even requested" => array() === $f["first_visit"]["runs"] && array() === $f["first_visit"]["probe_requests"],
        "Accept: the banner closes and both scripts run" => !empty($f["accept_clicked"]) && empty($f["after_accept"]["banner_visible"]) && 1 === ($f["after_accept"]["runs"]["stat"] ?? 0) && 1 === ($f["after_accept"]["runs"]["mkt"] ?? 0),
        "Accept: cookies say allow" => "allow" === ($f["after_accept"]["cookies"]["cmplz_statistics"] ?? "") && "allow" === ($f["after_accept"]["cookies"]["cmplz_marketing"] ?? ""),
        "records of consent: POST track answers 200" => 200 === ($track($f["after_accept"])[0]["status"] ?? 0),
        "return visit with cmplz_* cookies: still the static page" => !empty($f["return_visit"]["static"]) && in_array("cmplz_statistics", $f["return_visit"]["sent_cmplz_cookies"], true),
        "return visit: no banner, scripts run" => empty($f["return_visit"]["banner_visible"]) && 1 === ($f["return_visit"]["runs"]["stat"] ?? 0),
        "revoke: the banner reopens and Deny answers" => !empty($f["banner_reopened"]) && !empty($f["deny_clicked"]),
        "revoke: cookies say deny, consent stored again" => "deny" === ($f["after_revoke_same_page"]["cookies"]["cmplz_statistics"] ?? "") && ($revoked = $track($f["after_revoke_same_page"])[0] ?? array()) && false !== strpos($revoked["body"], "functional") && false === strpos($revoked["body"], "marketing") && (200 === $revoked["status"] || !empty($revoked["canceled"])), // Complianz reloads right after sending this one
        "after revoking: static page, scripts held back again" => !empty($f["visit_after_revoke"]["static"]) && array() === $f["visit_after_revoke"]["runs"] && array() === $f["visit_after_revoke"]["probe_requests"],
        "cookie policy: static page with the document" => !empty($f["policy"]["static"]) && !empty($f["policy"]["has_document"]),
        "cookie policy: consent form filled over REST" => ($f["policy"]["manage_container_filled"] ?? 0) > 0,
        "cookie policy: the visitor changes consent from the form" => !empty($f["policy"]["toggle_clicked"]) && false !== strpos($f["policy"]["cookie_after_toggle"] ?? "", "cmplz_marketing=allow"),
        "Do Not Track: navigator says 1, no banner, nothing runs, recorded" => "1" === ($d["navigator_dnt"] ?? "") && !empty($d["static"]) && empty($d["banner_visible"]) && array() === $d["runs"] && 200 === ($track($d)[0]["status"] ?? 0),
        "no page errors" => array() === ($f["page_errors"] ?? array("?")) && array() === ($d["page_errors"] ?? array("?")),
    );
    foreach ($checks as $label => $passed) { echo ($passed ? "1" : "0"), " ", $label, "\n"; }
' "$E/run/cz-browser.json" > "$E/run/cz-checks.txt"
while read -r R LABEL; do ok $((1-R)) "$LABEL"; done < "$E/run/cz-checks.txt"

echo "== Real saves through Complianz's REST routes: the static pages follow"
nonces; [ -n "$WPN" ] && [ -n "$CZN" ]; ok $? "the Complianz admin page gave its two nonces"
BV=$(in_page "$PAGE" banner_version)
CODE=$(rest do_action/update_banner_data "{\"nonce\":\"$CZN\",\"banner_id\":1,\"fields\":[{\"id\":\"accept\",\"value\":\"Accetto SBQA\"}]}")
Q=$(qa inspect | jget queue); [ "$CODE" = "200" ] && [ "$Q" -gt 2 ]; ok $? "banner saved (HTTP $CODE): $Q routes queued" "$(head -c 200 "$E/run/cz-rest.json")"
D=$(php "$H/http-gui.php" administrator diagnose); echo "$D" | grep -q 'complianz-banner'; ok $? "Diagnostica has the Complianz step"
[ "$(worker)" = "0" ]; ok $? "the worker empties the queue"
[ "$(in_page "$PAGE" banner_version)" -gt "$BV" ] && grep -q 'Accetto SBQA' "$HTML"; ok $? "the static page has the new banner_version and text ($BV -> $(in_page "$PAGE" banner_version))"
[ "$(qa inspect | jget queue)" = "0" ]; ok $? "regenerating pages queues nothing by itself"
curl -s -o /dev/null "$B$PAGE?sbqa=1"; [ "$(qa inspect | jget queue)" = "0" ]; ok $? "a WordPress render of a page queues nothing"
php "$H/http-gui.php" administrator diagnose > "$E/run/cz-diag.json"
php -r '$j=json_decode(file_get_contents($argv[1]),true); foreach($j["results"] as $r){ if("complianz-banner"===$r["id"]){ echo $r["status"]; } }' "$E/run/cz-diag.json" | grep -q pass; ok $? "Diagnostica: banner and policy in step"

cat > "$E/run/cz-renew.sh" <<RENEW
#!/usr/bin/env bash
curl -s -o /dev/null -w 'fields/set %{http_code} ' -X POST -H @"$E/run/cookie-administrator.txt" -H "X-WP-Nonce: $WPN" -H 'Content-Type: application/json' \
    -d '{"nonce":"$CZN","fields":[{"id":"uses_social_media","type":"radio","value":"no"}]}' "$B/wp-json/complianz/v1/fields/set"
SBQA_STACK=$SBQA_STACK SBQA_HOME=$SBQA_HOME $PHP84 "$H/complianz-qa.php" inspect
SBQA_STACK=$SBQA_STACK SBQA_HOME=$SBQA_HOME $PHP84 "$H/complianz-qa.php" worker
RENEW
PV=$(in_page "$PAGE" current_policy_id)
SBQA_BETWEEN="bash $E/run/cz-renew.sh" node "$H/consent-browser.js" "$B" "$PAGE" - renew > "$E/run/cz-renew.json" 2> "$E/run/cz-browser.err"; [ -s "$E/run/cz-renew.json" ] || cat "$E/run/cz-browser.err"
echo "   between: $(php -r '$j=json_decode(file_get_contents($argv[1]),true); echo str_replace("\n"," | ",$j["renew"]["between"]??"");' "$E/run/cz-renew.json")"
[ "$(in_page "$PAGE" current_policy_id)" -gt "$PV" ]; ok $? "a setting that revokes consent raises the policy id in the static page ($PV -> $(in_page "$PAGE" current_policy_id))"
php -r '
    $r = json_decode(file_get_contents($argv[1]), true)["renew"] ?? array(); $before = $r["consented"] ?? array(); $after = $r["after_change"] ?? array();
    exit(!empty($before["static"]) && 1 === ($before["runs"]["stat"] ?? 0) && empty($before["banner_visible"]) && !empty($after["static"]) && !empty($after["banner_visible"]) && array() === $after["runs"] ? 0 : 1);
' "$E/run/cz-renew.json"; ok $? "the visitor who consented under the old policy sees the banner again, and the scripts wait, on the static page"

BEFORE=$(grep -c 'data-cmplz-src="[^"]*sbqa-stat' "$HTML")
RULES=$(php -r '$r=array("block_script"=>array(array("enable"=>0,"name"=>"SBQA stat","urls"=>array("sbqa-stat.js"),"category"=>"statistics"),array("enable"=>1,"name"=>"SBQA mkt","urls"=>array("sbqa-mkt.js"),"category"=>"marketing")),"add_script"=>array(),"whitelist_script"=>array()); echo json_encode(array("nonce"=>$argv[1],"scripts"=>$r));' "$CZN")
CODE=$(rest do_action/update_scripts "$RULES"); Q=$(qa inspect | jget queue); [ "$CODE" = "200" ] && [ "$Q" -gt 2 ]; ok $? "Script Center saved (HTTP $CODE, $(qa inspect | jget rules)): $Q routes queued" "$(head -c 200 "$E/run/cz-rest.json")"
worker > /dev/null
[ "$BEFORE" = "1" ] && [ "$(grep -c 'data-cmplz-src="[^"]*sbqa-stat' "$HTML")" = "0" ] && grep -q 'data-cmplz-src="[^"]*sbqa-mkt' "$HTML"; ok $? "the block list in the static page follows the Script Center"

CODE=$(rest do_action/add_cookie "{\"nonce\":\"$CZN\",\"service\":\"\",\"cookieName\":\"sbqa\"}"); Q=$(qa inspect | jget queue); [ "$Q" -gt 2 ]; ok $? "a cookie added in the cookie list (HTTP $CODE): $Q routes queued" "$(head -c 200 "$E/run/cz-rest.json")"
worker > /dev/null

echo "== Clean"
ERRORS=$(grep -cE 'plugins/static-bridge/|PHP Fatal' "$E/logs/php-errors.log"); [ "$ERRORS" = "0" ]; ok $? "no Static Bridge line and no fatal in the PHP log" "$(grep -E 'plugins/static-bridge/|PHP Fatal' "$E/logs/php-errors.log" | head -3)"
cp "$E/run/cz-browser.json" "$E/run/cz-renew.json" "$E/logs/" 2>/dev/null
rm -f "$E/run/cz-browser.json" "$E/run/cz-renew.json" "$E/run/cz-browser.err" "$E/run/cz-checks.txt" "$E/run/cz-diag.json"
echo; echo "COMPLIANZ RESULT: $PASS passed, $FAIL failed — stack $SBQA_STACK"
[ "$FAIL" = "0" ]
