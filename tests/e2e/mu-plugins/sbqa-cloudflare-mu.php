<?php
/**
 * Plugin Name: SBQA Cloudflare fake (QA only)
 * Description: Copied into the disposable stack by cloudflare-e2e.sh and removed at the end.
 * Defines the QA Cloudflare constants (as wp-config.php would) from run/cf/credentials.json and
 * diverts every request to api.cloudflare.com to the local fake API; without the fake it fails
 * closed, so the disposable stack can never reach the real Cloudflare API.
 * While run/cf/home-edge holds the fake edge URL, home points at it (the stack's wp-config.php
 * pins WP_HOME, applied as an option_home filter). siteurl stays on the origin: auth cookie
 * names derive from it, and wp-admin/wp-cron keep working on the loopback origin.
 */
if (!defined('ABSPATH')) {
    exit;
}
$sbqa_cf_state = dirname(rtrim(ABSPATH, '/')) . '/run/cf';
$sbqa_cf_credentials = json_decode((string) @file_get_contents($sbqa_cf_state . '/credentials.json'), true);
if (is_array($sbqa_cf_credentials) && !empty($sbqa_cf_credentials['zone']) && !empty($sbqa_cf_credentials['token'])) {
    if (!defined('STATIC_BRIDGE_CLOUDFLARE_ZONE_ID')) {
        define('STATIC_BRIDGE_CLOUDFLARE_ZONE_ID', (string) $sbqa_cf_credentials['zone']);
    }
    if (!defined('STATIC_BRIDGE_CLOUDFLARE_API_TOKEN')) {
        define('STATIC_BRIDGE_CLOUDFLARE_API_TOKEN', (string) $sbqa_cf_credentials['token']);
    }
}
$sbqa_cf_edge = trim((string) @file_get_contents($sbqa_cf_state . '/home-edge'));
if (preg_match('#^http://127\.0\.0\.1:[0-9]{2,5}$#', $sbqa_cf_edge)) {
    add_filter('option_home', static function () use ($sbqa_cf_edge) {
        return $sbqa_cf_edge;
    }, 99);
}
add_filter('pre_http_request', static function ($pre, $args, $url) use ($sbqa_cf_state) {
    if ('api.cloudflare.com' !== strtolower((string) parse_url((string) $url, PHP_URL_HOST))) {
        return $pre;
    }
    $port = trim((string) @file_get_contents($sbqa_cf_state . '/api-port'));
    if (!preg_match('/^[0-9]{2,5}$/', $port)) {
        return new WP_Error('sbqa_cloudflare_blocked', 'QA: the real Cloudflare API is blocked on this stack.');
    }
    return wp_remote_request('http://127.0.0.1:' . $port . (string) parse_url((string) $url, PHP_URL_PATH), array(
        'method' => $args['method'] ?? 'POST',
        'headers' => $args['headers'] ?? array(),
        'body' => $args['body'] ?? null,
        'timeout' => $args['timeout'] ?? 5,
        'redirection' => 0,
    ));
}, 10, 3);
