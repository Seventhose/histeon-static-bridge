#!/usr/bin/env bash
# Archives on the disposable stack selected by SBQA_STACK (2.1.1). A full export lists every archive (an
# orphan and an empty category, a parent whose posts are in its child, authors, dates, every /page/N/);
# with Strict on, short feeds, search, files and /x without the slash still reach WordPress; a publication
# refreshes every page of every list it is in (and a Query Loop page); unpublishing refreshes them again;
# term changes move the routes; the next full export keeps what the incremental worker created.
# Run AFTER run-e2e.sh on the same ZIP (plugin active, release published). It adds posts and categories:
# run it last, before uninstall-e2e.sh.
# Usage: [SBQA_STACK=apache|nginx71] bash archives-e2e.sh
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
get() { php "$H/http-gui.php" anonymous get "$1"; }
# Links to posts and pages (/<slug>/ under the root) of a page, static file or WordPress render, sorted.
# Pagination links are left out: under ?sbq=1 WordPress keeps the query in them.
links() { grep -oE 'href="(https?://[^/"]+)?/sbqa-[a-z0-9_-]+/"' | sed -E 's#.*/(sbqa-[a-z0-9_-]+)/"#\1#' | sort -u; }
# Calls wp-cron.php (as a system cron) until the condition holds, at most 3 minutes.
cron_until() { for _ in $(seq 1 36); do curl -s -o /dev/null "$B/wp-cron.php"; sleep 5; eval "$1" && return 0; done; return 1; }
has() { [ -f "$S/current/$1index.html" ]; }
static_matches_wordpress() { [ -n "$(links < "$S/current/$1index.html")" ] && [ "$(links < "$S/current/$1index.html")" = "$(curl -s "$B/$1?sbq=1" | links)" ]; }

[ -L "$S/current" ] || { echo "Run run-e2e.sh first (no published release)."; exit 2; }
: > "$E/logs/php-errors.log"

echo "== Full export: every archive, linked or not"
SEED=$($PHP84 "$H/wp69-harness.php" archives seed); [ "$(echo "$SEED" | jget result)" = "PASS" ]; ok $? "seed: orphan category of 11 posts, empty category, parent/child, Query Loop page"
AUTHOR=$(echo "$SEED" | jget author); LIST_ID=$(echo "$SEED" | jget list)
F=$(export_final); [ "$(echo "$F" | jget status)" = "complete" ]; ok $? "full export completes" "$F"
MISSING=""
for route in category/sbqa-orfana/ category/sbqa-orfana/page/2/ category/sbqa-vuota/ category/sbqa-genitore/ category/sbqa-genitore/sbqa-figlia/ \
    2024/ 2024/page/2/ 2024/01/ 2024/01/page/2/ 2024/01/15/ 2024/01/15/page/2/ 2023/05/02/ "author/$AUTHOR/" "author/$AUTHOR/page/2/" page/2/ sbqa-elenco/; do
    has "$route" || MISSING="$MISSING $route"
done
[ -z "$MISSING" ]; ok $? "archives, their /page/N/ and the date archives are in the release" "missing:$MISSING"
! has category/sbqa-orfana/page/3/ && ! has page/3/; ok $? "no page past the last one"
[ "$(echo "$F" | jget skipped)" = "0" ]; ok $? "no URL reported as left out (a /page/N/ past the end is not an error)" "$F"
R=$(get /category/sbqa-vuota/); [ "$(echo "$R" | jget status)" = "200" ] && [ "$(echo "$R" | jget route)" = "static" ]; ok $? "an empty category no page links to is served static" "$R"
static_matches_wordpress category/sbqa-orfana/page/2/; ok $? "/category/…/page/2/ lists the same posts as WordPress"
if [ "$SBQA_WEB" = "nginx" ]; then
    # The export captured the site's security headers: the administrator copies the block again.
    $PHP84 "$H/wp69-harness.php" block > /dev/null && $NGX -t -p "$E/run" -c "$E/conf/nginx.conf" > /dev/null 2>&1 && $NGX -s reload -p "$E/run" -c "$E/conf/nginx.conf"
    ok $? "Nginx: block copied again after the export and reloaded"; sleep 0.5
fi
has tag/pdf/ && [ ! -e "$S/current/tag/unnamed-file.pdf" ]; ok $? "2.2.1: /tag/pdf/ is written to tag/pdf/, where the server looks"
R=$(get /tag/pdf/); [ "$(echo "$R" | jget status)" = "200" ] && [ "$(echo "$R" | jget route)" = "static" ]; ok $? "2.2.1: /tag/pdf/ is served static" "$R"
R=$(get /category/sbqa-vuota/); [ "$(echo "$R" | jget xfo)" = "SAMEORIGIN" ] && [ "$(echo "$R" | jget nosniff)" = "nosniff" ] && [ "$(echo "$R" | jget route)" = "static" ]; ok $? "2.2.1: a static page sends the site's security headers, once" "$R"

echo "== Strict: archives static, the rest to WordPress"
$PHP84 "$H/settings-form.php" strict_mode on > /dev/null && [ -f "$S/.strict" ]; ok $? "Strict on"
for path in /category/sbqa-orfana/ /category/sbqa-orfana/page/2/ /2024/01/15/ "/author/$AUTHOR/" /tag/pdf/; do
    R=$(get "$path"); [ "$(echo "$R" | jget status)" = "200" ] && [ "$(echo "$R" | jget route)" = "static" ]; ok $? "Strict: $path static 200" "$R"
done
for path in /rss2/ /category/sbqa-orfana/atom/ /search/sbqa/ /favicon.ico /category/sbqa-orfana; do
    R=$(get "$path"); [ "$(echo "$R" | jget status)" != "404" ] && [ "$(echo "$R" | jget route)" = "" ] && [ "$(echo "$R" | jget php)" = "1" ]; ok $? "Strict: $path answered by WordPress, not a 404" "$R"
done
R=$(get /category/sbqa-orfana); [ "$(echo "$R" | jget status)" = "301" ]; ok $? "Strict: /x without the slash gets WordPress's 301" "$R"
R=$(get /category/sbqa-non-esiste/); [ "$(echo "$R" | jget status)" = "404" ] && [ "$(echo "$R" | jget php)" = "" ]; ok $? "Strict: a missing route is still a 404 without PHP" "$R"
$PHP84 "$H/settings-form.php" strict_mode off > /dev/null && [ ! -e "$S/.strict" ]; ok $? "Strict off"

echo "== Incremental: every page of every list, Query Loop pages, unpublishing"
MK="SBQA_ARCH_$(date +%s%N)"; MKS=$(echo "$MK" | tr 'A-Z' 'a-z' | tr '_' '-')
NEW=$($PHP84 "$H/wp69-harness.php" archives publish "$MK"); NEW_ID=$(echo "$NEW" | jget post_id); [ "$(echo "$NEW" | jget result)" = "PASS" ]; ok $? "new post in the orphan category, dated 2024-01-15"
cron_until "grep -q '$MK' '$S/current/category/sbqa-orfana/index.html' 2>/dev/null && grep -q '$MK' '$S/current/sbqa-elenco/index.html' 2>/dev/null && static_matches_wordpress page/2/ && static_matches_wordpress category/sbqa-orfana/page/2/ && static_matches_wordpress 2024/01/15/page/2/"
ok $? "the post, its category, the Query Loop page and the shifted /page/2/ of home, category and day are refreshed"
for route in category/sbqa-orfana/page/2/ 2024/01/page/2/ "author/$AUTHOR/page/2/" page/2/; do
    static_matches_wordpress "$route"; ok $? "/$route lists the same posts as WordPress after the publication"
done
OFF=$($PHP84 "$H/wp69-harness.php" archives unpublish "$NEW_ID"); [ "$(echo "$OFF" | jget result)" = "PASS" ]; ok $? "the new post is moved back to draft"
cron_until "! grep -q '$MK' '$S/current/sbqa-elenco/index.html' && ! grep -q '$MK' '$S/current/category/sbqa-orfana/index.html' && [ ! -e '$S/current/$MKS/index.html' ]"
ok $? "unpublishing removes it from the Query Loop page, the category and the release"

echo "== Terms: new and renamed categories"
$PHP84 "$H/wp69-harness.php" archives term sbqa-nuova > /dev/null
cron_until "[ -f '$S/current/category/sbqa-nuova/index.html' ]"; ok $? "a new (empty) category is published by the incremental worker"
$PHP84 "$H/wp69-harness.php" archives rename sbqa-vuota sbqa-vuota-2 > /dev/null
cron_until "[ -f '$S/current/category/sbqa-vuota-2/index.html' ] && [ ! -e '$S/current/category/sbqa-vuota/index.html' ]"; ok $? "a renamed category moves its static route"

echo "== Second full export keeps what the incremental worker created"
F=$(export_final); [ "$(echo "$F" | jget status)" = "complete" ]; ok $? "second full export completes" "$F"
has category/sbqa-nuova/ && has category/sbqa-vuota-2/ && ! has category/sbqa-vuota/ && has category/sbqa-orfana/page/2/ && has 2024/01/15/; ok $? "archives created incrementally are still there; the renamed one is not back"

echo "== Logs"
N=$(grep -cE 'wp-content/plugins/static-bridge/|<code>static-bridge</code>' "$E/logs/php-errors.log"); [ "$N" = "0" ]; ok $? "no PHP notice/warning/error from the plugin ($N lines)"

echo
echo "ARCHIVES RESULT: $PASS passed, $FAIL failed — $SBQA_STACK"
exit $([ "$FAIL" = "0" ] && echo 0 || echo 1)
