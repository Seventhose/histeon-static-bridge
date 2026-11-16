# Sourced by every harness script: selects the disposable stack they talk to and refuses anything else.
#   SBQA_STACK=nginx  (default) wp69/  — WordPress 6.9, Nginx 1.26.1 on 127.0.0.1:18697, PHP-FPM on 127.0.0.1:18698
#   SBQA_STACK=apache           wp69a/ — WordPress 6.9, Apache 2.4.43 on 127.0.0.1:18699, PHP-FPM on a private unix socket
#   SBQA_STACK=nginx71          wp71/  — WordPress 7.1.3, Nginx 1.26.1 on 127.0.0.1:18731, PHP-FPM on 127.0.0.1:18732
#   SBQA_STACK=ff-nginx         wpff/  — WordPress 6.9 + Fluent Forms, Nginx on 127.0.0.1:18721, PHP-FPM on 127.0.0.1:18722
#   SBQA_STACK=ff-apache        wpffa/ — WordPress 6.9 + Fluent Forms, Apache on 127.0.0.1:18723, PHP-FPM on a unix socket
#   SBQA_STACK=wc-nginx         wpwc/  — WordPress 7.1.3 + WooCommerce, Nginx on 127.0.0.1:18741, PHP-FPM on 127.0.0.1:18742
#   SBQA_STACK=wc-apache        wpwca/ — WordPress 7.1.3 + WooCommerce, Apache on 127.0.0.1:18743, PHP-FPM on a unix socket
#   SBQA_STACK=litespeed        wp69l/ — WordPress 6.9, OpenLiteSpeed 1.9.4 + lsphp 8.4 (Docker image, host network) on 127.0.0.1:18751
# SBQA_WEB says which kind of rules the plugin writes (nginx or apache: LiteSpeed reads the .htaccess as Apache does);
# SBQA_LSWS=1 says the server is OpenLiteSpeed.
#
# Every stack lives in $SBQA_HOME/<env> (default tests/e2e/stacks, gitignored) and is built by
# setup-stack.sh, which writes the marker file <env>/.sbqa-stack. Scripts refuse a stack folder
# without that exact marker. Each stack has its own WordPress copy, MySQL datadir/socket and
# database; none touches LocalWP sites. Optional overrides: tests/e2e/local.env (gitignored).
SBQA_E2E=$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd -P)
SBQA_REPO=$(cd "$SBQA_E2E/../.." && pwd -P)
[ -f "$SBQA_E2E/local.env" ] && . "$SBQA_E2E/local.env"
SBQA_STACK=${SBQA_STACK:-nginx}
SBQA_FF=0
SBQA_WC=0
SBQA_LSWS=0
case "$SBQA_STACK" in
nginx)     SBQA_ENV_NAME=wp69;  SBQA_PORT=18697; SBQA_DB=sb_claude_wp69;  SBQA_WEB=nginx;  SBQA_WP=6.9 ;;
apache)    SBQA_ENV_NAME=wp69a; SBQA_PORT=18699; SBQA_DB=sb_claude_wp69a; SBQA_WEB=apache; SBQA_WP=6.9 ;;
nginx71)   SBQA_ENV_NAME=wp71;  SBQA_PORT=18731; SBQA_DB=sb_claude_wp71;  SBQA_WEB=nginx;  SBQA_WP=7.1.3 ;;
ff-nginx)  SBQA_ENV_NAME=wpff;  SBQA_PORT=18721; SBQA_DB=sb_claude_ff;    SBQA_WEB=nginx;  SBQA_WP=6.9; SBQA_FF=1 ;;
ff-apache) SBQA_ENV_NAME=wpffa; SBQA_PORT=18723; SBQA_DB=sb_claude_ffa;   SBQA_WEB=apache; SBQA_WP=6.9; SBQA_FF=1 ;;
wc-nginx)  SBQA_ENV_NAME=wpwc;  SBQA_PORT=18741; SBQA_DB=sb_claude_wc;    SBQA_WEB=nginx;  SBQA_WP=7.1.3; SBQA_WC=1 ;;
wc-apache) SBQA_ENV_NAME=wpwca; SBQA_PORT=18743; SBQA_DB=sb_claude_wca;   SBQA_WEB=apache; SBQA_WP=7.1.3; SBQA_WC=1 ;;
litespeed) SBQA_ENV_NAME=wp69l; SBQA_PORT=18751; SBQA_DB=sb_claude_wp69l; SBQA_WEB=apache; SBQA_WP=6.9; SBQA_LSWS=1 ;;
*) echo "SBQA_STACK must be 'nginx', 'apache', 'nginx71', 'ff-nginx', 'ff-apache', 'wc-nginx', 'wc-apache' or 'litespeed'"; exit 2 ;;
esac

# Stack data root: absolute, never inside LocalWP, short enough for the unix sockets (108 bytes).
SBQA_HOME=${SBQA_HOME:-$SBQA_E2E/stacks}
case "$SBQA_HOME" in /?*) ;; *) echo "REFUSED: SBQA_HOME must be an absolute path ($SBQA_HOME)"; exit 2 ;; esac
[ -d "$SBQA_HOME" ] && SBQA_HOME=$(cd "$SBQA_HOME" && pwd -P)
SBQA_HOME=${SBQA_HOME%/}
case "$SBQA_HOME/" in
"$HOME/" | "$HOME/Local Sites/"* | "$HOME/.config/Local/"* | "$SBQA_REPO/" | "$SBQA_REPO/plugin/"* | "$SBQA_REPO/dist/"*)
    echo "REFUSED: SBQA_HOME may not be $SBQA_HOME"; exit 2 ;;
esac
[ "${#SBQA_HOME}" -le 86 ] || { echo "REFUSED: SBQA_HOME is ${#SBQA_HOME} characters; the MySQL/PHP-FPM unix sockets under it need <= 86 (set SBQA_HOME to a shorter absolute path)"; exit 2; }
SBQA_E=$SBQA_HOME/$SBQA_ENV_NAME
export SBQA_STACK SBQA_WEB SBQA_LSWS SBQA_HOME

# The selected stack folder, only if setup-stack.sh built it for exactly this stack.
sbqa_require_stack() {
    [ -f "$SBQA_E/.sbqa-stack" ] && [ "$(cat "$SBQA_E/.sbqa-stack")" = "$SBQA_STACK" ] && [ -f "$SBQA_E/wordpress/wp-load.php" ] \
        || { echo "REFUSED: $SBQA_E is not a stack built by setup-stack.sh for SBQA_STACK=$SBQA_STACK (build it with: bash tests/e2e/setup-stack.sh $SBQA_STACK)"; exit 2; }
}

B=http://127.0.0.1:$SBQA_PORT
LW=${SBQA_LOCALWP:-$HOME/.config/Local/lightning-services}
P=$LW/php-8.4.18+1/bin/linux
M=$LW/mysql-8.4.0+2/bin/linux
NGX=$LW/nginx-1.26.1+3/bin/linux/sbin/nginx
APX=$LW/apache-2.4.43+11/bin/linux
HTTPD="env LD_LIBRARY_PATH=$APX/shared-libs $APX/bin/httpd"
# OpenLiteSpeed (litespeed stack): the official image, pinned by digest; the container is named after the stack.
SBQA_OLS_IMAGE=${SBQA_OLS_IMAGE:-litespeedtech/openlitespeed:1.9.4-lsphp84@sha256:84b5a648789f0c4ba1da9ffe3cb3ba79ae30a9c5c95f1e6a7f9302f8b273f2f8}
SBQA_OLS_NAME=sbqa-ols-$SBQA_ENV_NAME
# On the litespeed stack $HTTPD answers -v and -t for OpenLiteSpeed (ols.sh), so the Apache branches of the checks run there too.
[ "$SBQA_LSWS" = "1" ] && HTTPD="bash $SBQA_E2E/ols.sh"
