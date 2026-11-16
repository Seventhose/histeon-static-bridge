#!/usr/bin/env bash
# Start/stop a disposable Static Bridge QA stack (loopback only) built by setup-stack.sh.
# Binaries come from LocalWP; nothing is installed system-wide and no LocalWP site is touched.
#   MySQL 8.4.0 on a private socket (no TCP), PHP-FPM 8.4.18 (TCP port + 1 for Nginx stacks, a private
#   unix socket for Apache stacks), Nginx 1.26.1 or Apache 2.4.43 on 127.0.0.1:<port> (see profile.sh).
#   litespeed: OpenLiteSpeed in the container $SBQA_OLS_NAME (host network, the stack folder mounted at the same
#   path, the server config read only) instead of PHP-FPM and the web server.
# Usage: [SBQA_STACK=nginx|apache|nginx71|ff-nginx|ff-apache|wc-nginx|wc-apache|litespeed] bash stack.sh start|stop|status
set -u
H=$(cd "$(dirname "$0")" && pwd)
. "$H/profile.sh"
sbqa_require_stack; E=$SBQA_E
WEB=nginx; [ "$SBQA_WEB" = "apache" ] && WEB=httpd
alive() { [ -f "$1" ] && kill -0 "$(cat "$1")" 2>/dev/null; }
ols_alive() { [ "$(docker inspect -f '{{.State.Running}}' "$SBQA_OLS_NAME" 2>/dev/null)" = "true" ]; }
web_alive() { if [ "$SBQA_LSWS" = "1" ]; then ols_alive; else alive "$E/run/$WEB.pid"; fi; }

case "${1:-}" in
start)
    mkdir -p "$E/run/logs"
    [ "$SBQA_WEB" = "nginx" ] && mkdir -p "$E/run/client_body" "$E/run/fastcgi" "$E/run/proxy" "$E/run/scgi" "$E/run/uwsgi"
    if ! alive "$E/run/mysqld.pid"; then
        setsid env LD_LIBRARY_PATH="$M/lib" "$M/bin/mysqld" --no-defaults --datadir="$E/mysql-data/data" --basedir="$M" \
            --socket="$E/run/mysql.sock" --skip-networking --mysqlx=OFF --pid-file="$E/run/mysqld.pid" \
            --log-error="$E/logs/mysqld.log" --innodb-buffer-pool-size=128M > /dev/null 2>&1 < /dev/null &
        for i in $(seq 1 60); do [ -S "$E/run/mysql.sock" ] && alive "$E/run/mysqld.pid" && break; sleep 0.5; done
    fi
    [ "${2:-}" = "--mysql-only" ] && { bash "$0" status; exit 0; }
    # Another server (for example an older copy of the same stack) already holds the port: refuse.
    if ! web_alive && [ "$(curl -s -o /dev/null -w '%{http_code}' --max-time 3 "$B/" 2>/dev/null)" != "000" ]; then
        echo "REFUSED: 127.0.0.1:$SBQA_PORT is already served by another process" >&2; exit 2
    fi
    if [ "$SBQA_LSWS" = "1" ]; then
        if ! ols_alive; then
            docker rm -f "$SBQA_OLS_NAME" > /dev/null 2>&1
            # Only what the server needs, at the same paths: the stack folder itself stays 0700 on the host, while
            # the folders Docker creates above the mounts let the server user (nobody) reach the document root.
            docker run -d --name "$SBQA_OLS_NAME" --network host -v "$E/wordpress:$E/wordpress" -v "$E/run:$E/run" \
                -v "$E/logs:$E/logs" -v "$E/conf/lsphp:$E/conf/lsphp:ro" -v "$E/conf/ols:/usr/local/lsws/conf:ro" \
                --entrypoint sh "$SBQA_OLS_IMAGE" -c '/usr/local/lsws/bin/lswsctrl start; while /usr/local/lsws/bin/lswsctrl status | grep -q "running with PID"; do sleep 2; done' > /dev/null
            # 503 until lsphp has started.
            for i in $(seq 1 60); do case "$(curl -s -o /dev/null -w '%{http_code}' --max-time 3 "$B/wp-login.php")" in 000|503) sleep 0.5 ;; *) break ;; esac; done
        fi
        bash "$0" status; exit 0
    fi
    if ! alive "$E/run/php-fpm.pid"; then
        setsid env LD_LIBRARY_PATH="$P/shared-libs" "$P/sbin/php-fpm" -n -c "$E/conf/php.ini" -y "$E/conf/php-fpm.conf" -F > /dev/null 2>&1 < /dev/null &
        sleep 1
    fi
    if ! alive "$E/run/$WEB.pid"; then
        if [ "$SBQA_WEB" = "apache" ]; then
            $HTTPD -f "$E/conf/httpd.conf" -k start
            for i in $(seq 1 20); do alive "$E/run/httpd.pid" && break; sleep 0.25; done
        else
            "$NGX" -p "$E/run" -c "$E/conf/nginx.conf"
        fi
    fi
    bash "$0" status
    ;;
stop)
    if [ "$SBQA_LSWS" = "1" ]; then
        docker rm -f "$SBQA_OLS_NAME" > /dev/null 2>&1
    elif [ "$SBQA_WEB" = "apache" ]; then
        alive "$E/run/httpd.pid" && $HTTPD -f "$E/conf/httpd.conf" -k stop
    else
        alive "$E/run/nginx.pid" && "$NGX" -s quit -p "$E/run" -c "$E/conf/nginx.conf"
    fi
    alive "$E/run/php-fpm.pid" && kill -QUIT "$(cat "$E/run/php-fpm.pid")"
    alive "$E/run/mysqld.pid" && env LD_LIBRARY_PATH="$M/lib" "$M/bin/mysqladmin" --no-defaults -uroot --socket="$E/run/mysql.sock" shutdown
    for i in $(seq 1 40); do alive "$E/run/mysqld.pid" || alive "$E/run/php-fpm.pid" || web_alive || break; sleep 0.25; done
    bash "$0" status
    ;;
status)
    if [ "$SBQA_LSWS" = "1" ]; then
        alive "$E/run/mysqld.pid" && echo "mysqld: running (pid $(cat "$E/run/mysqld.pid"))" || echo "mysqld: stopped"
        ols_alive && echo "openlitespeed: running (container $SBQA_OLS_NAME)" || echo "openlitespeed: stopped"
    else
        for s in mysqld php-fpm $WEB; do alive "$E/run/$s.pid" && echo "$s: running (pid $(cat "$E/run/$s.pid"))" || echo "$s: stopped"; done
    fi
    CODE=$(curl -s -o /dev/null -w '%{http_code}' --max-time 3 $B/wp-login.php 2>/dev/null)
    if web_alive || [ "$CODE" = "000" ]; then echo "$B/ -> $CODE"; else echo "$B/ -> $CODE from ANOTHER process (not this stack)"; fi
    ;;
*)
    echo "Usage: [SBQA_STACK=nginx|apache|nginx71|ff-nginx|ff-apache|wc-nginx|wc-apache|litespeed] bash stack.sh start [--mysql-only]|stop|status"; exit 2 ;;
esac
