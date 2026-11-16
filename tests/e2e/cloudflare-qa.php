<?php
/**
 * Cloudflare QA helper (Static Bridge 2.0.17) for the disposable stack selected by SBQA_STACK.
 * Boots WordPress only after exact root/database/socket checks; the site address must be the
 * stack's own loopback origin or its QA fake edge (origin port + 13). All JSON.
 *
 * Modes:
 *   home edge|origin                  (QA) point home at the fake edge (via the QA mu-plugin) or back
 *   settings key=value ...            (QA) set render_origin, static_cache_control, cloudflare_purge,
 *                                     strict_mode; then rewrite .htaccess (Apache) or the Nginx include
 *   state                             settings, pending/last purge, scheduled purge, activity tail
 *   run-purge                         run the purge cron callback now
 *   diagnostic <type>...              run Diagnostica steps (e.g. cloudflare-edge cloudflare-markup render-origin)
 *   mark <post_id> <marker>           append a QA marker paragraph to a post
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
if (!$root || !is_file($root . '/wp-load.php') || !in_array($mode, array('home', 'settings', 'state', 'run-purge', 'diagnostic', 'mark'), true)) {
    fwrite(STDERR, "REFUSED: unknown mode or QA root missing.\n");
    exit(2);
}
$edgeBase = 'http://127.0.0.1:' . (SBQA_PROFILE['port'] + 13);
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
    'home_is_origin_or_qa_edge' => in_array(home_url(), array(BASE, $edgeBase), true),
    'plugin_loaded' => class_exists('Static_Bridge_Plugin', false),
);
if (in_array(false, $checks, true)) {
    echo json_encode(array('result' => 'REFUSED', 'checks' => $checks, 'home' => home_url())), PHP_EOL;
    exit(2);
}
$bridge = null;
foreach (($GLOBALS['wp_filter']['admin_menu']->callbacks ?? array()) as $callbacks) {
    foreach ($callbacks as $entry) {
        if (is_array($entry['function']) && $entry['function'][0] instanceof Static_Bridge_Plugin) {
            $bridge = $entry['function'][0];
        }
    }
}
$call = static function (string $method, array $arguments = array()) use ($bridge) {
    $reflection = new ReflectionMethod(Static_Bridge_Plugin::class, $method);
    return $reflection->invokeArgs($bridge, $arguments);
};
$out = static function (array $data, bool $ok = true): void {
    echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), PHP_EOL;
    exit($ok ? 0 : 1);
};
if (!$bridge) {
    $out(array('result' => 'FAIL', 'reason' => 'plugin instance not found'), false);
}

switch ($mode) {
    case 'home':
        $target = 'edge' === ($argv[2] ?? '') ? $edgeBase : ('origin' === ($argv[2] ?? '') ? BASE : '');
        if ('' === $target) {
            $out(array('result' => 'REFUSED', 'reason' => 'home edge|origin'), false);
        }
        // Applied by the QA mu-plugin on the next request (wp-config.php pins WP_HOME).
        $flag = $envRoot . '/run/cf/home-edge';
        if ($target === BASE) {
            @unlink($flag);
        } elseif (!is_dir(dirname($flag)) || !is_file(WP_CONTENT_DIR . '/mu-plugins/sbqa-cloudflare-mu.php')
            || false === file_put_contents($flag, $target)) {
            $out(array('result' => 'FAIL', 'reason' => 'QA mu-plugin or run/cf missing'), false);
        }
        $out(array('result' => 'PASS', 'home' => $target));
        // no break
    case 'settings':
        $settings = get_option(Static_Bridge_Plugin::SETTINGS_OPTION, array());
        $settings = is_array($settings) ? $settings : array();
        foreach (array_slice($argv, 2) as $pair) {
            [$key, $value] = array_pad(explode('=', (string) $pair, 2), 2, '');
            if (!in_array($key, array('render_origin', 'static_cache_control', 'cloudflare_purge', 'strict_mode'), true)) {
                $out(array('result' => 'REFUSED', 'reason' => 'key ' . $key), false);
            }
            $settings[$key] = in_array($key, array('cloudflare_purge', 'strict_mode'), true) ? (int) $value : $value;
        }
        update_option(Static_Bridge_Plugin::SETTINGS_OPTION, $settings, false);
        $call('sync_strict_marker', array(!empty($call('settings')['strict_mode'])));
        $rules = $call('update_routing_rules');
        $block = '';
        if ('nginx' === SBQA_PROFILE['web']) {
            $block = (string) $call('nginx_router_block');
            file_put_contents($envRoot . '/conf/static-bridge.conf', $block);
        }
        $effective = $call('settings');
        $out(array('result' => $rules ? 'PASS' : 'FAIL',
            'effective' => array_intersect_key($effective, array_flip(array('render_origin', 'static_cache_control', 'cloudflare_purge', 'strict_mode'))),
            'nginx_block_sha256' => '' !== $block ? hash('sha256', $block) : null), (bool) $rules);
        // no break
    case 'state':
        $activity = (array) get_option(Static_Bridge_Plugin::ACTIVITY_OPTION, array());
        $out(array(
            'result' => 'PASS',
            'home' => home_url(),
            'settings' => array_intersect_key($call('settings'), array_flip(array('render_origin', 'static_cache_control', 'cloudflare_purge', 'strict_mode'))),
            'purge_config' => $call('cloudflare_purge_config'),
            'pending' => get_option(Static_Bridge_Plugin::CLOUDFLARE_PURGE_OPTION, null),
            'last' => get_option(Static_Bridge_Plugin::CLOUDFLARE_PURGE_STATUS_OPTION, null),
            'purge_scheduled' => wp_next_scheduled(Static_Bridge_Plugin::CLOUDFLARE_PURGE_HOOK),
            'incremental_queue' => count((array) get_option(Static_Bridge_Plugin::INCREMENTAL_QUEUE_OPTION, array())),
            'job_status' => (get_option(Static_Bridge_Plugin::JOB_OPTION, array())['status'] ?? null),
            'current' => basename((string) realpath(WP_CONTENT_DIR . '/static-bridge/current')),
            'activity' => array_slice($activity, -12),
        ));
        // no break
    case 'run-purge':
        do_action(Static_Bridge_Plugin::CLOUDFLARE_PURGE_HOOK);
        $out(array('result' => 'PASS', 'last' => get_option(Static_Bridge_Plugin::CLOUDFLARE_PURGE_STATUS_OPTION, null), 'pending' => get_option(Static_Bridge_Plugin::CLOUDFLARE_PURGE_OPTION, null)));
        // no break
    case 'diagnostic':
        $current = realpath(WP_CONTENT_DIR . '/static-bridge/current');
        $run = array('release' => $current ? basename($current) : '');
        $results = array();
        foreach (array_slice($argv, 2) as $type) {
            $results[$type] = $call('run_diagnostic_step', array(array('id' => $type, 'type' => $type), $run));
        }
        $out(array('result' => 'PASS', 'steps' => $results));
        // no break
    case 'mark':
        $postId = (int) ($argv[2] ?? 0);
        $marker = preg_replace('/[^A-Za-z0-9_-]/', '', (string) ($argv[3] ?? ''));
        $post = get_post($postId);
        if (!$post || '' === $marker) {
            $out(array('result' => 'FAIL', 'reason' => 'post_or_marker_missing'), false);
        }
        $updated = wp_update_post(array('ID' => $postId, 'post_content' => $post->post_content . "\n<!-- wp:paragraph -->\n<p>" . $marker . "</p>\n<!-- /wp:paragraph -->"), true);
        $out(array('result' => is_wp_error($updated) ? 'FAIL' : 'PASS', 'post_id' => $postId, 'permalink' => get_permalink($postId)), !is_wp_error($updated));
}
