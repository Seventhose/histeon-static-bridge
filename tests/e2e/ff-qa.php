<?php
/**
 * Fluent Forms QA helper for the disposable stacks $SBQA_HOME/{wpff,wpffa}
 * (SBQA_STACK=ff-nginx|ff-apache). Refuses anything but the selected stack's exact root, database,
 * socket and loopback home. PHP 8.4 CLI.
 * Modes:
 *   setup    activate Fluent Forms (copied read-only from the LocalWP "test" site), keep its demo
 *            form #1, add form #2 (copy, used in the global footer), turn token protection on,
 *            publish /contatti-qa/ with [fluentform id="1"] and /notizia-qa/ without any form.
 *   entries  submissions per QA form (count, last email/subject) — no personal data exists here.
 *   cleanup  delete every QA submission (submissions, entry details, submission meta, logs).
 *   inspect  Fluent Forms version, token setting, Static Bridge Fluent Forms options.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli' || PHP_VERSION_ID < 80400 || PHP_VERSION_ID >= 80500) {
    fwrite(STDERR, "Run with the PHP 8.4 CLI.\n");
    exit(2);
}
require __DIR__ . '/profile.php';
$envRoot = SBQA_ENV ?: false;
$root = $envRoot ? realpath($envRoot . '/wordpress') : false;
$socket = $envRoot ? $envRoot . '/run/mysql.sock' : '';
$mode = (string) ($argv[1] ?? '');
if (!$root || !is_file($root . '/wp-load.php') || !in_array($mode, array('setup', 'entries', 'cleanup', 'inspect'), true)
    || empty(SBQA_PROFILE['ff'])) {
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
if ('setup' === $mode) {
    define('WP_ADMIN', true);
}
require $root . '/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';
global $wpdb;
$preflight = array(
    'root_exact' => realpath(ABSPATH) === $root,
    'database_exact' => SBQA_PROFILE['db'] === (string) $wpdb->get_var('SELECT DATABASE()'),
    'socket_exact' => realpath((string) $wpdb->get_var('SELECT @@socket')) === realpath($socket),
    'home_loopback' => BASE === home_url(),
);
if (in_array(false, $preflight, true)) {
    echo json_encode(array('result' => 'REFUSED', 'preflight' => $preflight)), PHP_EOL;
    exit(2);
}
$out = static function (array $data, bool $ok = true): void {
    echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), PHP_EOL;
    exit($ok ? 0 : 1);
};
$p = $wpdb->prefix;
$qaForms = array(1, 2);

switch ($mode) {
    case 'setup':
        wp_set_current_user(1);
        $activated = activate_plugin('fluentform/fluentform.php', '', false, false);
        if (is_wp_error($activated)) {
            $out(array('result' => 'FAIL', 'error' => $activated->get_error_message()), false);
        }
        // Fluent Forms creates its tables and the demo form (#1) on activation.
        if (!(int) $wpdb->get_var("SELECT COUNT(*) FROM {$p}fluentform_forms WHERE id = 1")) {
            (new \FluentForm\App\Hooks\Handlers\ActivationHandler())->handle(false);
        }
        if (!(int) $wpdb->get_var("SELECT COUNT(*) FROM {$p}fluentform_forms WHERE id = 2")) {
            $form = $wpdb->get_row("SELECT * FROM {$p}fluentform_forms WHERE id = 1", ARRAY_A);
            $form['id'] = 2;
            $form['title'] = 'Footer QA';
            $wpdb->insert("{$p}fluentform_forms", $form);
            foreach ((array) $wpdb->get_results("SELECT meta_key, value FROM {$p}fluentform_form_meta WHERE form_id = 1", ARRAY_A) as $meta) {
                $wpdb->insert("{$p}fluentform_form_meta", array('form_id' => 2, 'meta_key' => $meta['meta_key'], 'value' => $meta['value']));
            }
        }
        $settings = get_option('_fluentform_global_form_settings', array());
        $settings = is_array($settings) ? $settings : array();
        $settings['misc'] = array_merge(isset($settings['misc']) && is_array($settings['misc']) ? $settings['misc'] : array(), array('tokenBasedProtectionStatus' => 'yes'));
        update_option('_fluentform_global_form_settings', $settings);
        $pages = array();
        foreach (array('contatti-qa' => array('Contatti QA', "<!-- wp:shortcode -->\n[fluentform id=\"1\"]\n<!-- /wp:shortcode -->"), 'notizia-qa' => array('Notizia QA', "<!-- wp:paragraph -->\n<p>Pagina senza modulo nel contenuto.</p>\n<!-- /wp:paragraph -->")) as $slug => $page) {
            $existing = get_page_by_path($slug, OBJECT, 'page');
            $pages[$slug] = $existing ? (int) $existing->ID : (int) wp_insert_post(array('post_type' => 'page', 'post_status' => 'publish', 'post_name' => $slug, 'post_title' => $page[0], 'post_content' => $page[1], 'post_author' => 1));
        }
        $out(array(
            'result' => is_plugin_active('fluentform/fluentform.php') && defined('FLUENTFORM_VERSION') ? 'PASS' : 'FAIL',
            'fluentform_version' => defined('FLUENTFORM_VERSION') ? FLUENTFORM_VERSION : null,
            'forms' => array_map('intval', (array) $wpdb->get_col("SELECT id FROM {$p}fluentform_forms ORDER BY id")),
            'token_protection' => get_option('_fluentform_global_form_settings')['misc']['tokenBasedProtectionStatus'] ?? null,
            'pages' => $pages,
        ));
        // no break
    case 'entries':
        $rows = array();
        foreach ($qaForms as $formId) {
            $last = $wpdb->get_row($wpdb->prepare("SELECT id, response, created_at FROM {$p}fluentform_submissions WHERE form_id = %d ORDER BY id DESC LIMIT 1", $formId), ARRAY_A);
            $response = $last ? json_decode((string) $last['response'], true) : null;
            $rows[$formId] = array(
                'count' => (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}fluentform_submissions WHERE form_id = %d", $formId)),
                'last_email' => is_array($response) ? ($response['email'] ?? null) : null,
                'last_subject' => is_array($response) ? ($response['subject'] ?? null) : null,
                'last_embed_post_id' => is_array($response) ? ($response['__fluent_form_embded_post_id'] ?? null) : null,
            );
        }
        $out(array('result' => 'PASS', 'forms' => $rows));
        // no break
    case 'cleanup':
        $deleted = array();
        $in = implode(',', array_map('intval', $qaForms));
        foreach (array('fluentform_entry_details', 'fluentform_submission_meta', 'fluentform_logs', 'fluentform_submissions') as $table) {
            if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $p . $table))) {
                $column = 'fluentform_logs' === $table ? 'parent_source_id' : 'form_id';
                $deleted[$table] = (int) $wpdb->query("DELETE FROM {$p}{$table} WHERE {$column} IN ({$in})");
            }
        }
        $left = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$p}fluentform_submissions WHERE form_id IN ({$in})");
        $out(array('result' => 0 === $left ? 'PASS' : 'FAIL', 'deleted' => $deleted, 'submissions_left' => $left), 0 === $left);
        // no break
    case 'inspect':
        $out(array(
            'result' => 'PASS',
            'fluentform_active' => is_plugin_active('fluentform/fluentform.php'),
            'fluentform_version' => defined('FLUENTFORM_VERSION') ? FLUENTFORM_VERSION : null,
            'token_protection' => (get_option('_fluentform_global_form_settings', array())['misc']['tokenBasedProtectionStatus'] ?? null),
            'static_bridge_nonce_refresh' => get_option('static_bridge_fluent_forms_nonce_refresh', 'absent'),
            'static_bridge_fluent_forms_dynamic' => get_option('static_bridge_fluent_forms_dynamic', array()),
            'static_bridge_form_modes' => get_option('static_bridge_form_modes', array()),
        ));
}
