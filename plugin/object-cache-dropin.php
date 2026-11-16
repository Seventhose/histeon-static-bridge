<?php
/**
 * Drop-in Name: Static Bridge Object Cache
 * Description: Object cache persistente su Redis o Memcached, installata dalla scheda Server di Static Bridge. Si toglie dalla stessa scheda; se il servizio non risponde, WordPress continua senza cache esterna.
 * Version: 1
 *
 * Static-Bridge-Object-Cache-Dropin
 *
 * Self-contained on purpose: it reads nothing from the database and does not need the Static Bridge
 * plugin, so it keeps working (or keeps getting out of the way) whatever the plugin's state.
 * The configuration below is written by Static Bridge when the drop-in is installed.
 *
 * This file is the template: its first header line reads «Drop-in Name», and render_dropin() turns it into the
 * plugin name header WP shows among the drop-ins. With that header here, WP listed the template itself among the
 * plugins of the site (2.3.0 to 2.4.4), where activating it does nothing.
 */

defined('ABSPATH') || exit;

// WordPress includes this file from inside a function: the configuration has to be a real global.
/*SB-CONFIG*/
$GLOBALS['static_bridge_object_cache_config'] = array();
/*SB-CONFIG*/

/*
 * Nothing is defined, and WordPress keeps its own per-request cache, when the drop-in is switched off
 * with STATIC_BRIDGE_OBJECT_CACHE_DISABLED in wp-config.php, when PHP lost the extension, or on multisite
 * (WordPress starts the object cache twice there, and a second start after a fallback would be fatal).
 */
$static_bridge_object_cache_type = isset($GLOBALS['static_bridge_object_cache_config']['type']) ? (string) $GLOBALS['static_bridge_object_cache_config']['type'] : '';
if (!(defined('STATIC_BRIDGE_OBJECT_CACHE_DISABLED') && STATIC_BRIDGE_OBJECT_CACHE_DISABLED)
    && !(defined('MULTISITE') && MULTISITE)
    && (('redis' === $static_bridge_object_cache_type && class_exists('Redis'))
        || ('memcached' === $static_bridge_object_cache_type && class_exists('Memcached')))) {
    final class Static_Bridge_Object_Cache_Backend
    {
        /** Keys with no expiry still expire after this many seconds: a flush leaves old keys behind. */
        const MAX_TTL = 86400;
        const TIMEOUT = 0.5;
        /** Seconds without trying again after the service did not answer. */
        const DOWN_PAUSE = 60;

        private $config;
        /** @var Redis|Memcached|null */
        private $client = null;
        private $type = '';
        public $connected = false;
        private $prefix = '';
        /** Version of every key (flush) and of each group (flush_group): one entry of $versions. */
        private $versions = array('*' => 1);
        private $cache = array();
        private $global_groups = array();
        private $non_persistent_groups = array();
        private $blog_prefix = '1';
        public $cache_hits = 0;
        public $cache_misses = 0;

        public function __construct(array $config)
        {
            $this->config = $config;
            $this->blog_prefix = function_exists('get_current_blog_id') ? (string) get_current_blog_id() : '1';
            $salt = defined('WP_CACHE_KEY_SALT') ? (string) WP_CACHE_KEY_SALT : '';
            $this->prefix = 'sbc:' . (isset($config['prefix']) ? (string) $config['prefix'] : 'site') . ('' !== $salt ? ':' . md5($salt) : '') . ':';
            if (!$this->paused()) {
                $this->connect();
            }
        }

        private function down_file()
        {
            return WP_CONTENT_DIR . '/static-bridge/.object-cache-down';
        }

        private function paused()
        {
            $mtime = @filemtime($this->down_file());
            return false !== $mtime && time() - $mtime < self::DOWN_PAUSE;
        }

        private function mark_down()
        {
            $this->connected = false;
            $this->client = null;
            if (is_dir(dirname($this->down_file()))) {
                @touch($this->down_file());
            }
        }

        private function connect()
        {
            $type = isset($this->config['type']) ? (string) $this->config['type'] : '';
            $host = isset($this->config['host']) ? (string) $this->config['host'] : '';
            $port = isset($this->config['port']) ? (int) $this->config['port'] : 0;
            try {
                if ('redis' === $type && class_exists('Redis')) {
                    $client = new Redis();
                    if (!@$client->connect($host, $port, self::TIMEOUT, null, 0, self::TIMEOUT)) {
                        $this->mark_down();
                        return;
                    }
                    // The password stays in wp-config.php: the drop-in reads the host's constant, it never stores it.
                    if (!empty($this->config['auth']) && defined('WP_REDIS_PASSWORD') && '' !== WP_REDIS_PASSWORD && !$client->auth(WP_REDIS_PASSWORD)) {
                        $this->mark_down();
                        return;
                    }
                    if (isset($this->config['database']) && (int) $this->config['database'] > 0 && !$client->select((int) $this->config['database'])) {
                        $this->mark_down();
                        return;
                    }
                    $client->setOption(Redis::OPT_SERIALIZER, Redis::SERIALIZER_NONE);
                    $this->client = $client;
                } elseif ('memcached' === $type && class_exists('Memcached')) {
                    $client = new Memcached();
                    $client->setOption(Memcached::OPT_CONNECT_TIMEOUT, (int) (self::TIMEOUT * 1000));
                    $client->setOption(Memcached::OPT_SEND_TIMEOUT, (int) (self::TIMEOUT * 1000000));
                    $client->setOption(Memcached::OPT_RECV_TIMEOUT, (int) (self::TIMEOUT * 1000000));
                    $client->setOption(Memcached::OPT_POLL_TIMEOUT, (int) (self::TIMEOUT * 1000));
                    $client->setOption(Memcached::OPT_SERIALIZER, Memcached::SERIALIZER_PHP);
                    $client->addServer($host, $port);
                    $this->client = $client;
                } else {
                    return;
                }
                $this->type = $type;
                $this->connected = true;
                $this->load_versions();
            } catch (Throwable $error) {
                $this->mark_down();
            }
        }

        // ------------------------------------------------------------- versions

        private function versions_key()
        {
            return $this->prefix . 'versions';
        }

        private function load_versions()
        {
            if ('redis' === $this->type) {
                // An empty hash comes back as array(); false is an error reply (NOAUTH, wrong database…).
                $versions = $this->client->hGetAll($this->versions_key());
                if (!is_array($versions)) {
                    $this->mark_down();
                    return;
                }
            } else {
                $versions = $this->client->get($this->versions_key());
                if (false === $versions && Memcached::RES_NOTFOUND !== $this->client->getResultCode()) {
                    $this->mark_down();
                    return;
                }
            }
            if (false === $versions) {
                $versions = array();
            }
            if (!is_array($versions)) {
                $this->mark_down();
                return;
            }
            $this->versions = array('*' => isset($versions['*']) ? (int) $versions['*'] : 1);
            foreach ($versions as $group => $version) {
                $this->versions[(string) $group] = (int) $version;
            }
        }

        /** Raises the version of '*' (every key) or of one group; old keys are never read again and expire. */
        private function bump($what)
        {
            if (!$this->connected) {
                return false;
            }
            try {
                if ('redis' === $this->type) {
                    $version = $this->client->hIncrBy($this->versions_key(), $what, 1);
                    if (1 === (int) $version) {
                        // A missing entry counts as 1: the first bump has to land on 2.
                        $version = $this->client->hIncrBy($this->versions_key(), $what, 1);
                    }
                    $this->versions[$what] = (int) $version;
                    return false !== $version;
                }
                for ($attempt = 0; $attempt < 5; $attempt++) {
                    $cas = null;
                    $current = $this->client->get($this->versions_key(), null, Memcached::GET_EXTENDED);
                    if (false === $current) {
                        $versions = $this->versions;
                        $versions[$what] = (isset($versions[$what]) ? $versions[$what] : 1) + 1;
                        if ($this->client->add($this->versions_key(), $versions)) {
                            $this->versions = $versions;
                            return true;
                        }
                        continue;
                    }
                    $versions = is_array($current['value']) ? $current['value'] : array();
                    $versions[$what] = (isset($versions[$what]) ? (int) $versions[$what] : 1) + 1;
                    if ($this->client->cas($current['cas'], $this->versions_key(), $versions)) {
                        $this->versions = array_map('intval', $versions) + array('*' => 1);
                        return true;
                    }
                }
            } catch (Throwable $error) {
                $this->mark_down();
            }
            return false;
        }

        // ------------------------------------------------------------- keys

        private function key($key, $group)
        {
            $scope = isset($this->global_groups[$group]) ? 'g' : $this->blog_prefix;
            $group_version = isset($this->versions[$group]) ? $this->versions[$group] : 1;
            $name = $this->prefix . $this->versions['*'] . ':' . $scope . ':' . $group . '.' . $group_version . ':' . $key;
            // Memcached refuses keys over 250 bytes or with spaces and control characters.
            if ('memcached' === $this->type && (strlen($name) > 250 || preg_match('/[\s\x00-\x1f\x7f]/', $name))) {
                $name = $this->prefix . 'h:' . md5($name);
            }
            return $name;
        }

        private function persistent($group)
        {
            return $this->connected && !isset($this->non_persistent_groups[$group]);
        }

        private static function group($group)
        {
            return '' === (string) $group ? 'default' : (string) $group;
        }

        private static function valid_key($key)
        {
            return (is_string($key) && '' !== trim($key)) || is_int($key);
        }

        private static function copy($value)
        {
            return is_object($value) ? clone $value : $value;
        }

        private static function ttl($expire)
        {
            $expire = (int) $expire;
            return $expire > 0 ? min($expire, self::MAX_TTL) : self::MAX_TTL;
        }

        private function remote_get(array $keys)
        {
            try {
                if ('redis' === $this->type) {
                    $values = $this->client->mGet(array_values($keys));
                    $out = array();
                    foreach (array_values($keys) as $index => $name) {
                        if (is_array($values) && isset($values[$index]) && false !== $values[$index]) {
                            $out[$name] = unserialize($values[$index]);
                        }
                    }
                    return $out;
                }
                $values = $this->client->getMulti(array_values($keys));
                if (false === $values) {
                    if (Memcached::RES_NOTFOUND !== $this->client->getResultCode()) {
                        $this->mark_down();
                    }
                    return array();
                }
                return $values;
            } catch (Throwable $error) {
                $this->mark_down();
                return array();
            }
        }

        /** $mode: set, add (only if absent) or replace (only if present). */
        private function remote_set($name, $value, $expire, $mode)
        {
            try {
                if ('redis' === $this->type) {
                    $options = array('EX' => self::ttl($expire));
                    if ('add' === $mode) {
                        $options[] = 'NX';
                    } elseif ('replace' === $mode) {
                        $options[] = 'XX';
                    }
                    return (bool) $this->client->set($name, serialize($value), $options);
                }
                $ttl = self::ttl($expire);
                if ('add' === $mode) {
                    return $this->client->add($name, $value, $ttl);
                }
                if ('replace' === $mode) {
                    return $this->client->replace($name, $value, $ttl);
                }
                return $this->client->set($name, $value, $ttl);
            } catch (Throwable $error) {
                $this->mark_down();
                return false;
            }
        }

        private function remote_delete($name)
        {
            try {
                return 'redis' === $this->type ? $this->client->del($name) > 0 : $this->client->delete($name);
            } catch (Throwable $error) {
                $this->mark_down();
                return false;
            }
        }

        // ------------------------------------------------------------- WP_Object_Cache API

        public function get($key, $group = 'default', $force = false, &$found = null)
        {
            $group = self::group($group);
            if (!self::valid_key($key)) {
                $found = false;
                return false;
            }
            if (!$force && isset($this->cache[$group]) && array_key_exists($key, $this->cache[$group])) {
                $found = true;
                ++$this->cache_hits;
                return self::copy($this->cache[$group][$key]);
            }
            if (!$this->persistent($group)) {
                $found = false;
                ++$this->cache_misses;
                return false;
            }
            $name = $this->key($key, $group);
            $values = $this->remote_get(array($name));
            if (!array_key_exists($name, $values)) {
                $found = false;
                ++$this->cache_misses;
                return false;
            }
            $found = true;
            ++$this->cache_hits;
            $this->cache[$group][$key] = $values[$name];
            return self::copy($values[$name]);
        }

        public function get_multiple($keys, $group = 'default', $force = false)
        {
            $group = self::group($group);
            $out = array();
            $missing = array();
            foreach ((array) $keys as $key) {
                if (!$force && isset($this->cache[$group]) && array_key_exists($key, $this->cache[$group])) {
                    $out[$key] = self::copy($this->cache[$group][$key]);
                    ++$this->cache_hits;
                } elseif (self::valid_key($key) && $this->persistent($group)) {
                    $missing[$this->key($key, $group)] = $key;
                    $out[$key] = false;
                } else {
                    $out[$key] = false;
                    ++$this->cache_misses;
                }
            }
            if (!empty($missing)) {
                $values = $this->remote_get(array_keys($missing));
                foreach ($missing as $name => $key) {
                    if (array_key_exists($name, $values)) {
                        $this->cache[$group][$key] = $values[$name];
                        $out[$key] = self::copy($values[$name]);
                        ++$this->cache_hits;
                    } else {
                        ++$this->cache_misses;
                    }
                }
            }
            return $out;
        }

        private function store($key, $value, $group, $expire, $mode)
        {
            $group = self::group($group);
            if (!self::valid_key($key)) {
                return false;
            }
            if (function_exists('wp_suspend_cache_addition') && 'add' === $mode && wp_suspend_cache_addition()) {
                return false;
            }
            $known = isset($this->cache[$group]) && array_key_exists($key, $this->cache[$group]);
            if ($this->persistent($group)) {
                if (!$this->remote_set($this->key($key, $group), $value, $expire, $mode)) {
                    if ('set' !== $mode || $this->connected) {
                        return false;
                    }
                }
            } elseif (('add' === $mode && $known) || ('replace' === $mode && !$known)) {
                return false;
            }
            $this->cache[$group][$key] = self::copy($value);
            return true;
        }

        public function add($key, $value, $group = 'default', $expire = 0)
        {
            return $this->store($key, $value, $group, $expire, 'add');
        }

        public function replace($key, $value, $group = 'default', $expire = 0)
        {
            return $this->store($key, $value, $group, $expire, 'replace');
        }

        public function set($key, $value, $group = 'default', $expire = 0)
        {
            return $this->store($key, $value, $group, $expire, 'set');
        }

        public function add_multiple(array $data, $group = '', $expire = 0)
        {
            $out = array();
            foreach ($data as $key => $value) {
                $out[$key] = $this->add($key, $value, $group, $expire);
            }
            return $out;
        }

        public function set_multiple(array $data, $group = '', $expire = 0)
        {
            $out = array();
            foreach ($data as $key => $value) {
                $out[$key] = $this->set($key, $value, $group, $expire);
            }
            return $out;
        }

        public function delete($key, $group = 'default')
        {
            $group = self::group($group);
            if (!self::valid_key($key)) {
                return false;
            }
            $known = isset($this->cache[$group]) && array_key_exists($key, $this->cache[$group]);
            unset($this->cache[$group][$key]);
            if ($this->persistent($group)) {
                return $this->remote_delete($this->key($key, $group)) || $known;
            }
            return $known;
        }

        public function delete_multiple(array $keys, $group = '')
        {
            $out = array();
            foreach ($keys as $key) {
                $out[$key] = $this->delete($key, $group);
            }
            return $out;
        }

        public function incr($key, $offset = 1, $group = 'default')
        {
            $group = self::group($group);
            $value = $this->get($key, $group, true, $found);
            if (!$found) {
                return false;
            }
            $value = (is_numeric($value) ? (int) $value : 0) + (int) $offset;
            $value = max(0, $value);
            return $this->set($key, $value, $group) ? $value : false;
        }

        public function decr($key, $offset = 1, $group = 'default')
        {
            return $this->incr($key, -1 * (int) $offset, $group);
        }

        public function flush()
        {
            $this->cache = array();
            return $this->connected ? $this->bump('*') : true;
        }

        public function flush_runtime()
        {
            $this->cache = array();
            return true;
        }

        public function flush_group($group)
        {
            $group = self::group($group);
            unset($this->cache[$group]);
            return $this->persistent($group) ? $this->bump($group) : true;
        }

        public function add_global_groups($groups)
        {
            foreach ((array) $groups as $group) {
                $this->global_groups[(string) $group] = true;
            }
        }

        public function add_non_persistent_groups($groups)
        {
            foreach ((array) $groups as $group) {
                $this->non_persistent_groups[(string) $group] = true;
            }
        }

        public function switch_to_blog($blog_id)
        {
            $this->blog_prefix = (string) (int) $blog_id;
        }

        public function close()
        {
            try {
                if ('redis' === $this->type && $this->client) {
                    $this->client->close();
                } elseif ('memcached' === $this->type && $this->client) {
                    $this->client->quit();
                }
            } catch (Throwable $error) {
                // Closing a connection that already failed: nothing to do.
            }
            return true;
        }

        public function stats()
        {
            echo '<p><strong>Static Bridge Object Cache</strong> (' . esc_html($this->type) . '): '
                . (int) $this->cache_hits . ' hit, ' . (int) $this->cache_misses . ' miss.</p>';
        }
    }

    function wp_cache_init()
    {
        global $wp_object_cache;
        $config = isset($GLOBALS['static_bridge_object_cache_config']) && is_array($GLOBALS['static_bridge_object_cache_config']) ? $GLOBALS['static_bridge_object_cache_config'] : array();
        $wp_object_cache = new Static_Bridge_Object_Cache_Backend($config);
        // Without the service, transients go back to the database as without a drop-in: they must not vanish.
        if (!$wp_object_cache->connected && function_exists('wp_using_ext_object_cache')) {
            wp_using_ext_object_cache(false);
        }
    }

    function wp_cache_add($key, $data, $group = '', $expire = 0)
    {
        global $wp_object_cache;
        return $wp_object_cache->add($key, $data, $group, (int) $expire);
    }

    function wp_cache_add_multiple(array $data, $group = '', $expire = 0)
    {
        global $wp_object_cache;
        return $wp_object_cache->add_multiple($data, $group, (int) $expire);
    }

    function wp_cache_replace($key, $data, $group = '', $expire = 0)
    {
        global $wp_object_cache;
        return $wp_object_cache->replace($key, $data, $group, (int) $expire);
    }

    function wp_cache_set($key, $data, $group = '', $expire = 0)
    {
        global $wp_object_cache;
        return $wp_object_cache->set($key, $data, $group, (int) $expire);
    }

    function wp_cache_set_multiple(array $data, $group = '', $expire = 0)
    {
        global $wp_object_cache;
        return $wp_object_cache->set_multiple($data, $group, (int) $expire);
    }

    function wp_cache_get($key, $group = '', $force = false, &$found = null)
    {
        global $wp_object_cache;
        return $wp_object_cache->get($key, $group, $force, $found);
    }

    function wp_cache_get_multiple($keys, $group = '', $force = false)
    {
        global $wp_object_cache;
        return $wp_object_cache->get_multiple($keys, $group, $force);
    }

    function wp_cache_delete($key, $group = '')
    {
        global $wp_object_cache;
        return $wp_object_cache->delete($key, $group);
    }

    function wp_cache_delete_multiple(array $keys, $group = '')
    {
        global $wp_object_cache;
        return $wp_object_cache->delete_multiple($keys, $group);
    }

    function wp_cache_incr($key, $offset = 1, $group = '')
    {
        global $wp_object_cache;
        return $wp_object_cache->incr($key, $offset, $group);
    }

    function wp_cache_decr($key, $offset = 1, $group = '')
    {
        global $wp_object_cache;
        return $wp_object_cache->decr($key, $offset, $group);
    }

    function wp_cache_flush()
    {
        global $wp_object_cache;
        return $wp_object_cache->flush();
    }

    function wp_cache_flush_runtime()
    {
        global $wp_object_cache;
        return $wp_object_cache->flush_runtime();
    }

    function wp_cache_flush_group($group)
    {
        global $wp_object_cache;
        return $wp_object_cache->flush_group($group);
    }

    function wp_cache_supports($feature)
    {
        return in_array($feature, array('add_multiple', 'set_multiple', 'get_multiple', 'delete_multiple', 'flush_runtime', 'flush_group'), true);
    }

    function wp_cache_close()
    {
        global $wp_object_cache;
        return $wp_object_cache->close();
    }

    function wp_cache_add_global_groups($groups)
    {
        global $wp_object_cache;
        $wp_object_cache->add_global_groups($groups);
    }

    function wp_cache_add_non_persistent_groups($groups)
    {
        global $wp_object_cache;
        $wp_object_cache->add_non_persistent_groups($groups);
    }

    function wp_cache_switch_to_blog($blog_id)
    {
        global $wp_object_cache;
        $wp_object_cache->switch_to_blog($blog_id);
    }

    function wp_cache_reset()
    {
        _deprecated_function(__FUNCTION__, '3.5.0', 'wp_cache_switch_to_blog()');
        return false;
    }
}
