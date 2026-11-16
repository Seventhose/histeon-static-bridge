#!/usr/bin/env bash
# WooCommerce on static pages, end to end, on one disposable stack (SBQA_STACK=wc-nginx|wc-apache):
# $SBQA_HOME/wpwc (Nginx :18741) or $SBQA_HOME/wpwca (Apache :18743). Nothing else is touched.
#
# Resets the stack to its clean DB baseline, installs the ZIP, activates WooCommerce 11.2.2 (copied read only into
# the stack by setup-stack.sh) and builds a small shop (wc-qa.php setup), with the Store API nonce living 60 s
# (QA mu-plugin) so that the nonces in the exported HTML really expire. Then:
#   - the Diagnostica warns while the integration is off; the switch is turned on through the real settings form;
#   - export: catalog static (shop and its pages, categories, tags, products, variable and grouped products), cart,
#     checkout and account with WordPress, /wc-api/ answered by WooCommerce also with Strict, Store API, search,
#     filters, sorting, ?add-to-cart= and variation links dynamic, a visitor with a cart always on WordPress;
#   - only the page with the Store API nonce gets the refresher; no session data or render facts in any copy;
#   - control: after 60 s WordPress rejects the Store API nonce of the static page; a real headless Chrome adds to
#     the cart from that static page anyway, and from the shop (block button), a product page (form), a variable
#     product, the AJAX list; then a full block checkout with cash on delivery creates the order;
#   - the order lowers the stock: only the product pages are refreshed; a price, a sale and an "out of stock"
#     refresh the product, the shop, the category and the tag pages, and the static copies show the new values;
#   - a store setting on every page refreshes every route; a nonce Static Bridge cannot renew keeps that page with
#     WordPress, and it goes back to static when the nonce is gone; the Diagnostica checks every point;
#   - 2.4.1: Fluent Forms' fluentFormVars.nonce (no action, -1) in the footer of every page keeps no page dynamic and
#     the export completes; an old address that answers 301 is followed and not reported; the same -1 value printed
#     anywhere else still keeps that page with WordPress.
# Mail goes to run/mail-sink.log only; orders and sessions are deleted at the end.
# Usage: SBQA_STACK=wc-nginx|wc-apache [SBQA_PLUGIN_SOURCE=<plugin dir in the repo>] bash wc-e2e.sh <zip>
set -u
H=$(cd "$(dirname "$0")" && pwd)
. "$H/profile.sh"
sbqa_require_stack; E=$SBQA_E
[ "$SBQA_WC" = "1" ] || { echo "REFUSED: SBQA_STACK must be wc-nginx or wc-apache"; exit 2; }
export SBQA_PLUGIN_SOURCE=${SBQA_PLUGIN_SOURCE:-$SBQA_REPO/plugin}
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
qa() { $PHP84 "$H/wc-qa.php" "$@"; }
export_final() { php "$H/http-gui.php" administrator export | jget final; }
static_header() { curl -s -o /dev/null -D - "$@" | tr 'A-Z' 'a-z' | awk 'BEGIN { n = 0 } /^(x-static-bridge-route: static|x-sbqa-apache-file: static-bridge)/ { n = 1 } END { print n }'; }
code() { curl -s -o /dev/null -w '%{http_code}' "$@"; }
nginx_block() { [ "$SBQA_WEB" = "nginx" ] && $PHP84 "$H/wp69-harness.php" block > /dev/null && $NGX -s reload -p "$E/run" -c "$E/conf/nginx.conf" && sleep 0.5; return 0; }
diag() { php "$H/http-gui.php" administrator diagnose > "$E/run/wc-diag.json"; php -r '$j=json_decode(file_get_contents($argv[1]),true); foreach($j["results"] as $r){ if($r["id"]===$argv[2]){ echo $r["status"]; } }' "$E/run/wc-diag.json" "$1"; }
diag_all() { php -r '$j=json_decode(file_get_contents($argv[1]),true); $o=array(); foreach($j["results"] as $r){ if(0===strpos($r["id"],"woocommerce")) $o[]=$r["id"]."=".$r["status"]; } echo implode(" ",$o);' "$E/run/wc-diag.json"; }
queued() { qa queue | jget paths; }
in_page() { grep -c -- "$2" "$S/current$1index.html" 2>/dev/null; }
browser() { node "$H/wc-browser.js" "$B" "$@"; }
check() { php -r '$j=json_decode(stream_get_contents(STDIN),true); $ok=(function($j){ return '"$1"'; })($j); exit($ok?0:1);'; }

case "$ZIP" in /*) ;; *) ZIP="$PWD/$ZIP" ;; esac
[ -f "$ZIP" ] || { echo "Usage: bash wc-e2e.sh <zip>"; exit 2; }
[ "$(code --max-time 5 $B/wp-login.php)" != "000" ] || { echo "Stack not running on $B"; exit 2; }
case "$S" in "$SBQA_HOME/$SBQA_ENV_NAME/wordpress/wp-content/static-bridge") ;; *) echo "REFUSED: unexpected static root $S"; exit 2 ;; esac
[ -f "$WP/wp-content/plugins/woocommerce/woocommerce.php" ] || { echo "REFUSED: WooCommerce is not in the stack (rebuild it with setup-stack.sh $SBQA_STACK)"; exit 2; }

echo "== Reset $SBQA_ENV_NAME to its clean baseline, install $(basename "$ZIP") ($(sha256sum "$ZIP" | cut -c1-16)…)"
rm -f "$E/run/fault.json" "$E/run/wc-"* "$E/run/mail-sink.log"
$MYSQL -e "DROP DATABASE IF EXISTS $SBQA_DB; CREATE DATABASE $SBQA_DB CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_520_ci;"
$MYSQL $SBQA_DB < "$E/backups/00-baseline-before-activation.sql"
rm -rf "$S" "$WP/wp-content/plugins/static-bridge" "$WP/wp-content/uploads/wc-logs" "$WP/wp-content/uploads/woocommerce_uploads"
[ "$SBQA_WEB" = "apache" ] && rm -f "$WP/.htaccess"
unzip -q "$ZIP" -d "$WP/wp-content/plugins/"
# The QA mu-plugin of this checkout (2.4.1 added run/wc-ff-footer), not the copy made when the stack was built.
cp "$H/mu-plugins/sbqa-woocommerce-qa.php" "$WP/wp-content/mu-plugins/sbqa-woocommerce-qa.php"
diff -rq -x LICENSE "$SBQA_PLUGIN_SOURCE" "$WP/wp-content/plugins/static-bridge" > /dev/null; ok $? "installed ZIP is byte-identical to the plugin source"
if [ "$SBQA_WEB" = "nginx" ]; then
    echo 'location / { try_files $uri $uri/ /index.php$is_args$args; }' > "$E/conf/static-bridge.conf"
    $NGX -s reload -p "$E/run" -c "$E/conf/nginx.conf"; sleep 0.5
fi
: > "$E/logs/php-errors.log"
R=$($PHP84 "$H/wp69-harness.php" activate); [ "$(echo "$R" | jget result)" = "PASS" ]; ok $? "Static Bridge $(echo "$R" | jget version) activated"
[ "$SBQA_WEB" = "nginx" ] && { $NGX -s reload -p "$E/run" -c "$E/conf/nginx.conf"; sleep 0.5; }
$PHP84 "$H/wp69-harness.php" session administrator > /dev/null
R=$(qa activate); [ "$(echo "$R" | jget result)" = "PASS" ]; ok $? "WooCommerce $(echo "$R" | jget version) activated" "$R"
SETUP=$(qa setup); [ "$(echo "$SETUP" | jget result)" = "PASS" ]; ok $? "shop built: 18 + 1 simple products, a variable product with two variations, a grouped product, /vetrina/, /catalogo-blocchi/" "$SETUP"
echo "$LIFE" > "$E/run/wc-nonce-life"
echo "   QA: Store API nonce lives ${LIFE}s; COD and bank transfer on; shipping off; mail sink only"
SIMPLE=$(echo "$SETUP" | jget product); VARIABLE=$(echo "$SETUP" | jget variable); GROUPED=$(echo "$SETUP" | jget grouped)
CATEGORY=$(echo "$SETUP" | jget category); TAG=$(echo "$SETUP" | jget tag); SIMPLE_ID=$(echo "$SETUP" | jget ids.SBQA-SIMPLE)

curl -s -H @"$E/run/cookie-administrator.txt" "$B/wp-admin/admin.php?page=static-bridge&tab=settings" | grep -oE '<input[^>]*id="sb-woocommerce-enabled"[^>]*>' | grep -q 'checked'
ok $? "with WooCommerce active the integration is on by default (never saved)"
R=$($PHP84 "$H/settings-form.php" woocommerce_enabled on); [ "$(echo "$R" | jget http)" = "302" ]; ok $? "integration confirmed through the real settings form" "$R"

echo "== Export: the catalog is static, the transactional pages stay with WordPress"
F=$(export_final); EXPORTED_AT=$(date +%s)
[ "$(echo "$F" | jget status)" = "complete" ] && [ "$(echo "$F" | jget skipped)" = "0" ]; ok $? "full export completes, no route left dynamic ($(echo "$F" | jget saved) saved)" "$F"
nginx_block
for p in / /shop/ /shop/page/2/ "$CATEGORY" "$CATEGORY"page/2/ "$TAG" "$SIMPLE" "$VARIABLE" "$GROUPED" /vetrina/ /catalogo-blocchi/; do
    [ -f "$S/current${p}index.html" ] && [ "$(static_header "$B$p")" = "1" ]; ok $? "static: $p"
done
for p in /cart/ /checkout/ /my-account/ /my-account/lost-password/; do
    [ ! -e "$S/current$p" ] && [ "$(static_header "$B$p")" = "0" ] && [ "$(code "$B$p")" != "404" ]; ok $? "WordPress: $p (HTTP $(code "$B$p"), not in the release)"
done
BODY=$(curl -s "$B/wc-api/sbqa-$RUN_ID/"); [ "$BODY" = "-1" ] && [ "$(static_header "$B/wc-api/sbqa-$RUN_ID/")" = "0" ]; ok $? "/wc-api/ reaches WooCommerce (\"$BODY\", HTTP $(code "$B/wc-api/sbqa-$RUN_ID/"))"
CART=$(curl -s -D "$E/run/wc-h.txt" "$B/wp-json/wc/store/v1/cart"); echo "$CART" | check 'is_array($j) && array_key_exists("items", $j)' && grep -qi '^nonce:' "$E/run/wc-h.txt" && [ "$(static_header "$B/wp-json/wc/store/v1/cart")" = "0" ]
ok $? "Store API answers from WordPress with a Nonce header"
for q in "/shop/?orderby=price" "/shop/?orderby=price-desc" "/?s=Capsule&post_type=product" "$VARIABLE?attribute_colore=Blu" "$CATEGORY?orderby=popularity" "/shop/?min_price=5&max_price=9"; do
    [ "$(static_header "$B$q")" = "0" ] && [ "$(code "$B$q")" = "200" ]; ok $? "dynamic with WordPress: $q"
done
FIRST=$(curl -s "$B/shop/?orderby=price-desc" | grep -oE 'Capsule QA [0-9]+|Miscela Arabica QA' | head -1); [ "$FIRST" = "Capsule QA 18" ]; ok $? "sorting by price (descending) is WordPress's: first $FIRST"
curl -sL "$B/?s=Miscela&post_type=product" | grep -q 'Miscela Arabica QA'; ok $? "product search answers from WordPress"
[ "$(static_header -H 'Cookie: woocommerce_items_in_cart=1' "$B/shop/")" = "0" ] && [ "$(static_header -H 'Cookie: wp_woocommerce_session_x=1' "$B$SIMPLE")" = "0" ]; ok $? "a visitor with a cart gets WordPress's pages"
R=$(code -H 'Cookie: sbqa=1' "$B/?add-to-cart=$SIMPLE_ID"); [ "$R" != "404" ] && [ "$(static_header "$B/?add-to-cart=$SIMPLE_ID")" = "0" ]; ok $? "?add-to-cart= reaches WordPress (HTTP $R)"
$PHP84 "$H/settings-form.php" strict_mode on > /dev/null && nginx_block
[ "$(curl -s "$B/wc-api/sbqa-$RUN_ID/")" = "-1" ] && [ "$(code "$B/sbqa-missing-$RUN_ID/")" = "404" ] && [ "$(static_header "$B/cart/")" = "0" ] && [ "$(code "$B/cart/")" = "200" ]; ok $? "Strict: /wc-api/ and the cart still reach WordPress, an unknown route answers 404"
$PHP84 "$H/settings-form.php" strict_mode off > /dev/null && nginx_block

echo "== Nonces in the copies"
[ "$(in_page /catalogo-blocchi/ 'id="static-bridge-woocommerce"')" = "1" ]; ok $? "the All Products page carries the refresher once"
N=0; for p in / /shop/ "$SIMPLE" "$VARIABLE" /vetrina/; do [ "$(in_page "$p" 'static-bridge-woocommerce')" = "0" ] || N=$((N+1)); done; [ "$N" = "0" ]; ok $? "catalog pages without nonces get no script"
[ -z "$(grep -rlE 'static-bridge-facts|static_bridge_render|wp_woocommerce_session_|woocommerce_cart_hash=' "$S/current/" --include=index.html)" ]; ok $? "no render facts, render token or cart session in any copy"
R=$(env LD_LIBRARY_PATH="$P/shared-libs" "$P/bin/php" -n "$H/html-leak-scan.php" "$S/releases"); [ "$(echo "$R" | jget critical)" = "0" ]; ok $? "session-data scan: $(echo "$R" | jget pages_scanned) pages, $(echo "$R" | jget critical) critical"
HDRS=$(mktemp); FRESH=$(curl -s -D "$HDRS" -X POST "$B/wp-admin/admin-ajax.php" --data 'action=static_bridge_public_woocommerce_nonces')
[ "$(echo "$FRESH" | jget success)" = "true" ] && [ -n "$(echo "$FRESH" | jget data.store_api)" ] && grep -qi '^cache-control:.*no-store' "$HDRS"; ok $? "the public endpoint answers fresh Store API and REST nonces with no-store"; rm -f "$HDRS"
STALE=$(grep -oE 'wc_store_api&quot;:&quot;[0-9a-f]+' "$S/current/catalogo-blocchi/index.html" | grep -oE '[0-9a-f]{10}$')

echo "== Real browser, anonymous visitors, from the static pages"
R=$(browser shop); echo "$R" > "$E/run/wc-shop.json"
echo "$R" | check '!empty($j["document"]["static"]) && 207 === ($j["adds"][0]["status"] ?? 0) && in_array("woocommerce_items_in_cart", $j["cookies"] ?? array(), true) && empty($j["nextDocument"]["static"]) && preg_grep("/1 in cart/", $j["buttonText"] ?? array())'
ok $? "shop (block button, Interactivity API): added through the Store API, the next page comes from WordPress" "$(echo "$R" | head -c 400)"
R=$(browser product); echo "$R" | check '!empty($j["document"]["static"]) && "POST" === ($j["post"]["method"] ?? "") && empty($j["post"]["static"]) && false !== strpos($j["notice"] ?? "", "2 ×") && empty($j["nextDocument"]["static"]) && "2" === ($j["miniCount"] ?? "")'
ok $? "simple product page (form): quantity 2 in the cart, then WordPress's pages with the mini-cart at 2" "$(echo "$R" | head -c 400)"
R=$(browser variable); echo "$R" | check '!empty($j["document"]["static"]) && false !== strpos($j["variationPrice"] ?? "", "9,00") && false !== strpos($j["notice"] ?? "", "Tazza QA") && in_array("woocommerce_items_in_cart", $j["cookies"] ?? array(), true)'
ok $? "variable product page: colour Blu (9,00 €), added" "$(echo "$R" | head -c 400)"
R=$(browser ajax); echo "$R" | check '!empty($j["document"]["static"]) && 200 === ($j["adds"][0]["status"] ?? 0) && "1" === ($j["miniCount"] ?? "")'
ok $? "AJAX add to cart from /vetrina/ (?wc-ajax=add_to_cart), mini-cart at 1" "$(echo "$R" | head -c 400)"
WAIT=$((EXPORTED_AT + LIFE + 8 - $(date +%s))); [ "$WAIT" -gt 0 ] && { echo "   waiting ${WAIT}s for the Store API nonce of the static pages to expire…"; sleep "$WAIT"; }
CTRL=$(curl -s -X POST -H 'Content-Type: application/json' -H "Nonce: $STALE" -d "{\"id\":$SIMPLE_ID,\"quantity\":1}" "$B/wp-json/wc/store/v1/cart/add-item")
[ "$(echo "$CTRL" | jget code)" = "woocommerce_rest_invalid_nonce" ]; ok $? "control: after ${LIFE}s WordPress refuses the Store API nonce of the static page ($STALE)" "$CTRL"
R=$(browser blocks); echo "$R" > "$E/run/wc-blocks.json"
echo "$R" | STALE=$STALE check '!empty($j["document"]["static"]) && ($j["page"]["stale"]["wc_store_api"] ?? "") === getenv("STALE") && 1 === count($j["refresh"] ?? array()) && !empty($j["adds"]) && 207 === $j["adds"][0]["status"] && in_array(201, $j["adds"][0]["inner"] ?? array(), true) && $j["adds"][0]["nonce"] !== getenv("STALE") && $j["adds"][0]["bodyNonce"] === $j["adds"][0]["nonce"] && in_array("woocommerce_items_in_cart", $j["cookies"] ?? array(), true)'
ok $? "All Products page, nonce expired: one refresh, the Store API batch leaves with a fresh nonce (header and body) and adds the product (201)" "$(echo "$R" | head -c 600)"

echo "== Checkout to the order, from a static product page"
R=$(browser checkout "ordine-$RUN_ID@example.invalid"); echo "$R" > "$E/run/wc-checkout.json"
echo "$R" | check '!empty($j["document"]["static"]) && empty($j["checkoutDocument"]["static"]) && 200 === ($j["received"]["status"] ?? 0) && empty($j["received"]["static"]) && false !== strpos($j["received"]["url"] ?? "", "/checkout/order-received/") && false !== stripos($j["receivedText"] ?? "", "received") && array() === ($j["errors"] ?? array("?"))'
ok $? "block checkout with cash on delivery: order received page from WordPress" "$(echo "$R" | head -c 600)"
INS=$(qa inspect); echo "$INS" | RUN_ID=$RUN_ID check '($j["orders"][0]["email"] ?? "") === "ordine-" . getenv("RUN_ID") . "@example.invalid" && "cod" === $j["orders"][0]["method"] && in_array($j["orders"][0]["status"], array("processing", "on-hold"), true) && 19 === $j["stock"]["simple"]'
ok $? "WooCommerce stored the order (COD, $(echo "$INS" | jget orders.0.status), $(echo "$INS" | jget orders.0.total) €) and lowered the stock to $(echo "$INS" | jget stock.simple)" "$INS"
Q=$(queued); echo "$Q" | SIMPLE=$SIMPLE GROUPED=$GROUPED check 'in_array(getenv("SIMPLE"), $j, true) && in_array(getenv("GROUPED"), $j, true) && !in_array("/shop/", $j, true)'
ok $? "the stock change of the order queued the product page and the grouped product, not the lists: $Q"
qa worker > /dev/null
[ "$(in_page "$SIMPLE" '19 in stock')" -ge 1 ] && [ "$(static_header "$B$SIMPLE")" = "1" ]; ok $? "the static product page shows the new stock (19 in stock)"

echo "== Catalog changes from the data stores (REST, imports, scheduled sales go through the same save)"
R=$(qa price SBQA-SIMPLE 13.90); Q=$(queued)
echo "$Q" | SIMPLE=$SIMPLE CATEGORY=$CATEGORY TAG=$TAG GROUPED=$GROUPED check 'array() === array_diff(array(getenv("SIMPLE"), getenv("GROUPED"), "/shop/", "/shop/page/2/", getenv("CATEGORY"), getenv("TAG"), "/", "/vetrina/", "/catalogo-blocchi/"), $j)'
ok $? "a price change queued the product, the grouped product, the shop with its pages, category, tag, home and the pages with products: $Q"
qa worker > /dev/null
PRICE_PAGE=$(grep -l 'Miscela Arabica QA' "$S/current/shop/index.html" "$S/current/shop/page/2/index.html" 2>/dev/null | head -1)
[ "$(in_page "$SIMPLE" '13,90')" -ge 1 ] && [ -n "$PRICE_PAGE" ] && grep -q '13,90' "$PRICE_PAGE" && [ "$(in_page "$CATEGORY" '13,90')$(in_page "$CATEGORY"page/2/ '13,90')" != "00" ] && [ "$(static_header "$B/shop/")" = "1" ]
ok $? "static product, shop and category pages show 13,90 €"
qa sale SBQA-VAR-ROSSO 6.40 > /dev/null; qa worker > /dev/null
[ "$(in_page "$VARIABLE" '6,40')" -ge 1 ] && grep -q '6,40' "$S/current/shop/index.html" "$S/current/shop/page/2/index.html"; ok $? "a sale on a variation reaches the variable product page and the shop (6,40 €)"
qa stock SBQA-VAR-BLU 3 > /dev/null; Q=$(queued); echo "$Q" | VARIABLE=$VARIABLE check 'in_array(getenv("VARIABLE"), $j, true) && in_array("/shop/", $j, true)'
ok $? "a variation out of stock queued the variable product and the lists: $Q"
qa worker > /dev/null
grep -oE 'SBQA-VAR-BLU[^}]*' "$S/current${VARIABLE}index.html" | grep -q '&quot;is_in_stock&quot;:false' || grep -q 'Out of stock' "$S/current${VARIABLE}index.html"; ok $? "the static variable product page shows Blu out of stock"

echo "== Store settings on every page"
qa option woocommerce_currency_pos left_space > /dev/null; Q=$(qa inspect | jget queue)
[ "$Q" -ge 30 ]; ok $? "the currency position queued every route once ($Q)"
qa worker > /dev/null; grep -q '€&nbsp;13,90\|€ 13,90\|&euro;&nbsp;13,90' "$S/current${SIMPLE}index.html" || grep -q 'currencySymbol[^<]*>&euro;</span>&nbsp;13,90' "$S/current${SIMPLE}index.html"; ok $? "the static product page shows the symbol before the price"

echo "== A nonce Static Bridge cannot renew keeps that page with WordPress"
touch "$E/run/wc-foreign-nonce"
qa price SBQA-FILL-01 3.50 > /dev/null; qa worker > /dev/null; nginx_block
FILL=/product/capsule-qa-01/
INS=$(qa inspect); echo "$INS" | FILL=$FILL check 'isset($j["woocommerce_dynamic"][getenv("FILL")]) && in_array("woocommerce-sbqa-probe", $j["woocommerce_dynamic"][getenv("FILL")]["actions"], true)'
ok $? "the product page with the foreign nonce is recorded with its action" "$(echo "$INS" | jget woocommerce_dynamic)"
[ ! -e "$S/current${FILL}index.html" ] && [ "$(static_header "$B$FILL")" = "0" ] && [ "$(code "$B$FILL")" = "200" ]; ok $? "its stale copy is gone and WordPress answers it"
D=$(diag woocommerce-dynamic-pages); [ "$D" = "warning" ]; ok $? "Diagnostica names it ($D)"
rm -f "$E/run/wc-foreign-nonce"
qa price SBQA-FILL-01 3.60 > /dev/null; qa worker > /dev/null; nginx_block
[ -f "$S/current${FILL}index.html" ] && [ "$(static_header "$B$FILL")" = "1" ] && [ "$(qa inspect | jget woocommerce_dynamic)" = "[]" ]; ok $? "without the nonce the page is static again and the list is empty"

echo "== Fluent Forms in the footer of every page and an old address that redirects (2.4.1)"
touch "$E/run/wc-ff-footer"
F=$(export_final); nginx_block
[ "$(echo "$F" | jget status)" = "complete" ] && [ "$(echo "$F" | jget skipped)" = "0" ]; ok $? "full export with fluentFormVars.nonce (-1) on every page completes, no route left dynamic ($(echo "$F" | jget saved) saved)" "$F"
for p in / /shop/ "$CATEGORY" "$TAG" "$SIMPLE" /catalogo-blocchi/; do
    [ "$(in_page "$p" 'var fluentFormVars')" = "1" ] && [ "$(static_header "$B$p")" = "1" ]; ok $? "static with Fluent Forms' -1 nonce in the footer: $p"
done
[ "$(qa inspect | jget woocommerce_dynamic)" = "[]" ]; ok $? "no route recorded as dynamic for the -1 nonce"
php -r '$m=json_decode(file_get_contents($argv[1]),true); $hit=array_filter((array)($m["issues"]??array()), fn($i)=>false!==strpos(json_encode($i), "sbqa-vecchio-indirizzo")); exit((int)($m["redirects_followed"]??0) >= 1 && empty($hit) && !file_exists(dirname($argv[1])."/sbqa-vecchio-indirizzo/index.html") ? 0 : 1);' "$S/current/manifest.json"
ok $? "the old address answering 301 was followed, counted ($(php -r 'echo json_decode(file_get_contents($argv[1]),true)["redirects_followed"]??"?";' "$S/current/manifest.json")) and not listed among the problems"
echo elsewhere > "$E/run/wc-ff-footer"
qa price SBQA-FILL-01 3.70 > /dev/null; qa worker > /dev/null; nginx_block
INS=$(qa inspect); echo "$INS" | FILL=$FILL check 'isset($j["woocommerce_dynamic"][getenv("FILL")]) && array("-1") === $j["woocommerce_dynamic"][getenv("FILL")]["actions"]'
ok $? "the same -1 value printed outside fluentFormVars keeps the product page with WordPress" "$(echo "$INS" | jget woocommerce_dynamic)"
rm -f "$E/run/wc-ff-footer"
qa price SBQA-FILL-01 3.60 > /dev/null; qa worker > /dev/null; nginx_block
[ -f "$S/current${FILL}index.html" ] && [ "$(static_header "$B$FILL")" = "1" ] && [ "$(qa inspect | jget woocommerce_dynamic)" = "[]" ]; ok $? "without it the page is static again"

echo "== Diagnostica"
diag woocommerce-settings > /dev/null; A=$(diag_all)
[ -z "$(echo "$A" | tr ' ' '\n' | grep -E '=(fail|warning)')" ] && echo "$A" | grep -q 'woocommerce-endpoints=pass' && echo "$A" | grep -q 'woocommerce-store-api=pass' && echo "$A" | grep -q 'woocommerce-catalog=pass'
ok $? "every WooCommerce step passes: $A"

qa termmeta > /dev/null; Q=$(queued); echo "$Q" | CATEGORY=$CATEGORY check 'in_array(getenv("CATEGORY"), $j, true) && in_array("/shop/", $j, true) && in_array("/", $j, true)'
ok $? "a category display type (term meta) queued the category, the shop and the home: $Q"; qa worker > /dev/null

R=$($PHP84 "$H/settings-form.php" woocommerce_enabled off); nginx_block; D=$(diag woocommerce-disabled)
[ "$D" = "warning" ]; ok $? "Diagnostica: WooCommerce active with the integration switched off is a warning ($D)"
[ "$(static_header "$B/cart/")" = "0" ] && [ "$(curl -s "$B/wc-api/sbqa-$RUN_ID/")" = "-1" ] && [ -z "$(grep -lE 'woocommerce-cart|wc-block-cart' "$S/current/cart/index.html" 2>/dev/null)" ]
ok $? "switched off on purpose: the cart and /wc-api/ still reach WordPress"
$PHP84 "$H/settings-form.php" woocommerce_enabled on > /dev/null; nginx_block

echo "== Cleanup and logs"
R=$(qa cleanup); [ "$(echo "$R" | jget result)" = "PASS" ]; ok $? "QA orders deleted ($(echo "$R" | jget orders_deleted)) and sessions cleared"
echo "   mail sink: $([ -f "$E/run/mail-sink.log" ] && wc -l < "$E/run/mail-sink.log" || echo 0) message(s) captured, none sent"
cp "$E/run/wc-"*.json "$E/logs/" 2>/dev/null; rm -f "$E/run/wc-"*
NE=$(grep -cE 'wp-content/plugins/static-bridge/|PHP Fatal' "$E/logs/php-errors.log"); [ "$NE" = "0" ]; ok $? "no PHP notice, warning or fatal from Static Bridge ($NE lines)" "$(grep -E 'wp-content/plugins/static-bridge/|PHP Fatal' "$E/logs/php-errors.log" | head -3)"

echo
if [ "$SBQA_WEB" = "apache" ]; then WEBV=$($HTTPD -v | head -1 | cut -d' ' -f3); else WEBV=$($NGX -v 2>&1 | cut -d' ' -f3); fi
echo "WOOCOMMERCE RESULT: $PASS passed, $FAIL failed — $(basename "$ZIP") + WooCommerce on WordPress $(grep "wp_version =" "$WP/wp-includes/version.php" | grep -oE "[0-9.]+") / PHP $($PHP84 -r 'echo PHP_VERSION;') FPM / $WEBV"
exit $([ "$FAIL" = "0" ] && echo 0 || echo 1)
