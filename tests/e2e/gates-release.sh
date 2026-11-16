#!/usr/bin/env bash
# Every gate for one release ZIP, in order, on all disposable stacks (build them first with
# setup-stack.sh all). plugin/ must stay frozen while this runs: the harness refuses an installed
# plugin that differs from it. Logs go to $SBQA_HOME/logs/<version>/.
# Usage: bash tests/e2e/gates-release.sh dist/static-bridge-X.Y.Z.zip   (paths relative to the repo root)
set -u
ZIP=${1:?zip}
H=$(cd "$(dirname "$0")" && pwd)
. "$H/profile.sh"
cd "$SBQA_REPO"
case "$ZIP" in /*) ;; *) ZIP="$SBQA_REPO/$ZIP" ;; esac
[ -f "$ZIP" ] || { echo "Usage: bash tests/e2e/gates-release.sh <zip>"; exit 2; }
V=$(unzip -p "$ZIP" static-bridge/static-bridge.php | grep -m1 -oE "Version: [0-9.]+" | cut -d' ' -f2)
W=$SBQA_HOME/logs/$V; mkdir -p "$W"
for S in nginx apache nginx71 ff-nginx ff-apache wc-nginx wc-apache litespeed; do (SBQA_STACK=$S; . "$H/profile.sh"; sbqa_require_stack) || exit 2; done
FROZEN=$(sha256sum plugin/static-bridge.php | cut -d' ' -f1)
SUMMARY=()
note() { SUMMARY+=("$1"); echo "## $1"; }
frozen() { [ "$(sha256sum plugin/static-bridge.php | cut -d' ' -f1)" = "$FROZEN" ] || { echo "ABORT: plugin/ changed during the gates"; exit 3; }; }

echo "== Package $(basename "$ZIP") (version $V)"
T=$(mktemp -d); unzip -q "$ZIP" -d "$T"; diff -r -x LICENSE "$T/static-bridge" plugin > /dev/null && cmp -s "$T/static-bridge/LICENSE" LICENSE && note "ZIP == plugin/: PASS" || note "ZIP == plugin/: FAIL"; rm -rf "$T"
# Every committed ZIP other than this one is byte-identical to HEAD (this one may be new or rebuilt).
REL=${ZIP#"$SBQA_REPO"/}
git diff --quiet HEAD -- dist ":(exclude)$REL" && [ -z "$(git ls-files --others --exclude-standard dist | grep -vxF "$REL")" ] \
    && note "older ZIPs unchanged: PASS" || note "older ZIPs unchanged: FAIL"

echo "== Unit suites"
R=$(STATIC_BRIDGE_NGINX_BIN=$NGX STATIC_BRIDGE_APACHE_BIN="$APX/bin/httpd" LD_LIBRARY_PATH="$P/shared-libs" $P/bin/php -n -d memory_limit=512M tests/security/run.php > "$W/security-8.4.log" 2>&1; tail -1 "$W/security-8.4.log"); note "security PHP 8.4 + Nginx + Apache: $R"
R=$(STATIC_BRIDGE_NGINX_BIN=$NGX STATIC_BRIDGE_APACHE_BIN="$APX/bin/httpd" php -d error_reporting=E_ALL tests/security/run.php > "$W/security-$(php -r 'echo PHP_VERSION;').log" 2>&1; tail -1 "$W/security-$(php -r 'echo PHP_VERSION;').log"); note "security PHP $(php -r 'echo PHP_VERSION;') + Nginx + Apache: $R"
note "GUI: $(node tests/admin-ui.test.js 2>&1 | tail -1)"
[ -f tests/fluent-forms-static.test.js ] && note "Fluent Forms JS: $(node tests/fluent-forms-static.test.js 2>&1 | tail -1)"
note "WooCommerce JS: $(node tests/woocommerce-static.test.js 2>&1 | tail -1)"
[ -f tests/relative-dates.test.js ] && note "relative dates JS: $(node tests/relative-dates.test.js 2>&1 | tail -1)"
note "header fragments: $(LD_LIBRARY_PATH=$P/shared-libs $P/bin/php -n tests/v2/header-fragments-unit.php 2>&1 | tail -1)"
note "dependency index: $(LD_LIBRARY_PATH=$P/shared-libs $P/bin/php -n tests/v2/dependency-index-unit.php 2>&1 | tail -1)"
F=0; for f in $(find plugin tests -path tests/e2e/stacks -prune -o -name '*.php' -print); do LD_LIBRARY_PATH=$P/shared-libs $P/bin/php -n -l "$f" > /dev/null 2>&1 || F=$((F+1)); php -l "$f" > /dev/null 2>&1 || F=$((F+1)); done; note "lint PHP 8.4 + 8.5: $F failures"
J=0; for f in plugin/assets/*.js; do node --check "$f" || J=$((J+1)); done; note "node --check: $J failures"
# Rebuild cards (docs/ricostruire/): every rule of scripts/schede.mjs, and the merged file up to date. The public
# copy leaves the cards out, so the gate runs only where they are.
[ -f scripts/schede.mjs ] && note "schede: $(node scripts/schede.mjs controlla 2>&1 | tail -1)"
# Object-cache drop-in (2.3.0) against real Redis and Memcached in Docker; needs build-php-ext.sh once.
R=$(bash "$H/object-cache-e2e.sh" > "$W/object-cache-backend-$V.log" 2>&1; tail -1 "$W/object-cache-backend-$V.log"); note "object cache backend: $R"

for S in nginx apache nginx71; do
    echo "== Stack $S"
    SBQA_STACK=$S bash "$H/stack.sh" start > /dev/null
    frozen; R=$(SBQA_STACK=$S bash "$H/run-e2e.sh" "$ZIP" > "$W/e2e-$V-$S.log" 2>&1; grep 'E2E RESULT' "$W/e2e-$V-$S.log" | cut -d— -f1); note "$S run-e2e (with worker kill): $R"
    # No logged-in or per-session markup and no Static Bridge internals in any published page.
    LEAK_DIR=$(SBQA_STACK=$S; . "$H/profile.sh"; echo "$SBQA_HOME/$SBQA_ENV_NAME/wordpress/wp-content/static-bridge/releases")
    R=$(env LD_LIBRARY_PATH="$P/shared-libs" "$P/bin/php" -n "$H/html-leak-scan.php" "$LEAK_DIR" > "$W/leak-scan-$V-$S.json"; php -r '$j=json_decode(file_get_contents($argv[1]),true); echo $j["pages_scanned"]," pages, ",$j["critical"]," critical, ",$j["warnings"]," warnings";' "$W/leak-scan-$V-$S.json"); note "$S session-data scan: $R"
    if [ "$(SBQA_STACK=$S; . "$H/profile.sh"; echo "$SBQA_WEB")" = "nginx" ]; then
        # Typical mistakes in the hand-copied Nginx block must be caught by the Diagnostica.
        frozen; R=$(SBQA_STACK=$S bash "$H/nginx-misconfig-e2e.sh" > "$W/nginx-misconfig-$V-$S.log" 2>&1; tail -1 "$W/nginx-misconfig-$V-$S.log"); note "$S misconfigured Nginx block: $R"
    fi
    frozen; R=$(SBQA_STACK=$S bash "$H/retention-e2e.sh" > "$W/retention-e2e-$V-$S.log" 2>&1; grep 'RESULT' "$W/retention-e2e-$V-$S.log" | cut -d— -f1); note "$S retention: $R"
    # Server tab (2.3.0): .htaccess block on Apache, Nginx blocks and object cache on Nginx.
    frozen; R=$(SBQA_STACK=$S bash "$H/server-tab-e2e.sh" > "$W/server-tab-e2e-$V-$S.log" 2>&1; grep 'SERVER TAB E2E RESULT' "$W/server-tab-e2e-$V-$S.log"); note "$S server tab: $R"
    frozen; SBQA_STACK=$S bash "$H/run-e2e.sh" "$ZIP" --skip-kill > "$W/e2e-$V-$S-pre-cloudflare.log" 2>&1
    R=$(grep 'E2E RESULT' "$W/e2e-$V-$S-pre-cloudflare.log" | cut -d— -f1); note "$S run-e2e #2: $R"
    frozen; R=$(SBQA_STACK=$S bash "$H/cloudflare-e2e.sh" > "$W/cloudflare-e2e-$V-$S.log" 2>&1; tail -1 "$W/cloudflare-e2e-$V-$S.log"); note "$S cloudflare: $R"
    frozen; SBQA_STACK=$S bash "$H/run-e2e.sh" "$ZIP" --skip-kill > "$W/e2e-$V-$S-pre-uninstall.log" 2>&1
    R=$(grep 'E2E RESULT' "$W/e2e-$V-$S-pre-uninstall.log" | cut -d— -f1); note "$S run-e2e #3: $R"
    # Precompressed pages (2.3.0): the .gz served once compressed, refreshed and removed with its page.
    frozen; R=$(SBQA_STACK=$S bash "$H/precompressed-e2e.sh" > "$W/precompressed-e2e-$V-$S.log" 2>&1; grep 'PRECOMPRESSED RESULT' "$W/precompressed-e2e-$V-$S.log" | cut -d— -f1); note "$S precompressed pages: $R"
    # Readers during continuous pushes and exports (Apache publication race, 2.0.20): 3 runs on Apache.
    N=1; [ "$S" = "apache" ] && N=3
    frozen; R=$(SBQA_STACK=$S bash "$H/apache-race-e2e.sh" "$N" 40 2 > "$W/race-e2e-$V-$S.log" 2>&1; tail -1 "$W/race-e2e-$V-$S.log" | cut -d— -f1,2); note "$S publication race ($N x 40 s): $R"
    # Archives (2.2.0): it adds posts and categories, so it runs last before the uninstall.
    frozen; R=$(SBQA_STACK=$S bash "$H/archives-e2e.sh" > "$W/archives-e2e-$V-$S.log" 2>&1; grep 'ARCHIVES RESULT' "$W/archives-e2e-$V-$S.log" | cut -d— -f1); note "$S archives: $R"
    frozen; R=$(SBQA_STACK=$S bash "$H/uninstall-e2e.sh" > "$W/uninstall-e2e-$V-$S.log" 2>&1; tail -1 "$W/uninstall-e2e-$V-$S.log"); note "$S uninstall: $R"
    for f in "$W/e2e-$V-$S.log" "$W/e2e-$V-$S-pre-cloudflare.log" "$W/e2e-$V-$S-pre-uninstall.log"; do grep -h 'anonymous readers' "$f" | sed -E "s/.*(\{.*\}).*/   readers $S: \1/"; done
    SBQA_STACK=$S bash "$H/stack.sh" stop > /dev/null
done

echo "== Fluent Forms (ff-nginx, ff-apache stacks)"
for S in nginx apache; do
    SBQA_STACK=ff-$S bash "$H/stack.sh" start > /dev/null
    frozen; R=$(SBQA_STACK=ff-$S bash "$H/ff-e2e.sh" "$ZIP" > "$W/ff-e2e-$V-$S.log" 2>&1; grep 'RESULT' "$W/ff-e2e-$V-$S.log" | tail -1 | cut -d— -f1); note "fluent forms $S: $R"
    LEAK_DIR=$(SBQA_STACK=ff-$S; . "$H/profile.sh"; echo "$SBQA_HOME/$SBQA_ENV_NAME/wordpress/wp-content/static-bridge/releases")
    R=$(env LD_LIBRARY_PATH="$P/shared-libs" "$P/bin/php" -n "$H/html-leak-scan.php" "$LEAK_DIR" > "$W/leak-scan-$V-ff-$S.json"; php -r '$j=json_decode(file_get_contents($argv[1]),true); echo $j["pages_scanned"]," pages, ",$j["critical"]," critical, ",$j["warnings"]," warnings";' "$W/leak-scan-$V-ff-$S.json"); note "fluent forms $S session-data scan: $R"
    # Complianz (2.2.3): a real browser on the static page and real saves through Complianz's REST routes. It resets the stack.
    frozen; R=$(SBQA_STACK=ff-$S bash "$H/complianz-e2e.sh" "$ZIP" > "$W/complianz-e2e-$V-$S.log" 2>&1; grep 'COMPLIANZ RESULT' "$W/complianz-e2e-$V-$S.log" | cut -d— -f1); note "complianz $S: $R"
    SBQA_STACK=ff-$S bash "$H/stack.sh" stop > /dev/null
done

echo "== WooCommerce (wc-nginx, wc-apache stacks: WordPress 7.1.3 + WooCommerce 11.2.2)"
for S in nginx apache; do
    SBQA_STACK=wc-$S bash "$H/stack.sh" start > /dev/null
    frozen; R=$(SBQA_STACK=wc-$S bash "$H/wc-e2e.sh" "$ZIP" > "$W/wc-e2e-$V-$S.log" 2>&1; grep 'WOOCOMMERCE RESULT' "$W/wc-e2e-$V-$S.log" | cut -d— -f1); note "woocommerce $S: $R"
    LEAK_DIR=$(SBQA_STACK=wc-$S; . "$H/profile.sh"; echo "$SBQA_HOME/$SBQA_ENV_NAME/wordpress/wp-content/static-bridge/releases")
    R=$(env LD_LIBRARY_PATH="$P/shared-libs" "$P/bin/php" -n "$H/html-leak-scan.php" "$LEAK_DIR" > "$W/leak-scan-$V-wc-$S.json"; php -r '$j=json_decode(file_get_contents($argv[1]),true); echo $j["pages_scanned"]," pages, ",$j["critical"]," critical, ",$j["warnings"]," warnings";' "$W/leak-scan-$V-wc-$S.json"); note "woocommerce $S session-data scan: $R"
    SBQA_STACK=wc-$S bash "$H/stack.sh" stop > /dev/null
done

echo "== OpenLiteSpeed (litespeed stack: OpenLiteSpeed 1.9.4 in Docker, WordPress 6.9)"
SBQA_STACK=litespeed bash "$H/stack.sh" start > /dev/null
frozen; R=$(SBQA_STACK=litespeed bash "$H/litespeed-e2e.sh" "$ZIP" > "$W/litespeed-e2e-$V.log" 2>&1; grep 'LITESPEED RESULT' "$W/litespeed-e2e-$V.log" | cut -d— -f1); note "openlitespeed: $R"
LEAK_DIR=$(SBQA_STACK=litespeed; . "$H/profile.sh"; echo "$SBQA_HOME/$SBQA_ENV_NAME/wordpress/wp-content/static-bridge/releases")
R=$(env LD_LIBRARY_PATH="$P/shared-libs" "$P/bin/php" -n "$H/html-leak-scan.php" "$LEAK_DIR" > "$W/leak-scan-$V-litespeed.json"; php -r '$j=json_decode(file_get_contents($argv[1]),true); echo $j["pages_scanned"]," pages, ",$j["critical"]," critical, ",$j["warnings"]," warnings";' "$W/leak-scan-$V-litespeed.json"); note "openlitespeed session-data scan: $R"
SBQA_STACK=litespeed bash "$H/stack.sh" stop > /dev/null

echo; echo "===== SUMMARY $REL ($(sha256sum "$ZIP" | cut -c1-16)…)"; printf '%s\n' "${SUMMARY[@]}" | tee "$W/gates-$V.summary"
# Exit code for kit prova piena: 1 when a line of the summary is red (a FAIL, a non-zero failed, failures, critical,
# errors or not-caught count) or has no result at all.
RED=$(printf '%s\n' "${SUMMARY[@]}" | grep -E ': FAIL$|(^|[^0-9])[1-9][0-9]* (failed|FAIL|failures|critical|errori|not caught)|: *$' || true)
[ -z "$RED" ] || { echo; echo "===== RED LINES"; echo "$RED"; exit 1; }
