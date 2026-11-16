<?php
/**
 * Retention QA helper (Static Bridge 2.0.13) for the disposable stack selected by SBQA_STACK.
 * Boots WordPress only after the same exact root/database/socket checks as wp69-harness.php.
 *
 * Modes (all JSON):
 *   state                         releases, current, protections, scheduled retention, last result
 *   backdate <release> <seconds>  QA only: move a release folder's mtime (and .ready) into the past
 *   last-release <release>        QA only: point static_bridge_last_release at <release> (imported-DB case)
 *   index-ref <release>|clear     QA only: create/remove a dependency-index state row for <release>
 *   cron-now                      QA only: move the scheduled retention event to now (then hit wp-cron.php)
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli' || PHP_VERSION_ID < 80400 || PHP_VERSION_ID >= 80500) {
    fwrite(STDERR, "Run with the PHP 8.4 CLI.\n");
    exit(2);
}
require __DIR__ . '/profile.php';
$envRoot = SBQA_ENV ?: false;
$root = $envRoot ? realpath($envRoot . '/wordpress') : false;
$mode = (string) ($argv[1] ?? '');
$arg = (string) ($argv[2] ?? '');
if (!$root || !is_file($root . '/wp-load.php')
    || !in_array($mode, array('state', 'backdate', 'last-release', 'index-ref', 'cron-now', 'legacy-settings'), true)) {
    fwrite(STDERR, "REFUSED: unknown mode or QA root missing.\n");
    exit(2);
}
$_SERVER['SERVER_SOFTWARE'] = SBQA_PROFILE['server_software'];
$_SERVER['HTTP_HOST'] = '127.0.0.1:' . SBQA_PROFILE['port'];
$_SERVER['SERVER_NAME'] = '127.0.0.1';
$_SERVER['SERVER_PORT'] = (string) SBQA_PROFILE['port'];
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REQUEST_URI'] = '/';
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
require $root . '/wp-load.php';
global $wpdb;
$checks = array(
    'root_exact' => realpath(ABSPATH) === $root,
    'database_exact' => SBQA_PROFILE['db'] === (string) $wpdb->get_var('SELECT DATABASE()'),
    'socket_exact' => realpath((string) $wpdb->get_var('SELECT @@socket')) === realpath($envRoot . '/run/mysql.sock'),
    'home_loopback' => BASE === home_url(),
    'plugin_loaded' => 'state' === $mode || 'cron-now' === $mode || class_exists('Static_Bridge_Plugin', false),
);
if (in_array(false, $checks, true)) {
    echo json_encode(array('result' => 'REFUSED', 'checks' => $checks)), PHP_EOL;
    exit(2);
}
$releases = WP_CONTENT_DIR . '/static-bridge/releases';
$valid = static function (string $name) use ($releases): string {
    $path = $releases . '/' . $name;
    if (!preg_match('/\A[A-Za-z0-9_-]{1,100}\z/D', $name) || is_link($path) || !is_dir($path)) {
        fwrite(STDERR, "REFUSED: {$name} is not a release folder.\n");
        exit(2);
    }
    return $path;
};

switch ($mode) {
    case 'state':
        clearstatcache();
        $list = array();
        foreach (array_diff(scandir($releases) ?: array(), array('.', '..')) as $name) {
            $path = $releases . '/' . $name;
            $list[$name] = array(
                'link' => is_link($path),
                'dir' => is_dir($path) && !is_link($path),
                'ready' => !is_link($path) && is_file($path . '/.ready'),
                'mtime' => is_link($path) ? 0 : (int) @filemtime(is_file($path . '/.ready') ? $path . '/.ready' : $path),
            );
        }
        $current = realpath(WP_CONTENT_DIR . '/static-bridge/current');
        $last = get_option('static_bridge_last_release', array());
        // Literal option names: this mode also runs while the plugin is deactivated.
        $job = get_option('static_bridge_job', array());
        $settings = get_option('static_bridge_settings', array());
        $activity = (array) get_option('static_bridge_activity', array());
        echo json_encode(array(
            'result' => 'PASS',
            'current' => $current ? basename($current) : '',
            'releases' => $list,
            'last_release' => is_array($last) ? (string) ($last['id'] ?? '') : '',
            'job' => is_array($job) ? array('status' => $job['status'] ?? '', 'release_id' => $job['release_id'] ?? '') : null,
            'retention_enabled' => (int) !empty($settings['retention_enabled']),
            'retention_keep' => isset($settings['retention_keep']) ? (int) $settings['retention_keep'] : null,
            'next_retention' => (int) wp_next_scheduled('static_bridge_release_retention'),
            'now' => time(),
            'last_retention' => get_option('static_bridge_retention_last', null),
            'activity_tail' => array_slice($activity, -6),
        ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), PHP_EOL;
        break;
    case 'backdate':
        $path = $valid($arg);
        $seconds = (int) ($argv[3] ?? 0);
        if ($seconds < 1) {
            exit(2);
        }
        $time = time() - $seconds;
        if (is_file($path . '/.ready') && !is_link($path . '/.ready')) {
            touch($path . '/.ready', $time);
        }
        touch($path, $time);
        echo json_encode(array('result' => 'PASS', 'release' => $arg, 'mtime' => $time)), PHP_EOL;
        break;
    case 'last-release':
        $valid($arg);
        $last = get_option('static_bridge_last_release', array());
        $last = is_array($last) ? $last : array();
        $last['id'] = $arg;
        update_option('static_bridge_last_release', $last, false);
        echo json_encode(array('result' => 'PASS', 'last_release' => $arg)), PHP_EOL;
        break;
    case 'index-ref':
        $index = new Static_Bridge_Dependency_Index();
        if ('clear' === $arg) {
            $ok = $index->install() && false !== $wpdb->query("DELETE FROM {$wpdb->prefix}static_bridge_v2_state")
                && false !== $wpdb->query("DELETE FROM {$wpdb->prefix}static_bridge_v2_routes")
                && false !== $wpdb->query("DELETE FROM {$wpdb->prefix}static_bridge_v2_edges");
        } else {
            $valid($arg);
            $ok = $index->install() && $index->begin_release($arg);
        }
        $keys = $index->referenced_release_keys();
        echo json_encode(array('result' => $ok ? 'PASS' : 'FAIL', 'referenced_keys' => $keys)), PHP_EOL;
        exit($ok ? 0 : 1);
    case 'legacy-settings':
        // (QA) settings saved by a version before 2.4.0 that never touched retention: no retention keys at all.
        $stored = get_option('static_bridge_settings', array());
        $stored = is_array($stored) ? $stored : array();
        unset($stored['retention_enabled'], $stored['retention_keep']);
        update_option('static_bridge_settings', $stored, false);
        wp_clear_scheduled_hook('static_bridge_release_retention');
        echo json_encode(array('result' => 'PASS')), PHP_EOL;
        break;
    case 'cron-now':
        $next = wp_next_scheduled('static_bridge_release_retention');
        if (!$next) {
            echo json_encode(array('result' => 'FAIL', 'message' => 'no retention event scheduled')), PHP_EOL;
            exit(1);
        }
        wp_clear_scheduled_hook('static_bridge_release_retention');
        wp_schedule_single_event(time() - 1, 'static_bridge_release_retention');
        echo json_encode(array('result' => 'PASS', 'was' => $next, 'now' => time())), PHP_EOL;
        break;
}
