#!/usr/bin/env bash
# Plugins > Delete for Static Bridge on the disposable stack selected by SBQA_STACK.
# Run AFTER run-e2e.sh on the same ZIP (plugin active, releases published).
# Usage: [SBQA_STACK=apache|nginx71] bash uninstall-e2e.sh
set -u
H=$(cd "$(dirname "$0")" && pwd)
. "$H/profile.sh"
sbqa_require_stack; E=$SBQA_E
PHP84="env LD_LIBRARY_PATH=$P/shared-libs $P/bin/php -n -c $E/conf/php.ini"
PASS=0; FAIL=0
ok() { if [ "$1" = "0" ]; then PASS=$((PASS+1)); echo "[PASS] $2"; else FAIL=$((FAIL+1)); echo "[FAIL] $2${3:+ :: $3}"; fi; }
jget() { php -r '$j=json_decode(stream_get_contents(STDIN),true); $v=$j[$argv[1]] ?? null; echo is_scalar($v) ? var_export($v, true) : json_encode($v);' "$1"; }

: > "$E/logs/php-errors.log"
BEFORE=$($PHP84 "$H/uninstall-e2e.php" before)
[ "$(echo "$BEFORE" | jget plugin_active)" = "true" ] && [ "$(echo "$BEFORE" | jget static_root)" = "true" ] && [ "$(echo "$BEFORE" | jget options)" != "0" ]; ok $? "before: plugin active with options and a static root ($BEFORE)"
DEL=$($PHP84 "$H/uninstall-e2e.php" delete); ok $? "WordPress delete_plugins() deactivated and deleted the plugin ($DEL)"
AFTER=$($PHP84 "$H/uninstall-e2e.php" after); echo "   after: $AFTER"
[ "$(echo "$AFTER" | jget plugin_files)" = "false" ]; ok $? "plugin files removed"
[ "$(echo "$AFTER" | jget options)" = "0" ]; ok $? "no static_bridge_ option or transient left"
[ "$(echo "$AFTER" | jget tables)" = "[]" ]; ok $? "no static_bridge tables left"
[ "$(echo "$AFTER" | jget cron_hooks)" = "[]" ]; ok $? "no static_bridge cron event left (including a stale incremental one)"
[ "$(echo "$AFTER" | jget static_root)" = "false" ]; ok $? "wp-content/static-bridge removed"
[ "$(echo "$AFTER" | jget htaccess_static_bridge_blocks)" = "0" ]; ok $? "no Static Bridge block left in .htaccess"
if [ "$SBQA_WEB" = "apache" ]; then
    [ "$(echo "$AFTER" | jget htaccess_wordpress_block)" = "true" ]; ok $? "WordPress .htaccess block kept"
fi
php "$H/routing-check.php" expect-wordpress > "$E/run/uninstall-routing.txt"; ok $? "every route served by WordPress after deletion ($(tail -1 "$E/run/uninstall-routing.txt"))"
for path in / /hello-world/ /sample-page/; do
    [ "$(curl -s -o /dev/null -w '%{http_code}' "$B$path")" = "200" ]; ok $? "GET $path still 200 after deletion"
done
N=$(grep -cE 'wp-content/plugins/static-bridge/|<code>static-bridge</code>' "$E/logs/php-errors.log"); [ "$N" = "0" ]; ok $? "no PHP message from the plugin during deletion ($N)"
echo
echo "UNINSTALL E2E ($SBQA_STACK): $PASS passed, $FAIL failed"
[ "$FAIL" = "0" ]
