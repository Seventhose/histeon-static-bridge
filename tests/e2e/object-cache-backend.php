<?php
/**
 * The object-cache drop-in against real Redis and Memcached services (object-cache-e2e.sh starts them in
 * Docker on loopback ports). WordPress is not loaded: the drop-in is self-contained, so a few stubs are enough.
 *
 * Usage: php object-cache-backend.php <redis-port> <memcached-port> <redis-password-port> <password> [<redis-socket> <memcached-socket>]
 * With the two sockets the same cases run on unix sockets (port 0), as on hosts that offer Redis or Memcached that way.
 */
declare(strict_types=1);

[$redisPort, $memcachedPort, $authPort, $password] = array((int) ($argv[1] ?? 0), (int) ($argv[2] ?? 0), (int) ($argv[3] ?? 0), (string) ($argv[4] ?? ''));
[$redisSocket, $memcachedSocket] = array((string) ($argv[5] ?? ''), (string) ($argv[6] ?? ''));
$root = sys_get_temp_dir() . '/sb-object-cache-' . getmypid();
@mkdir($root . '/wp-content/static-bridge', 0777, true);
define('ABSPATH', $root . '/');
define('WP_CONTENT_DIR', $root . '/wp-content');
define('WP_REDIS_PASSWORD', $password);

$GLOBALS['ext_cache_calls'] = array();
function get_current_blog_id() { return 1; }
function wp_using_ext_object_cache($using = null) { if (null !== $using) { $GLOBALS['ext_cache_calls'][] = $using; } return true; }
function wp_suspend_cache_addition() { return false; }
function esc_html($text) { return htmlspecialchars((string) $text); }

$pass = 0;
$fail = 0;
function ok(bool $condition, string $label): void
{
    global $pass, $fail;
    $condition ? $pass++ : $fail++;
    echo ($condition ? '[PASS] ' : '[FAIL] ') . $label . PHP_EOL;
}

function __($text, $domain = null) { return $text; }
// As at installation: the template rendered with its configuration, then loaded as WordPress loads it.
require dirname(__DIR__, 2) . '/plugin/object-cache.php';
$rendered = Static_Bridge_Object_Cache::render_dropin(array('type' => 'redis', 'host' => '127.0.0.1', 'port' => $redisPort, 'prefix' => 'boot'));
file_put_contents(WP_CONTENT_DIR . '/object-cache.php', $rendered);
(function () { require WP_CONTENT_DIR . '/object-cache.php'; })();
ok(array('type' => 'redis', 'host' => '127.0.0.1', 'port' => $redisPort, 'auth' => false, 'database' => 0, 'prefix' => 'boot') === $GLOBALS['static_bridge_object_cache_config'], 'the configuration written at installation is a global even when the file is included from a function');
ok(function_exists('wp_cache_init') && class_exists('Static_Bridge_Object_Cache_Backend'), 'the drop-in defines the cache API when its extension is loaded');

function backend(array $config): Static_Bridge_Object_Cache_Backend
{
    @unlink(WP_CONTENT_DIR . '/static-bridge/.object-cache-down');
    return new Static_Bridge_Object_Cache_Backend($config);
}

$endpoints = array('redis' => array('redis', '127.0.0.1', $redisPort), 'memcached' => array('memcached', '127.0.0.1', $memcachedPort));
if ('' !== $redisSocket && '' !== $memcachedSocket) {
    $endpoints['redis on a unix socket'] = array('redis', $redisSocket, 0);
    $endpoints['memcached on a unix socket'] = array('memcached', $memcachedSocket, 0);
}
foreach ($endpoints as $type => [$service, $host, $port]) {
    $config = array('type' => $service, 'host' => $host, 'port' => $port, 'prefix' => 'site-a.' . bin2hex(random_bytes(3)));
    $cache = backend($config);
    ok($cache->connected, "$type: connected");

    ok($cache->set('k', array('a' => 1), 'g') && array('a' => 1) === backend($config)->get('k', 'g'), "$type: set in one request, read in the next");
    $found = null;
    ok(false === backend($config)->get('missing', 'g', false, $found) && false === $found, "$type: a missing key is a miss");
    ok($cache->set('false', false, 'g') && false === backend($config)->get('false', 'g', false, $found) && true === $found, "$type: a stored false is found");
    ok(false === $cache->add('k', 'other', 'g') && array('a' => 1) === backend($config)->get('k', 'g'), "$type: add does not overwrite");
    ok(false === $cache->replace('absent', 1, 'g') && $cache->replace('k', 2, 'g') && 2 === backend($config)->get('k', 'g'), "$type: replace only an existing key");
    ok($cache->delete('k', 'g') && false === backend($config)->get('k', 'g', false, $found) && false === $found, "$type: delete");

    $object = new stdClass();
    $object->n = 1;
    $cache->set('object', $object, 'g');
    $object->n = 2;
    $copy = $cache->get('object', 'g');
    $copy->n = 3;
    ok(1 === $cache->get('object', 'g')->n && 1 === backend($config)->get('object', 'g')->n, "$type: objects are copies, in memory and in the service");

    $cache->set_multiple(array('m1' => 'one', 'm2' => 'two'), 'g');
    ok(array('m1' => 'one', 'm2' => 'two', 'm3' => false) === backend($config)->get_multiple(array('m1', 'm2', 'm3'), 'g'), "$type: get_multiple, with a miss");

    $cache->add_non_persistent_groups(array('counts'));
    $cache->set('c', 5, 'counts');
    $other = backend($config);
    $other->add_non_persistent_groups(array('counts'));
    ok(5 === $cache->get('c', 'counts') && false === $other->get('c', 'counts'), "$type: a non-persistent group stays in the request");

    $cache->set('n', 5, 'g');
    ok(8 === $cache->incr('n', 3, 'g') && 0 === $cache->decr('n', 20, 'g') && 0 === backend($config)->get('n', 'g'), "$type: incr and decr, never below zero");

    $spaced = 'a key with spaces ' . str_repeat('x', 300);
    ok($cache->set($spaced, 'long', 'g') && 'long' === backend($config)->get($spaced, 'g'), "$type: a long key with spaces");

    $cache->switch_to_blog(2);
    $cache->set('blog', 'two', 'g');
    $cache->add_global_groups(array('users'));
    $cache->set('u', 'global', 'users');
    $blog1 = backend($config);
    $blog1->add_global_groups(array('users'));
    ok(false === $blog1->get('blog', 'g') && 'global' === $blog1->get('u', 'users'), "$type: blogs are separate, global groups are shared");

    $cache->switch_to_blog(1);
    $cache->set('keep', 'yes', 'keepgroup');
    $cache->set('drop', 'yes', 'dropgroup');
    ok($cache->flush_group('dropgroup') && false === backend($config)->get('drop', 'dropgroup') && 'yes' === backend($config)->get('keep', 'keepgroup'), "$type: flush_group empties that group only");

    $b_config = array('prefix' => 'site-b.' . bin2hex(random_bytes(3))) + $config;
    backend($b_config)->set('keep', 'b', 'keepgroup');
    ok($cache->flush() && false === backend($config)->get('keep', 'keepgroup'), "$type: flush empties this site");
    ok('b' === backend($b_config)->get('keep', 'keepgroup'), "$type: flushing one site leaves another site on the same server alone");

    $cache->set('short', 'x', 'g', 1);
    sleep(2);
    ok(false === backend($config)->get('short', 'g'), "$type: an expiry is honoured");
}

$GLOBALS['ext_cache_calls'] = array();
$down = backend(array('type' => 'redis', 'host' => '127.0.0.1', 'port' => 1, 'prefix' => 'down'));
ok(!$down->connected && is_file(WP_CONTENT_DIR . '/static-bridge/.object-cache-down'), 'redis down: not connected, and the pause is noted');
ok($down->set('k', 'v', 'g') && 'v' === $down->get('k', 'g'), 'redis down: the request still has its in-memory cache');
$started = microtime(true);
$paused = new Static_Bridge_Object_Cache_Backend(array('type' => 'redis', 'host' => '127.0.0.1', 'port' => 1, 'prefix' => 'down'));
ok(!$paused->connected && microtime(true) - $started < 0.05, 'redis down: the next request does not wait for it again');
$GLOBALS['static_bridge_object_cache_config'] = array('type' => 'redis', 'host' => '127.0.0.1', 'port' => 1, 'prefix' => 'down');
wp_cache_init();
ok(array(false) === $GLOBALS['ext_cache_calls'], 'redis down: wp_cache_init gives the transients back to the database');

$wrong = backend(array('type' => 'redis', 'host' => '127.0.0.1', 'port' => $authPort, 'prefix' => 'auth'));
ok(!$wrong->connected, 'redis with a password: refused without it');
$right = backend(array('type' => 'redis', 'host' => '127.0.0.1', 'port' => $authPort, 'auth' => true, 'prefix' => 'auth'));
ok($right->connected && $right->set('k', 'v', 'g') && 'v' === backend(array('type' => 'redis', 'host' => '127.0.0.1', 'port' => $authPort, 'auth' => true, 'prefix' => 'auth'))->get('k', 'g'), 'redis with a password: works with WP_REDIS_PASSWORD');

// WP_REDIS_DATABASE: the drop-in selects the database the host gives; another database does not see the keys.
$prefix = 'db.' . bin2hex(random_bytes(3));
$third = backend(array('type' => 'redis', 'host' => '127.0.0.1', 'port' => $redisPort, 'database' => 3, 'prefix' => $prefix));
ok($third->connected && $third->set('k', 'three', 'g') && 'three' === backend(array('type' => 'redis', 'host' => '127.0.0.1', 'port' => $redisPort, 'database' => 3, 'prefix' => $prefix))->get('k', 'g'), 'redis database 3: set and read');
ok(false === backend(array('type' => 'redis', 'host' => '127.0.0.1', 'port' => $redisPort, 'database' => 0, 'prefix' => $prefix))->get('k', 'g'), 'redis database 3: database 0 does not see the key');

echo "OBJECT CACHE BACKEND: $pass passed, $fail failed" . PHP_EOL;
exit($fail ? 1 : 0);
