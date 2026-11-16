#!/usr/bin/env bash
# Reproduces "single-post push right after a full export does not reach the
# public page on the disposable PHP-FPM stack (SBQA_STACK: Nginx or Apache). Every FPM worker first resolves
# the static `current` symlink (admin screen loads), an export then swaps it,
# and an editor pushes one post immediately afterwards.
set -u
H=$(cd "$(dirname "$0")" && pwd)
. "$H/profile.sh"
sbqa_require_stack; E=$SBQA_E
PHP84="env LD_LIBRARY_PATH=$P/shared-libs $P/bin/php -n -c $E/conf/php.ini"
S=$E/wordpress/wp-content/static-bridge

for i in 1 2 3 4 5 6 7 8; do curl -s -o /dev/null -H @"$E/run/cookie-administrator.txt" "$B/wp-admin/admin.php?page=static-bridge" & done; wait
php "$H/http-gui.php" administrator export > /dev/null
ACTIVE=$(readlink "$S/current")
MARK="SBQA_SWAP_$(date +%s%N)"
$PHP84 "$H/wp69-harness.php" mark 1 "$MARK" > /dev/null
LIST=$(curl -s -H @"$E/run/cookie-editor.txt" "$B/wp-admin/edit.php")
URL=$(echo "$LIST" | grep -oE 'admin-post\.php\?action=static_bridge_push_post&(amp;|#038;)post_id=1&(amp;|#038;)_wpnonce=[a-f0-9]+' | head -1 | sed 's/&amp;/\&/g; s/&#038;/\&/g')
for i in 1 2 3 4; do
    curl -s -o /dev/null -H @"$E/run/cookie-editor.txt" "$B/wp-admin/$URL"
    PUBLIC=$(curl -s "$B/hello-world/" | grep -c "$MARK")
    WRONG=$(grep -l "$MARK" "$S"/releases/*/hello-world/index.html 2>/dev/null | grep -v "$ACTIVE" | wc -l)
    echo "push attempt $i: public_has_new_content=$PUBLIC written_into_non_active_release=$WRONG"
    [ "$PUBLIC" = "1" ] && break
done
echo "plugin version: $(grep -m1 'Version:' "$E/wordpress/wp-content/plugins/static-bridge/static-bridge.php" | awk '{print $3}') | realpath_cache_ttl=$($PHP84 -r 'echo ini_get("realpath_cache_ttl");')"
