<?php
/**
 * Deletes Static Bridge through WordPress's own delete_plugins() (the same path
 * as Plugins > Delete, which runs uninstall.php) on the disposable stack
 * selected by SBQA_STACK, and reports what is left.
 * Usage: php uninstall-e2e.php before|delete|after   (PHP 8.4 CLI)
 */
declare(strict_types=1);

require __DIR__ . '/profile.php';
$mode = (string) ($argv[1] ?? '');
$root = realpath(SBQA_ENV . '/wordpress');
if (!$root || !in_array($mode, array('before', 'delete', 'after'), true)) {
    fwrite(STDERR, "Usage: php uninstall-e2e.php before|delete|after\n");
    exit(2);
}
$_SERVER['SERVER_SOFTWARE'] = SBQA_PROFILE['server_software'];
$_SERVER['HTTP_HOST'] = '127.0.0.1:' . SBQA_PROFILE['port'];
$_SERVER['REQUEST_URI'] = '/wp-admin/plugins.php';
$_SERVER['REQUEST_METHOD'] = 'GET';
define('WP_ADMIN', true);
require $root . '/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';
require_once ABSPATH . 'wp-admin/includes/file.php';
global $wpdb;
if (SBQA_PROFILE['db'] !== (string) $wpdb->get_var('SELECT DATABASE()') || realpath(ABSPATH) !== $root) {
    fwrite(STDERR, "REFUSED: not the disposable QA stack.\n");
    exit(2);
}

$plugin = 'static-bridge/static-bridge.php';
$state = static function () use ($wpdb, $plugin): array {
    $cron = array();
    foreach ((array) _get_cron_array() as $events) {
        foreach (array_keys((array) $events) as $hook) {
            if (0 === strpos((string) $hook, 'static_bridge')) {
                $cron[] = $hook;
            }
        }
    }
    $htaccess = (string) @file_get_contents(ABSPATH . '.htaccess');
    return array(
        'plugin_files' => is_dir(WP_PLUGIN_DIR . '/static-bridge'),
        'plugin_active' => is_plugin_active($plugin),
        'options' => (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s", $wpdb->esc_like('static_bridge_') . '%', $wpdb->esc_like('_transient_static_bridge_') . '%')),
        'tables' => array_values((array) $wpdb->get_col($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($wpdb->prefix . 'static_bridge') . '%'))),
        'cron_hooks' => array_values(array_unique($cron)),
        'static_root' => file_exists(WP_CONTENT_DIR . '/static-bridge') || is_link(WP_CONTENT_DIR . '/static-bridge'),
        'htaccess_static_bridge_blocks' => substr_count($htaccess, '# BEGIN Static Bridge'),
        'htaccess_wordpress_block' => false !== strpos($htaccess, '# BEGIN WordPress'),
    );
};

if ('delete' === $mode) {
    if (is_plugin_active($plugin)) {
        deactivate_plugins($plugin, false, false);
    }
    // Leftover events from a previous run are part of what uninstall must clean.
    wp_schedule_single_event(time() + 3600, 'static_bridge_process_incremental_queue');
    $result = delete_plugins(array($plugin));
    echo json_encode(array('delete_plugins' => is_wp_error($result) ? $result->get_error_code() . ': ' . $result->get_error_message() : $result), JSON_UNESCAPED_SLASHES), PHP_EOL;
    exit(true === $result ? 0 : 1);
}
echo json_encode($state(), JSON_UNESCAPED_SLASHES), PHP_EOL;
