<?php
/**
 * Installs and removes Static Bridge's object-cache.php drop-in (Redis or Memcached).
 *
 * Only a file that carries the drop-in's signature is ever replaced or removed: a drop-in from another
 * plugin or from the hosting stays untouched. Each installation gets a key prefix of its own, so a new
 * installation never reads values written before it (and two sites on one server never share keys).
 */

if (!defined('ABSPATH')) {
    exit;
}

final class Static_Bridge_Object_Cache
{
    const SIGNATURE = 'Static-Bridge-Object-Cache-Dropin';
    const PROBE_OPTION = 'static_bridge_object_cache_probe';
    const PROBE_ACTION = 'static_bridge_object_cache_probe';

    public static function dropin_path()
    {
        return WP_CONTENT_DIR . '/object-cache.php';
    }

    /** 'none', 'ours' or 'foreign'. */
    public static function dropin_state($path = null)
    {
        $path = null === $path ? self::dropin_path() : (string) $path;
        if (!file_exists($path) && !is_link($path)) {
            return 'none';
        }
        if (is_link($path) || !is_file($path)) {
            return 'foreign';
        }
        $head = @file_get_contents($path, false, null, 0, 2048);
        return is_string($head) && false !== strpos($head, self::SIGNATURE) ? 'ours' : 'foreign';
    }

    /** Why the drop-in cannot be installed here, '' when it can. */
    public static function unavailable_reason(?array $service = null)
    {
        if (function_exists('is_multisite') && is_multisite()) {
            return __('Non disponibile su multisite.', 'static-bridge');
        }
        if ('foreign' === self::dropin_state()) {
            return __('C’è già un file object-cache.php di un altro plugin o dell’hosting: Static Bridge non lo sostituisce.', 'static-bridge');
        }
        if (null === $service || empty($service['reachable'])) {
            return __('Né Redis né Memcached rispondono su questo server.', 'static-bridge');
        }
        if (!wp_is_writable(WP_CONTENT_DIR)) {
            return __('WordPress non può scrivere nella cartella wp-content.', 'static-bridge');
        }
        return '';
    }

    /** The drop-in's configuration for a service found by Static_Bridge_Server_Check::object_cache_services(). */
    public static function config_for(array $service)
    {
        global $table_prefix;
        $site = (defined('DB_NAME') ? DB_NAME : '') . '|' . (string) $table_prefix . '|' . strtolower((string) wp_parse_url(home_url('/'), PHP_URL_HOST));
        return array(
            'type' => 'Memcached' === $service['name'] ? 'memcached' : 'redis',
            'host' => (string) $service['host'],
            'port' => (int) $service['port'],
            'auth' => !empty($service['auth']),
            'database' => isset($service['database']) ? (int) $service['database'] : 0,
            // Site, then installation: a reinstall starts from an empty cache.
            'prefix' => substr(md5($site), 0, 10) . '.' . strtolower(wp_generate_password(6, false, false)),
        );
    }

    /** The drop-in source with $config written into its configuration line. */
    public static function render_dropin(array $config, $template = null)
    {
        $template = null === $template ? (string) file_get_contents(__DIR__ . '/object-cache-dropin.php') : (string) $template;
        // The template must not carry a plugin header (WP would list it among the plugins); the drop-in needs it.
        $template = (string) preg_replace('/^ \* Drop-in Name: /m', ' * Plugin Name: ', $template, 1);
        $allowed = array(
            'type' => in_array($config['type'] ?? '', array('redis', 'memcached'), true) ? $config['type'] : 'redis',
            'host' => preg_replace('/[^A-Za-z0-9.:\/_\[\]-]/', '', (string) ($config['host'] ?? '')),
            'port' => max(0, min(65535, (int) ($config['port'] ?? 0))),
            'auth' => !empty($config['auth']),
            'database' => max(0, min(255, (int) ($config['database'] ?? 0))),
            'prefix' => preg_replace('/[^a-z0-9.]/', '', (string) ($config['prefix'] ?? '')),
        );
        $line = '$GLOBALS[\'static_bridge_object_cache_config\'] = ' . var_export($allowed, true) . ';';
        $rendered = preg_replace('~/\*SB-CONFIG\*/\R.*?\R/\*SB-CONFIG\*/~s', "/*SB-CONFIG*/\n" . str_replace(array('\\', '$'), array('\\\\', '\\$'), $line) . "\n/*SB-CONFIG*/", $template, 1, $count);
        return 1 === $count ? $rendered : '';
    }

    /**
     * Writes the drop-in, asks the site over HTTP whether it now uses it, and takes it away again if not.
     * $request (2.5.0): how to send the probe, function ($url, $args); wp_safe_remote_get() when not callable.
     *
     * @return true|WP_Error
     */
    public static function install(array $service, $request = null)
    {
        $reason = self::unavailable_reason($service);
        if ('' !== $reason) {
            return new WP_Error('static_bridge_object_cache_unavailable', $reason);
        }
        $source = self::render_dropin(self::config_for($service));
        if ('' === $source) {
            return new WP_Error('static_bridge_object_cache_template', __('Il modello del drop-in non è leggibile.', 'static-bridge'));
        }
        $written = self::write(self::dropin_path(), $source);
        if (is_wp_error($written)) {
            return $written;
        }
        $probe = self::probe($request);
        if (true !== $probe) {
            self::remove();
            return $probe;
        }
        return true;
    }

    /** @return true|WP_Error */
    public static function remove()
    {
        $state = self::dropin_state();
        if ('none' === $state) {
            return true;
        }
        if ('ours' !== $state) {
            return new WP_Error('static_bridge_object_cache_foreign', __('Il file object-cache.php non è di Static Bridge: resta dov’è.', 'static-bridge'));
        }
        if (!@unlink(self::dropin_path())) {
            return new WP_Error('static_bridge_object_cache_remove', __('Il file object-cache.php non si può togliere: toglilo a mano da wp-content.', 'static-bridge'));
        }
        if (function_exists('opcache_invalidate')) {
            @opcache_invalidate(self::dropin_path(), true);
        }
        return true;
    }

    private static function write($path, $source)
    {
        if ('foreign' === self::dropin_state($path)) {
            return new WP_Error('static_bridge_object_cache_foreign', __('C’è già un file object-cache.php di un altro plugin o dell’hosting: Static Bridge non lo sostituisce.', 'static-bridge'));
        }
        $temporary = $path . '.static-bridge-' . bin2hex(random_bytes(4)) . '.tmp';
        if (strlen($source) !== @file_put_contents($temporary, $source, LOCK_EX)) {
            @unlink($temporary);
            return new WP_Error('static_bridge_object_cache_write', __('Scrittura di object-cache.php non riuscita.', 'static-bridge'));
        }
        @chmod($temporary, 0644);
        if (!@rename($temporary, $path)) {
            @unlink($temporary);
            return new WP_Error('static_bridge_object_cache_write', __('Scrittura di object-cache.php non riuscita.', 'static-bridge'));
        }
        if (function_exists('opcache_invalidate')) {
            @opcache_invalidate($path, true);
        }
        return true;
    }

    /**
     * A fresh request to the site, which loads the new drop-in, reports whether it is connected.
     * A fatal error in the drop-in shows here as HTTP 500, before anyone else meets it.
     * $request (2.5.0): as in install().
     *
     * @return true|WP_Error
     */
    public static function probe($request = null)
    {
        $token = wp_generate_password(32, false, false);
        update_option(self::PROBE_OPTION, array('hash' => hash('sha256', $token), 'expires' => time() + 60), false);
        $url = add_query_arg(array('action' => self::PROBE_ACTION, 'token' => $token), admin_url('admin-ajax.php'));
        $host = strtolower((string) wp_parse_url(home_url('/'), PHP_URL_HOST));
        $args = array(
            'timeout' => 15,
            'redirection' => 0,
            'sslverify' => !preg_match('/(?:\.local|\.test|\.localhost)$/i', $host),
        );
        // 2.5.0: $request is the plugin's self_request(), which adds the Basic Auth header on this site only.
        $response = is_callable($request) ? call_user_func($request, $url, $args) : wp_safe_remote_get($url, $args);
        delete_option(self::PROBE_OPTION);
        if (is_wp_error($response)) {
            return new WP_Error('static_bridge_object_cache_probe', sprintf(
                /* translators: %s: error message */
                __('Il sito non ha risposto alla prova della cache: %s. Il drop-in è stato tolto.', 'static-bridge'),
                $response->get_error_message()
            ));
        }
        $status = (int) wp_remote_retrieve_response_code($response);
        $data = json_decode((string) wp_remote_retrieve_body($response), true);
        if (200 !== $status || !is_array($data) || empty($data['success']) || !is_array($data['data'] ?? null)) {
            return new WP_Error('static_bridge_object_cache_probe', sprintf(
                /* translators: %s: HTTP status code */
                __('Con il drop-in il sito ha risposto HTTP %s: il drop-in è stato tolto e il sito è tornato com’era.', 'static-bridge'),
                $status
            ));
        }
        if (empty($data['data']['ours']) || empty($data['data']['connected'])) {
            return new WP_Error('static_bridge_object_cache_probe', __('Il drop-in si è caricato ma non è riuscito a collegarsi al servizio: è stato tolto.', 'static-bridge'));
        }
        return true;
    }

    /** The answer of the probe request: no output unless the token of a probe in progress matches. */
    public static function probe_response()
    {
        $token = isset($_GET['token']) && is_string($_GET['token']) ? (string) wp_unslash($_GET['token']) : '';
        $probe = get_option(self::PROBE_OPTION, array());
        if ('' === $token || !is_array($probe) || empty($probe['hash']) || (int) ($probe['expires'] ?? 0) < time()
            || !hash_equals((string) $probe['hash'], hash('sha256', $token))) {
            wp_send_json_error(null, 403);
        }
        global $wp_object_cache;
        wp_send_json_success(array(
            'ours' => $wp_object_cache instanceof Static_Bridge_Object_Cache_Backend,
            'connected' => $wp_object_cache instanceof Static_Bridge_Object_Cache_Backend && !empty($wp_object_cache->connected) && wp_using_ext_object_cache(),
        ));
    }
}
