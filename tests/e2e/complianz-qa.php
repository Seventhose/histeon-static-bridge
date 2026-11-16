<?php
/**
 * Complianz QA helper for the disposable stacks $SBQA_HOME/{wpff,wpffa} (SBQA_STACK=ff-nginx|ff-apache).
 * Refuses anything but the selected stack's root. PHP 8.4 CLI. Mail is not sent: Complianz stays on default
 * settings for its notices and the stack has no route out.
 * Modes:
 *   activate   activate Complianz (copied read-only into the stack by complianz-e2e.sh).
 *   setup      next request: tables, one banner, an EU opt-in site with records of consent, Do Not Track respected,
 *              the two probe scripts blocked as statistics and marketing, a generated cookie policy at /cookie-policy-eu/.
 *   worker     run the Static Bridge incremental worker until its queue is empty.
 *   inspect    Complianz version, banner_version, policy id, queue length, script block rules.
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
if (!$root || !is_file($root . '/wp-load.php') || !in_array($mode, array('activate', 'setup', 'worker', 'inspect'), true) || empty(SBQA_PROFILE['ff'])) {
    fwrite(STDERR, "REFUSED: unknown mode or QA root missing.\n");
    exit(2);
}
$_SERVER['SERVER_SOFTWARE'] = SBQA_PROFILE['server_software'];
$_SERVER['HTTP_HOST'] = '127.0.0.1:' . SBQA_PROFILE['port'];
$_SERVER['SERVER_NAME'] = '127.0.0.1';
$_SERVER['SERVER_PORT'] = (string) SBQA_PROFILE['port'];
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REQUEST_URI'] = '/wp-admin/';
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
if (in_array($mode, array('activate', 'setup'), true)) {
    define('WP_ADMIN', true);
}
define('WP_DISABLE_FATAL_ERROR_HANDLER', true);
register_shutdown_function(static function () {
    $error = error_get_last();
    if ($error && in_array($error['type'], array(E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR), true)) {
        fwrite(STDERR, $error['message'] . ' at ' . $error['file'] . ':' . $error['line'] . "\n");
    }
});
require $root . '/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';
require_once ABSPATH . 'wp-admin/includes/upgrade.php';
$admins = get_users(array('role' => 'administrator', 'number' => 1, 'fields' => 'ID'));
wp_set_current_user((int) $admins[0]);
$out = static function (array $data): void {
    echo json_encode($data, JSON_UNESCAPED_SLASHES), "\n";
};
$rules = static fn() => array_map(static fn($r) => $r['name'] . ':' . $r['category'] . ':' . (int) $r['enable'], (array) (get_option('complianz_options_custom-scripts')['block_script'] ?? array()));

switch ($mode) {
    case 'activate':
        $activated = activate_plugin('complianz-gdpr/complianz-gpdr.php', '', false, false);
        $out(array('result' => is_wp_error($activated) ? 'FAIL' : 'PASS', 'error' => is_wp_error($activated) ? $activated->get_error_message() : ''));
        break;

    case 'setup':
        // Complianz loads its admin code only for a request that arrives logged in, which a CLI run is not.
        require_once CMPLZ_PATH . 'cookiebanner/admin/cookiebanner.php';
        do_action('cmplz_install_tables');
        cmplz_install_cookiebanner_table(true);
        cmplz_check_minimum_one_banner();
        $policy = wp_insert_post(array('post_type' => 'page', 'post_status' => 'publish', 'post_name' => 'cookie-policy-eu', 'post_title' => 'Cookie Policy (EU)',
            'post_content' => '[cmplz-document type="cookie-statement" region="eu"]'));
        $options = (array) get_option('cmplz_options', array());
        $options = array_merge($options, array('regions' => 'eu', 'eu_consent_regions' => 'yes', 'cookie-statement' => 'generated', 'compile_statistics' => 'yes', 'uses_cookies' => 'yes',
            'enable_cookie_banner' => 'yes', 'enable_cookie_blocker' => 'yes', 'safe_mode' => false, 'consent_per_service' => 'yes', 'records_of_consent' => 'yes', 'respect_dnt' => 'yes', 'cookie_expiry' => 365,
            'organisation_name' => 'SBQA', 'country_company' => 'IT'));
        update_option('cmplz_options', $options);
        update_option('cmplz_wizard_completed_once', true);
        update_option('complianz_options_custom-scripts', array('add_script' => array(), 'whitelist_script' => array(), 'block_script' => array(
            array('enable' => 1, 'name' => 'SBQA stat', 'urls' => array('sbqa-stat.js'), 'category' => 'statistics', 'enable_placeholder' => false, 'iframe' => false, 'placeholder_class' => '', 'placeholder' => '', 'enable_dependency' => '', 'dependency' => array()),
            array('enable' => 1, 'name' => 'SBQA mkt', 'urls' => array('sbqa-mkt.js'), 'category' => 'marketing', 'enable_placeholder' => false, 'iframe' => false, 'placeholder_class' => '', 'placeholder' => '', 'enable_dependency' => '', 'dependency' => array()),
        )));
        cmplz_delete_transient('cmplz_blocked_scripts');
        cmplz_update_all_banners();
        wp_cache_flush();
        $out(array('result' => is_wp_error($policy) || !$policy ? 'FAIL' : 'PASS', 'version' => CMPLZ_VERSION, 'policy_page' => $policy, 'banners' => count(cmplz_get_cookiebanners()), 'rules' => $rules()));
        break;

    case 'worker':
        for ($round = 0; $round < 40 && !empty(get_option(Static_Bridge_Plugin::INCREMENTAL_QUEUE_OPTION, array())); $round++) {
            delete_option('static_bridge_incremental_worker_lock');
            do_action('static_bridge_process_incremental_queue');
        }
        $out(array('rounds' => $round, 'left' => count((array) get_option(Static_Bridge_Plugin::INCREMENTAL_QUEUE_OPTION, array()))));
        break;

    case 'inspect':
        $out(array('version' => CMPLZ_VERSION, 'banner_version' => (int) cmplz_get_cookiebanner(cmplz_get_default_banner_id())->banner_version,
            'policy' => (int) get_option('complianz_active_policy_id', 1), 'queue' => count((array) get_option(Static_Bridge_Plugin::INCREMENTAL_QUEUE_OPTION, array())), 'rules' => $rules()));
        break;
}
