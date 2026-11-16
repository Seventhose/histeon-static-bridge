#!/usr/bin/env bash
# The object-cache drop-in against real Redis and Memcached, started in Docker on loopback ports and
# removed at the end. PHP is LocalWP's 8.4.18 with redis.so and memcached.so built by build-php-ext.sh.
# Usage: bash tests/e2e/object-cache-e2e.sh
set -u
H=$(cd "$(dirname "$0")" && pwd)
SBQA_STACK=nginx . "$H/profile.sh"
X=$SBQA_HOME/sources/php-ext-8.4
[ -f "$X/redis.so" ] && [ -f "$X/memcached.so" ] || { echo "Missing $X/redis.so or memcached.so: run bash tests/e2e/build-php-ext.sh"; exit 2; }
P=$LW/php-8.4.18+1/bin/linux
PHPX="env LD_LIBRARY_PATH=$P/shared-libs:$X $P/bin/php -n -d extension=$X/redis.so -d extension=$X/memcached.so"
PASSWORD=$(head -c 18 /dev/urandom | base64 | tr -dc 'A-Za-z0-9')
NAMES="sbqa-redis sbqa-redis-auth sbqa-memcached sbqa-redis-sock sbqa-memcached-sock"
SOCK=''
cleanup() { docker rm -f $NAMES > /dev/null 2>&1; [ -n "$SOCK" ] && rm -rf "$SOCK"; return 0; }
trap cleanup EXIT
cleanup
# Unix sockets in a short folder (a socket path is at most 107 bytes), shared with the containers.
SOCK=$(mktemp -d /tmp/sbqa-oc.XXXXXX); chmod 777 "$SOCK"
docker run -d --name sbqa-redis -p 127.0.0.1:16379:6379 redis:7-alpine > /dev/null
docker run -d --name sbqa-redis-auth -p 127.0.0.1:16380:6379 redis:7-alpine redis-server --requirepass "$PASSWORD" > /dev/null
docker run -d --name sbqa-memcached -p 127.0.0.1:11311:11211 memcached:1.6-alpine > /dev/null
docker run -d --name sbqa-redis-sock --network none -v "$SOCK:/sock" redis:7-alpine redis-server --port 0 --unixsocket /sock/redis.sock --unixsocketperm 777 > /dev/null
docker run -d --name sbqa-memcached-sock --network none -v "$SOCK:/sock" memcached:1.6-alpine memcached -s /sock/memcached.sock -a 0777 > /dev/null
for i in $(seq 1 40); do
    docker exec sbqa-redis redis-cli ping 2>/dev/null | grep -q PONG && docker exec sbqa-redis-auth redis-cli -a "$PASSWORD" --no-auth-warning ping 2>/dev/null | grep -q PONG && break
    sleep 0.25
done
for i in $(seq 1 40); do [ -S "$SOCK/redis.sock" ] && [ -S "$SOCK/memcached.sock" ] && break; sleep 0.25; done
$PHPX "$H/object-cache-backend.php" 16379 11311 16380 "$PASSWORD" "$SOCK/redis.sock" "$SOCK/memcached.sock"
