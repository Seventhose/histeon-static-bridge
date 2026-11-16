#!/usr/bin/env bash
# Builds one disposable QA stack from scratch in $SBQA_HOME/<env> (default tests/e2e/stacks, gitignored):
#   - WordPress core copied (read only) from SBQA_WP69_CORE (an extracted WordPress 6.9 tree or the official
#     wordpress-6.9.zip); for nginx71 the 7.1.3 core is then copied (read only) from SBQA_WP71_CORE
#     (default: the LocalWP site "test") and the database upgraded with wp_upgrade(), as originally;
#   - ff-* stacks: Fluent Forms copied (read only) from SBQA_FLUENTFORM_SOURCE (default: the LocalWP
#     site "test") and the QA mu-plugin sbqa-fluentforms-qa.php;
#   - wc-* stacks: WordPress 7.1.3 as for nginx71 (WooCommerce 11 needs WordPress 7.0), WooCommerce 11.2.2
#     copied (read only, not activated) from SBQA_WOOCOMMERCE_SOURCE (default: the first LocalWP site that has
#     it) and the QA mu-plugin sbqa-woocommerce-qa.php;
#   - wp-config.php with fresh random salts, MySQL 8.4.0 datadir initialised from nothing on a private
#     socket (no TCP), PHP-FPM 8.4.18, Nginx 1.26.1 or Apache 2.4.43 config (LocalWP binaries under
#     SBQA_LOCALWP, default ~/.config/Local/lightning-services) bound to 127.0.0.1 only;
#   - litespeed: OpenLiteSpeed 1.9.4 with lsphp 8.4 from the pinned official Docker image (SBQA_OLS_IMAGE, pulled
#     beforehand: setup never downloads), host network, listener on 127.0.0.1 only, no WebAdmin; MySQL and the
#     CLI PHP stay the LocalWP ones, lsphp reaches MySQL through the same private socket;
#   - backups/00-baseline-before-activation.sql dumped from a fresh install (seed-baseline.php).
# Source trees are verified against core-hashes.txt (SBQA_ALLOW_SOURCE_DRIFT=1 accepts a different tree,
# with a warning). Never writes outside the new stack folder; refuses if it already exists.
# Usage: bash setup-stack.sh nginx|apache|nginx71|ff-nginx|ff-apache|wc-nginx|wc-apache|litespeed|all
set -eu
H=$(cd "$(dirname "$0")" && pwd)
if [ "${1:-}" = "all" ]; then
    for s in nginx apache nginx71 ff-nginx ff-apache wc-nginx wc-apache litespeed; do bash "$0" "$s"; done
    exit 0
fi
SBQA_STACK=${1:?Usage: bash setup-stack.sh nginx|apache|nginx71|ff-nginx|ff-apache|wc-nginx|wc-apache|litespeed|all}
. "$H/profile.sh"
E=$SBQA_E
WP=$E/wordpress
[ -e "$E" ] && { echo "REFUSED: $E already exists (remove it with destroy-stack.sh $SBQA_STACK first)"; exit 2; }
for bin in "$P/bin/php" "$P/sbin/php-fpm" "$M/bin/mysqld" "$M/bin/mysqldump" "$NGX" "$APX/bin/httpd"; do
    [ -x "$bin" ] || { echo "REFUSED: LocalWP binary missing: $bin (set SBQA_LOCALWP)"; exit 2; }
done
PHP84="env LD_LIBRARY_PATH=$P/shared-libs $P/bin/php -n"
MYSQL="env LD_LIBRARY_PATH=$M/lib $M/bin/mysql --no-defaults -uroot --socket=$E/run/mysql.sock"
LOCAL_TEST="$HOME/Local Sites/test/app/public"
if [ "$SBQA_LSWS" = "1" ]; then
    docker image inspect "$SBQA_OLS_IMAGE" > /dev/null 2>&1 || { echo "REFUSED: Docker image $SBQA_OLS_IMAGE missing (docker pull it first)"; exit 2; }
fi

# Tree hash of a folder: sha256 of the sorted list of "sha256  ./path" lines.
tree_hash() { (cd "$1" && find . -type f -print0 | LC_ALL=C sort -z | xargs -0 sha256sum) | sha256sum | cut -c1-64; }
expect_hash() { # <key> <folder> <what>
    local want got
    want=$(awk -v k="$1" '$1 == k { print $2 }' "$H/core-hashes.txt"); got=$(tree_hash "$2")
    [ "$got" = "$want" ] && return 0
    if [ "${SBQA_ALLOW_SOURCE_DRIFT:-0}" = "1" ]; then echo "WARNING: $3 differs from the verified $1 tree ($got); results may differ"; return 0; fi
    echo "REFUSED: $3 is not the verified $1 tree (got $got, want $want; SBQA_ALLOW_SOURCE_DRIFT=1 to accept)."
    echo "Remove the partial stack with: bash tests/e2e/destroy-stack.sh $SBQA_STACK"; exit 2
}
# rsync filters for the files of a WordPress release (no wp-config.php, no extra plugins/themes/uploads).
CORE_ROOT=(--include='/wp-admin/***' --include='/wp-includes/***')
for f in index.php license.txt readme.html wp-activate.php wp-blog-header.php wp-comments-post.php wp-config-sample.php wp-cron.php \
    wp-links-opml.php wp-load.php wp-login.php wp-mail.php wp-settings.php wp-signup.php wp-trackback.php xmlrpc.php; do
    CORE_ROOT+=(--include="/$f")
done
CORE_CONTENT=(--include=/wp-content/ --include=/wp-content/index.php --include=/wp-content/plugins/
    --include=/wp-content/plugins/index.php --include=/wp-content/plugins/hello.php --include='/wp-content/plugins/akismet/***'
    --include=/wp-content/themes/ --include=/wp-content/themes/index.php --include='/wp-content/themes/twentytwentythree/***'
    --include='/wp-content/themes/twentytwentyfour/***' --include='/wp-content/themes/twentytwentyfive/***')

# ---- sources ---------------------------------------------------------------------------------
WP69=${SBQA_WP69_CORE:-}
[ -n "$WP69" ] || { echo "REFUSED: set SBQA_WP69_CORE to an extracted WordPress 6.9 tree or to wordpress-6.9.zip (see docs/TESTING-AND-RELEASE.md)"; exit 2; }
if [ -f "$WP69" ]; then
    X=$SBQA_HOME/sources/wordpress-6.9
    if [ ! -f "$X/wordpress/wp-load.php" ]; then
        mkdir -p "$X"; unzip -q "$WP69" 'wordpress/*' -d "$X"
    fi
    WP69=$X/wordpress
fi
[ -f "$WP69/wp-includes/version.php" ] && grep -q "^\$wp_version = '6\.9';" "$WP69/wp-includes/version.php" \
    || { echo "REFUSED: $WP69 is not a WordPress 6.9 tree"; exit 2; }
if [ "$SBQA_WP" = "7.1.3" ]; then
    WP71=${SBQA_WP71_CORE:-$LOCAL_TEST}
    grep -q "^\$wp_version = '7\.1\.3';" "$WP71/wp-includes/version.php" 2>/dev/null || { echo "REFUSED: $WP71 is not a WordPress 7.1.3 tree (set SBQA_WP71_CORE)"; exit 2; }
fi
if [ "$SBQA_FF" = "1" ]; then
    FF=${SBQA_FLUENTFORM_SOURCE:-$LOCAL_TEST/wp-content/plugins/fluentform}
    grep -q "^ \* Version: 6\.2\.15$" "$FF/fluentform.php" 2>/dev/null || { echo "REFUSED: $FF is not Fluent Forms 6.2.15 (set SBQA_FLUENTFORM_SOURCE)"; exit 2; }
fi
if [ "$SBQA_WC" = "1" ]; then
    WOO=${SBQA_WOOCOMMERCE_SOURCE:-$(for d in "$HOME/Local Sites"/*/app/public/wp-content/plugins/woocommerce; do
        grep -q "^ \* Version: 11\.2\.2$" "$d/woocommerce.php" 2>/dev/null && { echo "$d"; break; }; done)}
    grep -q "^ \* Version: 11\.2\.2$" "$WOO/woocommerce.php" 2>/dev/null || { echo "REFUSED: no WooCommerce 11.2.2 at '$WOO' (set SBQA_WOOCOMMERCE_SOURCE)"; exit 2; }
fi

# ---- files -----------------------------------------------------------------------------------
echo "== Building $SBQA_STACK in $E"
mkdir -p "$E/conf" "$E/logs" "$E/run" "$E/backups" "$E/mysql-data"
chmod 700 "$E" "$E/run"
echo "$SBQA_STACK" > "$E/.sbqa-stack"
rsync -a "${CORE_ROOT[@]}" "${CORE_CONTENT[@]}" --exclude='*' "$WP69/" "$WP/"
expect_hash wordpress-6.9 "$WP" "WordPress 6.9 from $WP69"
SALTS=$(php -r 'foreach (["AUTH_KEY","SECURE_AUTH_KEY","LOGGED_IN_KEY","NONCE_KEY","AUTH_SALT","SECURE_AUTH_SALT","LOGGED_IN_SALT","NONCE_SALT"] as $k) { echo "define(\x27$k\x27, \x27" . bin2hex(random_bytes(32)) . "\x27);\n"; }')
cat > "$WP/wp-config.php" <<CFG
<?php
// Disposable Static Bridge QA stack $SBQA_STACK (tests/e2e/setup-stack.sh). Loopback only; not a live site.
define('DB_NAME', '$SBQA_DB');
define('DB_USER', 'root');
define('DB_PASSWORD', '');
define('DB_HOST', 'localhost:$E/run/mysql.sock');
define('DB_CHARSET', 'utf8mb4');
define('DB_COLLATE', '');
$SALTS
\$table_prefix = 'wp69_';
define('WP_HOME', '$B');
define('WP_SITEURL', '$B');
define('WP_ENVIRONMENT_TYPE', 'local');
define('WP_CACHE', false);
// Italian site: Static Bridge is written in Italian and the checks read its texts (other languages get English).
define('WPLANG', 'it_IT');
define('WP_DEBUG', true);
define('WP_DEBUG_DISPLAY', false);
define('WP_DEBUG_LOG', '$E/logs/wp-debug.log');
define('DISABLE_WP_CRON', true);
define('AUTOMATIC_UPDATER_DISABLED', true);
define('WP_HTTP_BLOCK_EXTERNAL', true);
define('WP_ACCESSIBLE_HOSTS', '127.0.0.1');
if (!defined('ABSPATH')) { define('ABSPATH', __DIR__ . '/'); }
require_once ABSPATH . 'wp-settings.php';
CFG
chmod 600 "$WP/wp-config.php"

cat > "$E/conf/php.ini" <<CFG
memory_limit = 256M
max_execution_time = 120
error_reporting = E_ALL
display_errors = Off
log_errors = On
error_log = $E/logs/php-errors.log
date.timezone = UTC
upload_max_filesize = 8M
post_max_size = 8M
CFG
if [ "$SBQA_WEB" = "nginx" ]; then LISTEN="listen = 127.0.0.1:$((SBQA_PORT + 1))
listen.allowed_clients = 127.0.0.1"; else LISTEN="listen = $E/run/fpm.sock
listen.mode = 0600"; fi
cat > "$E/conf/php-fpm.conf" <<CFG
[global]
pid = $E/run/php-fpm.pid
error_log = $E/logs/php-fpm.log
daemonize = no

[www]
$LISTEN
pm = static
pm.max_children = 4
request_terminate_timeout = 180s
catch_workers_output = yes
php_admin_value[error_log] = $E/logs/php-errors.log
php_admin_flag[log_errors] = on
CFG

if [ "$SBQA_LSWS" = "1" ]; then
    # Server config from the templates; mime.properties is the image's own. lsphp reads conf/lsphp/php.ini (PHPRC).
    mkdir -p "$E/conf/ols/vhosts/sbqa" "$E/conf/lsphp"
    sed -e "s#@E@#$E#g" -e "s#@PORT@#$SBQA_PORT#g" "$H/templates/ols-httpd_config.conf" > "$E/conf/ols/httpd_config.conf"
    sed -e "s#@E@#$E#g" "$H/templates/ols-vhconf.conf" > "$E/conf/ols/vhosts/sbqa/vhconf.conf"
    docker run --rm --network none --entrypoint cat "$SBQA_OLS_IMAGE" /usr/local/lsws/.conf/mime.properties > "$E/conf/ols/mime.properties"
    cp "$E/conf/php.ini" "$E/conf/lsphp/php.ini"
elif [ "$SBQA_WEB" = "nginx" ]; then
    mkdir -p "$E/run/client_body" "$E/run/fastcgi" "$E/run/proxy" "$E/run/scgi" "$E/run/uwsgi" "$E/run/logs"
    # LocalWP site layout; the plugin's generated block (conf/static-bridge.conf) replaces location /.
    sed -e "s#@E@#$E#g" -e "s#@LW@#$LW#g" -e "s#@PORT@#$SBQA_PORT#g" -e "s#@FPM_PORT@#$((SBQA_PORT + 1))#g" \
        "$H/templates/nginx.conf" > "$E/conf/nginx.conf"
    echo 'location / { try_files $uri $uri/ /index.php$is_args$args; }' > "$E/conf/static-bridge.conf"
else
    # LocalWP apache2.conf.hbs + modules.conf.hbs + site.conf.hbs (Linux, no fcgid), resolved for this stack.
    # modules.conf is rendered from LocalWP's own template. Only additions: mod_headers + the
    # X-SBQA-Apache-File observability header (QA only: success responses for files under
    # wp-content/static-bridge/), explicit runtime/mutex/pid paths, and an access log with %D.
    sed -e "s#{{ modules }}#$APX/modules#g" -e "s#{{ configPath }}#$LW/apache-2.4.43+11/conf#g" \
        -e '/{{#if shouldUseFcgid}}/,/{{\/if}}/d' -e '/{{#unless os.windows}}/d' -e '/{{\/unless}}/d' \
        "$LW/apache-2.4.43+11/conf/modules.conf.hbs" > "$E/conf/modules.conf"
    grep -q '{{' "$E/conf/modules.conf" && { echo "REFUSED: unrendered template tag in modules.conf"; exit 2; }
    sed -e "s#@E@#$E#g" -e "s#@APX@#$APX#g" -e "s#@PORT@#$SBQA_PORT#g" "$H/templates/httpd.conf" > "$E/conf/httpd.conf"
fi
grep -q '@[A-Z_]*@' "$E/conf/"*.conf && { echo "REFUSED: unresolved template placeholder"; exit 2; }

# ---- database baseline -----------------------------------------------------------------------
setsid env LD_LIBRARY_PATH="$M/lib" "$M/bin/mysqld" --no-defaults --initialize-insecure --datadir="$E/mysql-data/data" \
    --basedir="$M" --log-error="$E/logs/mysql-init.log" < /dev/null
bash "$H/stack.sh" start --mysql-only
trap 'bash "$H/stack.sh" stop > /dev/null 2>&1 || true' EXIT
$MYSQL -e "CREATE DATABASE $SBQA_DB CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_520_ci;"
SEED="$PHP84 -c $E/conf/php.ini $H/seed-baseline.php"
$SEED install > "$E/logs/seed.json"
$SEED content >> "$E/logs/seed.json"
if [ "$SBQA_WP" = "7.1.3" ]; then
    # Same path as the original 7.1 stack: the 6.9 baseline, then the 7.1.3 core and wp_upgrade().
    rsync -a --delete "${CORE_ROOT[@]}" --exclude='/wp-content/***' --exclude='*' "$WP71/" "$WP/"
    T=$(mktemp -d); rsync -a "${CORE_ROOT[@]}" --exclude='*' "$WP/" "$T/"; expect_hash wordpress-7.1.3-core "$T" "WordPress 7.1.3 from $WP71"; rm -rf "$T"
    $SEED upgrade >> "$E/logs/seed.json"
fi
V=$($SEED verify) || { echo "$V"; echo "REFUSED: baseline check failed"; exit 2; }
echo "$V" >> "$E/logs/seed.json"
env LD_LIBRARY_PATH="$M/lib" "$M/bin/mysqldump" --no-defaults -uroot --socket="$E/run/mysql.sock" --single-transaction \
    "$SBQA_DB" > "$E/backups/00-baseline-before-activation.sql"

# ---- QA additions (after the baseline, as on the original stacks) ----------------------------
mkdir -p "$WP/wp-content/mu-plugins"
cp "$H/mu-plugins/sbqa-fault-injector.php" "$H/mu-plugins/sbqa-textdomain-trace.php" "$WP/wp-content/mu-plugins/"
if [ "$SBQA_FF" = "1" ]; then
    # Read-only copy of the plugin (cp reads; the source site is not touched).
    cp -a "$FF" "$WP/wp-content/plugins/fluentform"
    expect_hash fluentform-6.2.15 "$WP/wp-content/plugins/fluentform" "Fluent Forms from $FF"
    cp "$H/mu-plugins/sbqa-fluentforms-qa.php" "$WP/wp-content/mu-plugins/"
fi
if [ "$SBQA_WC" = "1" ]; then
    # Read-only copy, not activated: wc-e2e.sh activates it after resetting the database to the baseline.
    cp -a "$WOO" "$WP/wp-content/plugins/woocommerce"
    expect_hash woocommerce-11.2.2 "$WP/wp-content/plugins/woocommerce" "WooCommerce from $WOO"
    cp "$H/mu-plugins/sbqa-woocommerce-qa.php" "$WP/wp-content/mu-plugins/"
fi
if [ "$SBQA_WEB" = "nginx" ]; then "$NGX" -t -q -p "$E/run" -c "$E/conf/nginx.conf"; elif [ "$SBQA_LSWS" != "1" ]; then $HTTPD -t -f "$E/conf/httpd.conf"; fi
bash "$H/stack.sh" stop > /dev/null
trap - EXIT
# HTTP smoke test (skipped while another server holds the port, e.g. an older copy of the stack).
if [ "$(curl -s -o /dev/null -w '%{http_code}' --max-time 3 "$B/")" = "000" ]; then
    bash "$H/stack.sh" start > /dev/null
    CODE=$(curl -s -o /dev/null -w '%{http_code}' --max-time 20 "$B/")
    bash "$H/stack.sh" stop > /dev/null
    [ "$CODE" = "200" ] || { echo "REFUSED: $B/ answered $CODE after the build"; exit 2; }
else
    echo "NOTE: 127.0.0.1:$SBQA_PORT is in use by another process; HTTP smoke test skipped"
fi
echo "$SBQA_STACK built: WordPress $(echo "$V" | php -r 'echo json_decode(stream_get_contents(STDIN), true)["wordpress"];') on $B ($SBQA_WEB). Start it with: SBQA_STACK=$SBQA_STACK bash tests/e2e/stack.sh start"
