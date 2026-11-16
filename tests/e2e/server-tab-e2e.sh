#!/usr/bin/env bash
# Server tab (Static Bridge 2.3.0) on the disposable stack selected by SBQA_STACK. Run AFTER run-e2e.sh on
# the same ZIP: it reuses the active plugin, the release and the QA sessions.
#   Apache: the compression and file-cache block of .htaccess with the stack's modules (mod_filter but neither
#     mod_deflate nor mod_expires: the block must do nothing and break nothing), with the modules loaded,
#     and on a server that refuses the directives (the block must go away by itself).
#   Nginx: the copied blocks pass nginx -t and compress; the object-cache drop-in on Redis and Memcached
#     (Docker, loopback only), with the service stopping and coming back, a foreign drop-in, deactivation.
# Changed server configuration is a QA copy ((QA) below): the stack's own files are restored on exit.
# Usage: [SBQA_STACK=apache] bash server-tab-e2e.sh
set -u
H=$(cd "$(dirname "$0")" && pwd)
. "$H/profile.sh"
sbqa_require_stack; E=$SBQA_E
PHP84="env LD_LIBRARY_PATH=$P/shared-libs $P/bin/php -n -c $E/conf/php.ini"
WP=$E/wordpress
PASS=0; FAIL=0
ok() { if [ "$1" = "0" ]; then PASS=$((PASS+1)); echo "[PASS] $2"; else FAIL=$((FAIL+1)); echo "[FAIL] $2${3:+ :: $3}"; fi; }
COOKIE="$E/run/cookie-administrator.txt"
[ -s "$COOKIE" ] || { echo "No administrator session: run run-e2e.sh first"; exit 2; }
code() { curl -s -o /dev/null -w '%{http_code}' --max-time 20 "$@"; }
headers() { curl -s -o /dev/null -D - --max-time 20 "$@" | tr -d '\r'; }
tab() { curl -s --max-time 60 -H @"$COOKIE" "$B/wp-admin/admin.php?page=static-bridge&tab=server"; }
# One action of the "Cosa può fare Static Bridge" card, with the nonce of the tab's forms (or $3, taken
# earlier, when the card shows no form); prints the notice shown after it, "none" if there is none.
nonce() { tab | grep -oE 'name="_wpnonce" value="[a-f0-9]+"' | head -1 | cut -d'"' -f4; }
tuning() {
    local n=${3:-$(nonce)}
    [ -n "$n" ] || { echo "no-nonce"; return; }
    curl -s -o /dev/null --max-time 90 -H @"$COOKIE" --data "action=static_bridge_server_tuning&feature=$1&enable=$2&_wpnonce=$n" "$B/wp-admin/admin-post.php"
    tab | php -r '$h = stream_get_contents(STDIN); preg_match("~<div class=\"notice (notice-[a-z]+)[^\"]*\"><p>(.*?)</p>~s", $h, $m); echo ($m[1] ?? "none"), " ", html_entity_decode(strip_tags($m[2] ?? ""));'
}
check() { php "$H/http-gui.php" administrator call static_bridge_server_check_step check="$1" | php -r '$j = json_decode(stream_get_contents(STDIN), true); $r = $j["json"]["data"]["result"] ?? array(); echo ($r["status"] ?? "?"), " ", $r["summary"] ?? "";'; }
# Deactivates or activates Static Bridge from the Plugins screen, as an administrator does.
plugin_toggle() {
    local link; link=$(curl -s -H @"$COOKIE" "$B/wp-admin/plugins.php" | grep -oE "plugins\.php\?action=$1&(amp;|#038;)plugin=static-bridge%2Fstatic-bridge\.php[^\"']*" | head -1 | sed 's/&amp;/\&/g; s/&#038;/\&/g')
    [ -n "$link" ] && curl -s -o /dev/null --max-time 60 -H @"$COOKIE" "$B/wp-admin/$link"
}
php "$H/http-gui.php" administrator call static_bridge_server_check_start > /dev/null

if [ "$SBQA_WEB" = "apache" ]; then
    HT=$WP/.htaccess
    HTTPD_CONF=$E/conf/httpd.conf
    QA_CONF=$E/conf/httpd-server-tab.conf
    restart_httpd() { $HTTPD -f "$HTTPD_CONF" -k stop > /dev/null 2>&1; for i in $(seq 1 40); do [ -f "$E/run/httpd.pid" ] && kill -0 "$(cat "$E/run/httpd.pid" 2>/dev/null)" 2>/dev/null || break; sleep 0.25; done; $HTTPD -f "$1" -k start; for i in $(seq 1 40); do [ "$(code "$B/wp-login.php")" != "000" ] && break; sleep 0.25; done; }
    restore() { [ -f "$HT" ] && php -r '$f=$argv[1]; file_put_contents($f, preg_replace("~^# BEGIN StaticBridge-Server\R.*?^# END StaticBridge-Server\h*(?:\R\h*(?=\R|$))*\R?~ms", "", file_get_contents($f)));' "$HT"; restart_httpd "$HTTPD_CONF"; rm -f "$QA_CONF"; }
    trap restore EXIT
    BEFORE=$(cat "$HT")
    CSS="$B/wp-includes/css/dashicons.min.css"

    echo "== Apache without mod_deflate, mod_filter, mod_expires (the stack as built)"
    R=$(check compression); case "$R" in warning*) ok 0 "compression reported missing: $R";; *) ok 1 "compression reported missing" "$R";; esac
    R=$(tuning htaccess 1); case "$R" in notice-success*) ok 0 "block written: $R";; *) ok 1 "block written" "$R";; esac
    grep -q '^# BEGIN StaticBridge-Server' "$HT" && grep -q '^# BEGIN Static Bridge$' "$HT" && grep -q '^# BEGIN WordPress' "$HT"; ok $? "the block sits beside the routing and WordPress blocks"
    [ "$(code "$B/")" = "200" ] && [ "$(code "$CSS")" = "200" ] && [ "$(code -H @"$COOKIE" "$B/wp-admin/")" = "200" ]; ok $? "without the modules the block changes nothing: home, CSS and wp-admin answer 200"
    php "$H/http-gui.php" administrator export > /dev/null; grep -q '^# BEGIN StaticBridge-Server' "$HT" && grep -q '^# BEGIN Static Bridge$' "$HT"; ok $? "a full export rewrites the routing rules and leaves the block in place"
    R=$(tuning htaccess 0); [ "$(cat "$HT")" = "$(php -r 'echo preg_replace("~^# BEGIN StaticBridge-Server\R.*?^# END StaticBridge-Server\h*(?:\R\h*(?=\R|$))*\R?~ms", "", file_get_contents($argv[1]));' "$HT")" ] && ! grep -q StaticBridge-Server "$HT"; ok $? "block removed: $R"

    echo "== (QA) Apache with mod_deflate and mod_expires loaded"
    { cat "$HTTPD_CONF"; for m in filter deflate expires; do grep -q "^LoadModule ${m}_module" "$E/conf/modules.conf" || echo "LoadModule ${m}_module \"$APX/modules/mod_$m.so\""; done; } > "$QA_CONF"
    restart_httpd "$QA_CONF"
    [ "$(code "$B/")" = "200" ]; ok $? "QA configuration started"
    R=$(tuning htaccess 1); case "$R" in notice-success*) ok 0 "block written: $R";; *) ok 1 "block written" "$R";; esac
    H_CSS=$(headers -H 'Accept-Encoding: gzip' "$CSS"); H_HOME=$(headers -H 'Accept-Encoding: gzip' "$B/")
    echo "$H_CSS" | grep -qi '^content-encoding: gzip' && echo "$H_CSS" | grep -qiE '^cache-control: max-age=2592000'; ok $? "CSS compressed and kept 30 days by the browser" "$(echo "$H_CSS" | grep -iE 'encoding|cache-control|expires' | tr '\n' ' ')"
    echo "$H_HOME" | grep -qi '^content-encoding: gzip' && ! echo "$H_HOME" | grep -qiE '^(expires|cache-control: max-age=[1-9])'; ok $? "the static home compressed, with no expiry from the block" "$(echo "$H_HOME" | grep -iE 'encoding|cache-control|expires|x-sbqa' | tr '\n' ' ')"
    R=$(check compression); case "$R" in pass*) ok 0 "Server tab: $R";; *) ok 1 "Server tab sees the compression" "$R";; esac
    R=$(check browser-cache); case "$R" in pass*) ok 0 "Server tab: $R";; *) ok 1 "Server tab sees the file cache" "$R";; esac
    plugin_toggle deactivate
    ! grep -q StaticBridge-Server "$HT"; ok $? "deactivating Static Bridge removes the block"
    plugin_toggle activate
    [ "$(code "$B/")" = "200" ] && grep -q '^# BEGIN Static Bridge$' "$HT"; ok $? "Static Bridge active again, with its routing rules"

    echo "== (QA) Apache that refuses the directives in .htaccess (AllowOverride without Indexes)"
    sed 's/^\(\s*\)AllowOverride All/\1AllowOverride FileInfo AuthConfig Limit Options=FollowSymLinks,SymLinksIfOwnerMatch/' "$QA_CONF" > "$QA_CONF.tmp" && mv "$QA_CONF.tmp" "$QA_CONF"
    restart_httpd "$QA_CONF"
    [ "$(code "$B/")" = "200" ]; ok $? "QA configuration started without the block"
    R=$(tuning htaccess 1); case "$R" in notice-error*500*) ok 0 "refusal reported: $R";; *) ok 1 "refusal reported" "$R";; esac
    ! grep -q StaticBridge-Server "$HT" && [ "$(code "$B/")" = "200" ]; ok $? "the block is gone and the site answers 200 again"
    restart_httpd "$HTTPD_CONF"
    # The served copy in the routing block changes with each export: compare which blocks are there.
    blocks() { grep -E '^# (BEGIN|END) ' "$1"; }
    [ "$(blocks "$HT")" = "$(echo "$BEFORE" | grep -E '^# (BEGIN|END) ')" ]; ok $? ".htaccess has the same blocks as before the test" "$(blocks "$HT" | tr '\n' ' ')"
else
    echo "== Nginx: the copied blocks"
    QA_NGINX=$E/conf/nginx-server-tab.conf
    SNIPPETS=$($PHP84 -r 'define("WP_USE_THEMES", false); require $argv[1] . "/wp-load.php"; echo json_encode(Static_Bridge_Server_Tuning::nginx_snippets());' "$WP")
    php -r '$s = json_decode($argv[1], true); $c = file_get_contents($argv[2]); $c = preg_replace("~\bhttp \{~", "http {\n" . $s["http"] . "\n", $c, 1); $c = preg_replace("~(\n  server \{\n)~", "$1" . $s["server"] . "\n", $c, 1); file_put_contents($argv[3], $c);' "$SNIPPETS" "$E/conf/nginx.conf" "$QA_NGINX"
    $NGX -t -p "$E/run" -c "$QA_NGINX" > /dev/null 2>&1; ok $? "(QA) nginx -t accepts the two blocks where the tab says to put them"
    "$NGX" -s quit -p "$E/run" -c "$E/conf/nginx.conf"; for i in $(seq 1 40); do [ -f "$E/run/nginx.pid" ] || break; sleep 0.25; done
    "$NGX" -p "$E/run" -c "$QA_NGINX"; sleep 0.5
    H_CSS=$(headers -H 'Accept-Encoding: gzip' "$B/wp-includes/css/dashicons.min.css"); H_HOME=$(headers -H 'Accept-Encoding: gzip' "$B/")
    echo "$H_CSS" | grep -qi '^content-encoding: gzip' && echo "$H_CSS" | grep -qiE '^cache-control: max-age=2592000'; ok $? "CSS compressed and kept 30 days" "$(echo "$H_CSS" | grep -iE 'encoding|cache-control|expires' | tr '\n' ' ')"
    echo "$H_HOME" | grep -qi '^content-encoding: gzip' && ! echo "$H_HOME" | grep -qiE '^(expires|cache-control: max-age=[1-9])'; ok $? "the static home compressed, with no expiry" "$(echo "$H_HOME" | grep -iE 'encoding|cache-control|expires|x-static' | tr '\n' ' ')"
    "$NGX" -s quit -p "$E/run" -c "$QA_NGINX"; for i in $(seq 1 40); do [ -f "$E/run/nginx.pid" ] || break; sleep 0.25; done
    "$NGX" -p "$E/run" -c "$E/conf/nginx.conf"; rm -f "$QA_NGINX"; sleep 0.5
    [ "$(code "$B/")" = "200" ]; ok $? "stack Nginx configuration back"

    echo "== Object cache (QA: PHP-FPM restarted with redis.so and memcached.so; Redis and Memcached in Docker)"
    X=$SBQA_HOME/sources/php-ext-8.4
    if [ ! -f "$X/redis.so" ] || [ ! -f "$X/memcached.so" ] || ! docker info > /dev/null 2>&1; then
        echo "[SKIP] object cache: no extensions in $X (bash tests/e2e/build-php-ext.sh) or no Docker"
    elif ss -ltn 2>/dev/null | grep -qE '127\.0\.0\.1:(6379|11211) |\*:(6379|11211) |\[::\]:(6379|11211) '; then
        echo "[SKIP] object cache: 6379 or 11211 is already in use on this machine"
    else
        QA_INI=$E/conf/php-server-tab.ini
        { cat "$E/conf/php.ini"; echo "extension=$X/redis.so"; echo "extension=$X/memcached.so"; } > "$QA_INI"
        fpm() { [ -f "$E/run/php-fpm.pid" ] && kill -QUIT "$(cat "$E/run/php-fpm.pid" 2>/dev/null)" 2>/dev/null; for i in $(seq 1 40); do [ -f "$E/run/php-fpm.pid" ] && kill -0 "$(cat "$E/run/php-fpm.pid" 2>/dev/null)" 2>/dev/null || break; sleep 0.25; done
            setsid env LD_LIBRARY_PATH="$P/shared-libs:$X" "$P/sbin/php-fpm" -n -c "$1" -y "$E/conf/php-fpm.conf" -F > /dev/null 2>&1 < /dev/null & sleep 1; }
        QA_MU=$WP/wp-content/mu-plugins/sbqa-object-cache-qa.php
        cleanup() { docker rm -f sbqa-oc-redis sbqa-oc-memcached > /dev/null 2>&1; f=$WP/wp-content/object-cache.php; [ -f "$f" ] && grep -q Static-Bridge-Object-Cache-Dropin "$f" && rm -f "$f"; rm -f "$QA_MU" "$WP/wp-content/static-bridge/.object-cache-down"; fpm "$E/conf/php.ini"; rm -f "$QA_INI"; }
        trap cleanup EXIT
        docker rm -f sbqa-oc-redis sbqa-oc-memcached > /dev/null 2>&1
        docker run -d --name sbqa-oc-redis -p 127.0.0.1:6379:6379 redis:7-alpine > /dev/null
        for i in $(seq 1 40); do docker exec sbqa-oc-redis redis-cli ping 2>/dev/null | grep -q PONG && break; sleep 0.25; done
        fpm "$QA_INI"
        MYSQL="env LD_LIBRARY_PATH=$M/lib $M/bin/mysql --no-defaults -uroot --socket=$E/run/mysql.sock $SBQA_DB -N -e"
        PFX=$(grep -oE "table_prefix\s*=\s*'[^']+'" "$WP/wp-config.php" | cut -d"'" -f2)
        DROPIN=$WP/wp-content/object-cache.php
        # (QA) ?sbqa_transient=<name> sets a transient: where it lands (Redis or the options table) shows the fallback.
        mkdir -p "$WP/wp-content/mu-plugins"
        echo '<?php add_action("init", function () { if (isset($_GET["sbqa_transient"]) && preg_match("/^[a-z0-9]{1,20}$/", $_GET["sbqa_transient"])) { set_transient("sbqa_oc_" . $_GET["sbqa_transient"], "v", 600); } });' > "$QA_MU"
        in_db() { [ -n "$($MYSQL "SELECT option_name FROM ${PFX}options WHERE option_name = '_transient_sbqa_oc_$1'")" ]; }

        R=$(check object-cache); case "$R" in tip*Redis*127.0.0.1:6379*) ok 0 "Server tab finds Redis: $R";; *) ok 1 "Server tab finds Redis" "$R";; esac
        # The nonce comes first: with a foreign drop-in the card offers no button, a request could still arrive.
        N0=$(nonce)
        echo "<?php // another plugin's drop-in (QA)" > "$DROPIN"
        R=$(tuning object-cache 1 "$N0"); case "$R" in notice-error*object-cache.php*) [ "$(cat "$DROPIN")" = "<?php // another plugin's drop-in (QA)" ];; *) false;; esac; ok $? "a foreign drop-in is refused and left alone: $R"
        tab | grep -q 'C’è già un file object-cache.php'; ok $? "the card explains why there is no button"
        rm -f "$DROPIN"
        R=$(tuning object-cache 1); case "$R" in notice-success*Redis*) ok 0 "installed: $R";; *) ok 1 "installed" "$R";; esac
        grep -q Static-Bridge-Object-Cache-Dropin "$DROPIN" && ! grep -qi 'password' <(sed -n '/SB-CONFIG/,/SB-CONFIG/p' "$DROPIN"); ok $? "the drop-in is Static Bridge's and holds no password"
        [ "$(code "$B/")" = "200" ] && [ "$(code -H @"$COOKIE" "$B/wp-admin/")" = "200" ] && [ "$(code "$B/wp-login.php")" = "200" ]; ok $? "home, wp-admin and login answer 200 with the cache"
        N=$(docker exec sbqa-oc-redis redis-cli --scan --pattern 'sbc:*' | wc -l); [ "$N" -gt 10 ]; ok $? "WordPress writes to Redis ($N keys under sbc:)"
        tab | grep -q 'sb-status-pill is-published"><span></span>Attiva'; ok $? "the card shows the cache as active"
        F=$(php "$H/http-gui.php" administrator export | php -r '$j = json_decode(stream_get_contents(STDIN), true); echo $j["final"]["status"] ?? json_encode($j);'); [ "$F" = "complete" ]; ok $? "a full export completes with the object cache ($F)"
        $PHP84 -r 'define("WP_USE_THEMES", false); require $argv[1] . "/wp-load.php"; echo wp_using_ext_object_cache() ? "ext" : "core";' "$WP" | grep -q core; ok $? "PHP without the extension (WP-CLI here): the drop-in steps aside and WordPress uses its own cache"
        code "$B/?sbqa_transient=up" > /dev/null; ! in_db up; ok $? "with Redis a transient goes to Redis, not to the database"

        echo "== Redis stops"
        docker stop sbqa-oc-redis > /dev/null
        T0=$(date +%s%N); C1=$(code "$B/?nocache=1"); C2=$(code -H @"$COOKIE" "$B/wp-admin/"); T1=$(date +%s%N)
        [ "$C1" = "200" ] && [ "$C2" = "200" ]; ok $? "without Redis: home and wp-admin still answer 200 ($(( (T1-T0)/1000000 )) ms for both)"
        [ -f "$WP/wp-content/static-bridge/.object-cache-down" ]; ok $? "the pause after a failed connection is noted"
        R=$(tab | grep -c 'Servizio non raggiungibile'); [ "$R" -ge 1 ]; ok $? "the card says the service does not answer"
        code "$B/?sbqa_transient=down" > /dev/null; in_db down; ok $? "without Redis a transient goes to the database, as without a drop-in"
        docker start sbqa-oc-redis > /dev/null; for i in $(seq 1 40); do docker exec sbqa-oc-redis redis-cli ping 2>/dev/null | grep -q PONG && break; sleep 0.25; done
        rm -f "$WP/wp-content/static-bridge/.object-cache-down"
        [ "$(code "$B/")" = "200" ] && tab | grep -q 'sb-status-pill is-published"><span></span>Attiva'; ok $? "Redis back: the cache is used again"

        echo "== Off, Memcached, deactivation"
        R=$(tuning object-cache 0); [ ! -f "$DROPIN" ] && [ "$(code "$B/")" = "200" ]; ok $? "removed from the tab: $R"
        docker stop sbqa-oc-redis > /dev/null
        docker run -d --name sbqa-oc-memcached -p 127.0.0.1:11211:11211 memcached:1.6-alpine > /dev/null; sleep 1
        R=$(tuning object-cache 1); case "$R" in notice-success*Memcached*) ok 0 "Memcached installed: $R";; *) ok 1 "Memcached installed" "$R";; esac
        [ "$(code "$B/")" = "200" ] && [ "$(code -H @"$COOKIE" "$B/wp-admin/")" = "200" ]; ok $? "home and wp-admin answer 200 with Memcached"
        S=$(docker exec sbqa-oc-memcached sh -c 'printf "stats\r\nquit\r\n" | nc 127.0.0.1 11211' 2>/dev/null | grep -oE 'curr_items [0-9]+' | cut -d' ' -f2); [ "${S:-0}" -gt 10 ]; ok $? "WordPress writes to Memcached (${S:-0} items)"
        plugin_toggle deactivate
        [ ! -f "$DROPIN" ] && [ "$(code "$B/")" = "200" ]; ok $? "deactivating Static Bridge removes its drop-in"
        plugin_toggle activate
        [ "$(code "$B/")" = "200" ]; ok $? "Static Bridge active again"
    fi
fi
N=$(grep -cE 'wp-content/plugins/static-bridge/|object-cache\.php' "$E/logs/php-errors.log"); [ "$N" = "0" ]; ok $? "no PHP notice/warning/error from the plugin or the drop-in ($N lines)"
echo "SERVER TAB E2E RESULT: $PASS passed, $FAIL failed"
[ "$FAIL" = "0" ]
