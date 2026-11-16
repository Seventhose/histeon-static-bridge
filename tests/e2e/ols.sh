#!/usr/bin/env bash
# $HTTPD of the litespeed stack (profile.sh): the two Apache calls the checks make, answered for OpenLiteSpeed.
#   -v       "Server version: <name/version>" as httpd -v prints it (from the image, no network)
#   -t ...   the running server answers HTTP (OpenLiteSpeed has no config test; the .htaccess is read per request)
set -u
H=$(cd "$(dirname "$0")" && pwd)
. "$H/profile.sh"
case "${1:-}" in
-v) V=$(docker run --rm --network none --entrypoint sh "$SBQA_OLS_IMAGE" -c 'cat /usr/local/lsws/VERSION' 2>/dev/null | tr -d '\r\n')
    echo "Server version: OpenLiteSpeed/${V:-unknown}" ;;
-t) [ "$(docker inspect -f '{{.State.Running}}' "$SBQA_OLS_NAME" 2>/dev/null)" = "true" ] \
        && [ "$(curl -s -o /dev/null -w '%{http_code}' --max-time 5 "$B/wp-login.php")" = "200" ] ;;
*) echo "Usage: bash ols.sh -v | -t"; exit 2 ;;
esac
