#!/usr/bin/env bash
# Stops one disposable QA stack and deletes its folder $SBQA_HOME/<env>. Refuses any folder that
# setup-stack.sh did not build for exactly this stack (marker file <env>/.sbqa-stack).
# Usage: bash destroy-stack.sh nginx|apache|nginx71|ff-nginx|ff-apache|wc-nginx|wc-apache|litespeed
set -eu
H=$(cd "$(dirname "$0")" && pwd)
SBQA_STACK=${1:?Usage: bash destroy-stack.sh nginx|apache|nginx71|ff-nginx|ff-apache|wc-nginx|wc-apache|litespeed}
. "$H/profile.sh"
[ -e "$SBQA_E" ] || { echo "$SBQA_E does not exist"; exit 0; }
[ "$(cat "$SBQA_E/.sbqa-stack" 2>/dev/null)" = "$SBQA_STACK" ] || { echo "REFUSED: $SBQA_E has no $SBQA_STACK marker"; exit 2; }
[ -f "$SBQA_E/wordpress/wp-load.php" ] && bash "$H/stack.sh" stop > /dev/null || true
for pid in "$SBQA_E"/run/*.pid; do [ -f "$pid" ] && kill -0 "$(cat "$pid")" 2>/dev/null && { echo "REFUSED: $(basename "$pid" .pid) still running"; exit 2; }; done
chmod -R u+w "$SBQA_E"
rm -rf "$SBQA_E"
echo "$SBQA_STACK removed ($SBQA_E)"
