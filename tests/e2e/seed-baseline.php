<?php
/**
 * Creates the clean database baseline of a new disposable stack (called by setup-stack.sh, PHP 8.4 CLI,
 * with only the stack's MySQL running). Same content as the original QA baseline: a fresh WordPress
 * install (admin qa-admin, random password nobody knows, site not public, plain permalinks), the
 * fallback Navigation post a first front-end render creates and the dashboard's Quick Draft auto-draft.
 * No Static Bridge option or table exists before run-e2e.sh activates the plugin.
 * Modes:
 *   install  wp_install() into the empty database (refused if WordPress is already installed);
 *   content  the Navigation post and the Quick Draft auto-draft (as after the first visits);
 *   upgrade  wp_upgrade() after setup-stack.sh replaced the core files (WordPress 7.1.3 stack);
 *   verify   prints the baseline facts setup-stack.sh checks before saving the dump.
 * Refuses anything but the selected stack's exact root, database, socket and loopback home.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli' || PHP_VERSION_ID < 80400 || PHP_VERSION_ID >= 80500) {
    fwrite(STDERR, "Run with the PHP 8.4 CLI.\n");
    exit(2);
}
require __DIR__ . '/profile.php';
$root = realpath(SBQA_ENV . '/wordpress');
$socket = SBQA_ENV . '/run/mysql.sock';
$mode = (string) ($argv[1] ?? '');
if (!$root || !is_file($root . '/wp-load.php') || !in_array($mode, array('install', 'content', 'upgrade', 'verify'), true)) {
    fwrite(STDERR, "REFUSED: unknown mode or QA root missing.\n");
    exit(2);
}
$_SERVER['SERVER_SOFTWARE'] = SBQA_PROFILE['server_software'];
$_SERVER['HTTP_HOST'] = '127.0.0.1:' . SBQA_PROFILE['port'];
$_SERVER['SERVER_NAME'] = '127.0.0.1';
$_SERVER['SERVER_PORT'] = (string) SBQA_PROFILE['port'];
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REQUEST_URI'] = '/wp-admin/install.php';
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
if (in_array($mode, array('install', 'upgrade'), true)) {
    define('WP_INSTALLING', true);
}
// Never send the "New WordPress Site" mail (upgrade.php only defines it when missing).
function wp_new_blog_notification($blog_title, $blog_url, $user_id, $password)
{
}

require $root . '/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/upgrade.php';
require_once ABSPATH . 'wp-admin/includes/post.php';
add_filter('pre_wp_mail', '__return_true');
global $wpdb, $wp_db_version;
$preflight = array(
    'root_exact' => realpath(ABSPATH) === $root,
    'database_exact' => SBQA_PROFILE['db'] === (string) $wpdb->get_var('SELECT DATABASE()'),
    'socket_exact' => realpath((string) $wpdb->get_var('SELECT @@socket')) === realpath($socket),
    'home_constant_loopback' => defined('WP_HOME') && BASE === WP_HOME && defined('WP_SITEURL') && BASE === WP_SITEURL,
    'prefix' => 'wp69_' === $wpdb->prefix,
);
if (in_array(false, $preflight, true)) {
    echo json_encode(array('result' => 'REFUSED', 'preflight' => $preflight), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), PHP_EOL;
    exit(2);
}
$out = static function (array $data, bool $ok = true): void {
    echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), PHP_EOL;
    exit($ok ? 0 : 1);
};

switch ($mode) {
    case 'install':
        if (is_blog_installed()) {
            $out(array('result' => 'REFUSED', 'reason' => 'already_installed'), false);
        }
        $result = wp_install('Static Bridge WordPress 6.9 QA', 'qa-admin', 'qa-admin@example.invalid', false, '', wp_generate_password(32, true, true), '');
        // Plain permalinks, as in the original baseline: wp-harness activate sets /%postname%/ itself.
        update_option('permalink_structure', '');
        update_option('rewrite_rules', '');
        $out(array('result' => 'PASS', 'user_id' => (int) $result['user_id']));
        // no break
    case 'content':
        if ((int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts}") !== 3) {
            $out(array('result' => 'REFUSED', 'reason' => 'not_a_fresh_install'), false);
        }
        // The fallback Navigation (author 0) the block theme header creates on its first render...
        $navigation = class_exists('WP_Navigation_Fallback') ? WP_Navigation_Fallback::get_fallback() : null;
        // ...and the Quick Draft auto-draft the administrator's first dashboard visit creates.
        wp_set_current_user(1);
        $draft = get_default_post_to_edit('post', true);
        update_user_meta(1, $wpdb->prefix . 'dashboard_quick_press_last_post_id', (int) $draft->ID);
        $out(array('result' => 'PASS', 'navigation_id' => $navigation ? (int) $navigation->ID : 0, 'auto_draft_id' => (int) $draft->ID));
        // no break
    case 'upgrade':
        $before = (int) get_option('db_version');
        wp_upgrade();
        $out(array('result' => 'PASS', 'db_version_before' => $before, 'db_version' => (int) get_option('db_version'), 'core_db_version' => (int) $wp_db_version));
        // no break
    case 'verify':
        $posts = $wpdb->get_results("SELECT ID, post_author, post_type, post_status, post_name FROM {$wpdb->posts} ORDER BY ID", ARRAY_A);
        $facts = array(
            'wordpress' => get_bloginfo('version'),
            'db_version' => (int) get_option('db_version'),
            'core_db_version' => (int) $wp_db_version,
            'home' => home_url(),
            'siteurl' => site_url(),
            'blog_public' => (string) get_option('blog_public'),
            'permalink_structure' => (string) get_option('permalink_structure'),
            'template' => (string) get_option('template'),
            'users' => array_map('strval', (array) $wpdb->get_col("SELECT user_login FROM {$wpdb->users} ORDER BY ID")),
            'posts' => array_map(static function ($p) {
                return implode(':', array($p['ID'], $p['post_author'], $p['post_type'], $p['post_status'], $p['post_name']));
            }, (array) $posts),
            'static_bridge_options' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE '%static\\_bridge%'"),
            'active_plugins' => (array) get_option('active_plugins'),
        );
        $expected = array('1:1:post:publish:hello-world', '2:1:page:publish:sample-page', '3:1:page:draft:privacy-policy', '4:0:wp_navigation:publish:navigation', '5:1:post:auto-draft:');
        $ok = $facts['posts'] === $expected && array('qa-admin') === $facts['users'] && BASE === $facts['home'] && BASE === $facts['siteurl']
            && '0' === $facts['blog_public'] && '' === $facts['permalink_structure'] && 'twentytwentyfive' === $facts['template']
            && 0 === $facts['static_bridge_options'] && array() === $facts['active_plugins'] && $facts['db_version'] === $facts['core_db_version']
            && 0 === strpos($facts['wordpress'] . '.', SBQA_PROFILE['wp'] . '.');
        $out(array('result' => $ok ? 'PASS' : 'FAIL') + $facts, $ok);
}
