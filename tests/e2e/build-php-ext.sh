#!/usr/bin/env bash
# Builds redis.so and memcached.so for PHP 8.4.18 (the version of the stacks: an extension built on a later 8.4 can need symbols 8.4.18 lacks) in the official php:8.4.18-cli-bookworm image,
# with the libmemcached libraries they need, into $SBQA_HOME/sources/php-ext-8.4 (ignored by Git).
# LocalWP's PHP 8.4.18 loads them; nothing is installed on the machine.
set -u
H=$(cd "$(dirname "$0")" && pwd)
SBQA_STACK=nginx . "$H/profile.sh"
OUT=$SBQA_HOME/sources/php-ext-8.4
mkdir -p "$OUT"
docker run --rm -v "$OUT:/out" php:8.4.18-cli-bookworm sh -c '
set -e
apt-get update -qq > /dev/null && apt-get install -y -qq libmemcached-dev zlib1g-dev libssl-dev pkg-config > /dev/null 2>&1
printf "\n" | pecl install -f redis > /dev/null 2>&1
yes "" | pecl install -f memcached > /dev/null 2>&1
D=$(php-config --extension-dir); cp "$D/redis.so" "$D/memcached.so" /out/
for l in $(ldd "$D/memcached.so" | awk "/=>/ {print \$3}"); do case $l in */libmemcached*|*/libhashkit*|*/libsasl2*) cp -L "$l" /out/ ;; esac; done
chown -R '"$(id -u):$(id -g)"' /out'
P=$LW/php-8.4.18+1/bin/linux
env LD_LIBRARY_PATH="$P/shared-libs:$OUT" "$P/bin/php" -n -d extension="$OUT/redis.so" -d extension="$OUT/memcached.so" -r 'echo "redis ", phpversion("redis"), ", memcached ", phpversion("memcached"), PHP_EOL;'
