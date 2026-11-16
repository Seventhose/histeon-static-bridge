<?php
/**
 * CLI harness for the disposable Static Bridge QA stack selected by SBQA_STACK (default wp69:
 * WordPress 6.9, PHP-FPM 8.4.18, Nginx 1.26.1, private MySQL 8.4 socket, loopback only;
 * see profile.sh for the other stacks), all under $SBQA_HOME and built by setup-stack.sh.
 *
 * Refuses to run against anything except the selected stack's exact root, database and socket.
 * Modes: preflight, activate, block (Nginx), htaccess, session <role>, inspect, deactivate, reactivate, users,
 *        mark <post_id> <marker> (appends a QA marker paragraph to a post),
 *        archives seed|publish <marker>|unpublish <post_id>|term <slug>|rename <slug> <new-slug> (archives-e2e.sh).
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
$allowed = array('preflight', 'activate', 'block', 'session', 'inspect', 'deactivate', 'reactivate', 'users', 'mark', 'remark', 'htaccess', 'archives');
if (!$root || !is_file($root . '/wp-load.php') || !in_array($mode, $allowed, true)) {
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
if (in_array($mode, array('activate', 'deactivate', 'reactivate'), true)) {
    define('WP_ADMIN', true);
}

$observed = array();
error_reporting(E_ALL);
set_error_handler(static function ($severity, $message, $file, $line) use (&$observed) {
    $observed[] = array('severity' => $severity, 'file' => basename((string) $file), 'line' => $line, 'message' => substr((string) $message, 0, 300));
    return false;
});

require $root . '/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';
require_once ABSPATH . 'wp-admin/includes/file.php';
require_once ABSPATH . 'wp-admin/includes/misc.php';
global $wpdb;

$pluginFile = 'static-bridge/static-bridge.php';
$projectRoot = (string) realpath(dirname(__DIR__, 2));
$sameFile = static function (string $a, string $b): bool {
    return is_file($a) && is_file($b) && hash_equals((string) hash_file('sha256', $a), (string) hash_file('sha256', $b));
};
$installed = WP_PLUGIN_DIR . '/static-bridge';
// SBQA_PLUGIN_SOURCE may name another plugin source inside the project (e.g. a
// snapshot identical to an earlier ZIP) to compare builds on the same stack.
$sourceDir = realpath((string) (getenv('SBQA_PLUGIN_SOURCE') ?: $projectRoot . '/plugin'));
if (!$sourceDir || 0 !== strpos($sourceDir . '/', $projectRoot . '/')) {
    fwrite(STDERR, "REFUSED: plugin source must be inside the project.\n");
    exit(2);
}
$candidate = true;
foreach (array('static-bridge.php', 'i18n.php', 'dependency-index.php', 'header-fragments.php', 'export-queue.php', 'assets/admin.js', 'assets/post-push.js', 'assets/admin.css') as $file) {
    $candidate = $candidate && $sameFile($sourceDir . '/' . $file, $installed . '/' . $file);
}
$preflight = array(
    'root_exact' => realpath(ABSPATH) === $root,
    'database_exact' => SBQA_PROFILE['db'] === (string) $wpdb->get_var('SELECT DATABASE()'),
    'socket_exact' => realpath((string) $wpdb->get_var('SELECT @@socket')) === realpath($socket),
    'home_loopback' => BASE === home_url(),
    'wordpress_version_exact' => 1 === preg_match('/^' . preg_quote(SBQA_PROFILE['wp'], '/') . '(?:\.|$)/', (string) get_bloginfo('version')),
    'php_8_4' => PHP_MAJOR_VERSION === 8 && PHP_MINOR_VERSION === 4,
    'plugin_candidate_matches_source' => $candidate,
);
foreach ($preflight as $key => $ok) {
    if (!$ok) {
        echo json_encode(array('result' => 'REFUSED', 'preflight' => $preflight), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), PHP_EOL;
        exit(2);
    }
}
$out = static function (array $data, bool $ok = true) use (&$observed) {
    $data['php_notices_warnings'] = array_values(array_filter($observed, static function ($e) {
        return 0 === strpos($e['file'], 'static-bridge') || 0 === strpos($e['file'], 'dependency-index') || 0 === strpos($e['file'], 'header-fragments') || 0 === strpos($e['file'], 'export-queue');
    }));
    $data['other_php_messages'] = count($observed) - count($data['php_notices_warnings']);
    echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), PHP_EOL;
    exit($ok ? 0 : 1);
};
$staticRoot = WP_CONTENT_DIR . '/static-bridge';
$htaccess = static function (): array {
    $text = (string) @file_get_contents(ABSPATH . '.htaccess');
    $wp = strpos($text, '# BEGIN WordPress');
    $sb = strpos($text, '# BEGIN Static Bridge' . "\n");
    return array('wordpress_block' => false !== $wp, 'static_bridge_block' => false !== $sb,
        'archive_protection_block' => false !== strpos($text, '# BEGIN Static Bridge Archive Protection'),
        'static_bridge_before_wordpress' => false !== $sb && false !== $wp && $sb < $wp);
};
$writeBlock = static function () use ($envRoot): string {
    if ('nginx' !== SBQA_PROFILE['web']) {
        return 'not-applicable';
    }
    $bridge = null;
    foreach (($GLOBALS['wp_filter']['admin_menu']->callbacks ?? array()) as $callbacks) {
        foreach ($callbacks as $entry) {
            if (is_array($entry['function']) && $entry['function'][0] instanceof Static_Bridge_Plugin) {
                $bridge = $entry['function'][0];
            }
        }
    }
    if (!$bridge) {
        return '';
    }
    $method = new ReflectionMethod(Static_Bridge_Plugin::class, 'nginx_router_block');
    $method->setAccessible(true);
    $block = (string) $method->invoke($bridge);
    file_put_contents($envRoot . '/conf/static-bridge.conf', $block);
    return hash('sha256', $block);
};

switch ($mode) {
    case 'preflight':
        $out(array('result' => 'PASS', 'preflight' => $preflight, 'plugin_active' => is_plugin_active($pluginFile)));
        // no break
    case 'activate':
        global $wp_rewrite;
        $wp_rewrite->set_permalink_structure('/%postname%/');
        // On Apache WordPress writes its own .htaccess block, as when Permalinks are saved.
        flush_rewrite_rules('apache' === SBQA_PROFILE['web']);
        $result = activate_plugin($pluginFile, '', false, false);
        $active = is_plugin_active($pluginFile);
        $blockHash = $active ? $writeBlock() : '';
        $rules = $htaccess();
        $ok = !is_wp_error($result) && $active && !file_exists($staticRoot . '/.enabled') && '' !== $blockHash
            && ('apache' !== SBQA_PROFILE['web'] || ($rules['wordpress_block'] && !$rules['static_bridge_block']));
        $out(array(
            'result' => $ok ? 'PASS' : 'FAIL',
            'activation_error' => is_wp_error($result) ? $result->get_error_code() . ': ' . $result->get_error_message() : null,
            'plugin_active' => $active,
            'version' => class_exists('Static_Bridge_Plugin') ? Static_Bridge_Plugin::VERSION : null,
            'routing_marker_absent_without_release' => !file_exists($staticRoot . '/.enabled'),
            'permalink_structure' => get_option('permalink_structure'),
            'options_created' => array_values(array_map('strval', (array) $wpdb->get_col("SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE 'static\\_bridge%'"))),
            'tables' => array_values((array) $wpdb->get_col($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($wpdb->prefix . 'static_bridge') . '%'))),
            'nginx_block_sha256' => $blockHash,
            'htaccess' => 'apache' === SBQA_PROFILE['web'] ? $rules : null,
        ), $ok);
        // no break
    case 'block':
        $hash = $writeBlock();
        $out(array('result' => '' !== $hash ? 'PASS' : 'FAIL', 'nginx_block_sha256' => $hash), '' !== $hash);
        // no break
    case 'users':
        $made = array();
        foreach (array('editor' => 'sbqa_editor', 'author' => 'sbqa_author', 'subscriber' => 'sbqa_subscriber') as $role => $login) {
            if (!username_exists($login)) {
                $id = wp_insert_user(array('user_login' => $login, 'user_pass' => wp_generate_password(32, true, true), 'user_email' => $login . '@example.invalid', 'role' => $role));
                $made[$role] = is_wp_error($id) ? $id->get_error_code() : (int) $id;
            } else {
                $made[$role] = (int) username_exists($login);
            }
        }
        $out(array('result' => 'PASS', 'users' => $made));
        // no break
    case 'session':
        $role = (string) ($argv[2] ?? 'administrator');
        $users = get_users(array('role' => $role, 'number' => 1, 'fields' => 'ID'));
        if (empty($users[0])) {
            $out(array('result' => 'FAIL', 'reason' => 'no_user_for_role'), false);
        }
        $userId = (int) $users[0];
        $expiration = time() + 2 * HOUR_IN_SECONDS;
        $token = WP_Session_Tokens::get_instance($userId)->create($expiration);
        $cookie = AUTH_COOKIE . '=' . rawurlencode(wp_generate_auth_cookie($userId, $expiration, 'auth', $token))
            . '; ' . LOGGED_IN_COOKIE . '=' . rawurlencode(wp_generate_auth_cookie($userId, $expiration, 'logged_in', $token));
        $path = $envRoot . '/run/cookie-' . sanitize_key($role) . '.txt';
        file_put_contents($path, 'Cookie: ' . $cookie . "\n");
        chmod($path, 0600);
        $out(array('result' => 'PASS', 'role' => $role, 'user_id' => $userId, 'cookie_header_file' => basename($path)));
        // no break
    case 'deactivate':
        $current = realpath($staticRoot . '/current');
        $manifestHash = $current && is_file($current . '/manifest.json') ? hash_file('sha256', $current . '/manifest.json') : null;
        deactivate_plugins($pluginFile, false, false);
        $after = realpath($staticRoot . '/current');
        $ok = !is_plugin_active($pluginFile) && !file_exists($staticRoot . '/.enabled') && $after === $current
            && ($manifestHash === null || hash_equals($manifestHash, (string) hash_file('sha256', $current . '/manifest.json')));
        $out(array('result' => $ok ? 'PASS' : 'FAIL', 'plugin_active' => is_plugin_active($pluginFile),
            'routing_marker_removed' => !file_exists($staticRoot . '/.enabled'), 'release_preserved' => $after === $current,
            'release' => $current ? basename($current) : null), $ok);
        // no break
    case 'reactivate':
        $current = realpath($staticRoot . '/current');
        $result = activate_plugin($pluginFile, '', false, false);
        $ok = !is_wp_error($result) && is_plugin_active($pluginFile) && realpath($staticRoot . '/current') === $current;
        $out(array('result' => $ok ? 'PASS' : 'FAIL', 'plugin_active' => is_plugin_active($pluginFile),
            'routing_marker_present' => is_file($staticRoot . '/.enabled'), 'release' => $current ? basename($current) : null), $ok);
        // no break
    case 'mark':
        $postId = (int) ($argv[2] ?? 0);
        $marker = preg_replace('/[^A-Za-z0-9_-]/', '', (string) ($argv[3] ?? ''));
        $post = get_post($postId);
        if (!$post || '' === $marker) {
            $out(array('result' => 'FAIL', 'reason' => 'post_or_marker_missing'), false);
        }
        $updated = wp_update_post(array('ID' => $postId, 'post_content' => $post->post_content . "\n<!-- wp:paragraph -->\n<p>" . $marker . "</p>\n<!-- /wp:paragraph -->"), true);
        $out(array('result' => is_wp_error($updated) ? 'FAIL' : 'PASS', 'post_id' => $postId, 'marker' => $marker, 'permalink' => get_permalink($postId)), !is_wp_error($updated));
        // no break
    case 'remark':
        // Like mark, but replaces the previous remark paragraph (repeated pushes keep the post size bounded).
        $postId = (int) ($argv[2] ?? 0);
        $marker = preg_replace('/[^A-Za-z0-9_-]/', '', (string) ($argv[3] ?? ''));
        $post = get_post($postId);
        if (!$post || '' === $marker) {
            $out(array('result' => 'FAIL', 'reason' => 'post_or_marker_missing'), false);
        }
        $block = "<!-- wp:paragraph {\"className\":\"sbqa-remark\"} -->\n<p class=\"sbqa-remark\">" . $marker . "</p>\n<!-- /wp:paragraph -->";
        $content = preg_replace('~\n?<!-- wp:paragraph \{"className":"sbqa-remark"\} -->.*?<!-- /wp:paragraph -->~s', '', $post->post_content);
        $updated = wp_update_post(array('ID' => $postId, 'post_content' => $content . "\n" . $block), true);
        $out(array('result' => is_wp_error($updated) ? 'FAIL' : 'PASS', 'post_id' => $postId, 'bytes' => strlen($marker)), !is_wp_error($updated));
        // no break
    case 'archives':
        // Lists no page links to: an orphan category of 11 posts on one day, an empty category, a parent whose
        // posts are all in its child, and a page with a core Query Loop. The plugin's hooks run as on a save.
        $action = (string) ($argv[2] ?? '');
        $category = static function (string $slug, int $parent = 0): int {
            $term = get_term_by('slug', $slug, 'category');
            if ($term) {
                return (int) $term->term_id;
            }
            $made = wp_insert_term(ucfirst($slug), 'category', array('slug' => $slug, 'parent' => $parent));
            return is_wp_error($made) ? 0 : (int) $made['term_id'];
        };
        $admin = (int) (get_users(array('role' => 'administrator', 'number' => 1, 'fields' => 'ID'))[0] ?? 1);
        if ('seed' === $action) {
            update_option('posts_per_page', 10);
            $orphan = $category('sbqa-orfana');
            $parent = $category('sbqa-genitore');
            $child = $category('sbqa-figlia', $parent);
            $empty = $category('sbqa-vuota');
            $ids = array();
            for ($index = 1; $index <= 11; $index++) {
                $ids[] = wp_insert_post(array('post_title' => 'SBQA orfana ' . $index, 'post_name' => 'sbqa-orfana-' . $index, 'post_status' => 'publish', 'post_author' => $admin,
                    'post_date' => sprintf('2024-01-15 %02d:00:00', $index), 'post_category' => array($orphan), 'post_content' => 'SBQA archive post ' . $index));
            }
            $ids[] = wp_insert_post(array('post_title' => 'SBQA figlia', 'post_name' => 'sbqa-figlia-1', 'post_status' => 'publish', 'post_author' => $admin,
                'post_date' => '2023-05-02 09:00:00', 'post_category' => array($child), 'post_content' => 'SBQA child post'));
            $list = wp_insert_post(array('post_title' => 'SBQA elenco', 'post_name' => 'sbqa-elenco', 'post_type' => 'page', 'post_status' => 'publish', 'post_author' => $admin,
                'post_content' => '<!-- wp:query {"queryId":7,"query":{"perPage":3,"postType":"post","order":"desc","orderBy":"date","inherit":false}} --><div class="wp-block-query"><!-- wp:post-template --><!-- wp:post-title {"isLink":true} /--><!-- /wp:post-template --></div><!-- /wp:query -->'));
            // 2.2.1: a tag whose slug is a file extension, and security headers the site sends from PHP.
            $tagged = $ids ? wp_set_post_tags((int) $ids[0], array('pdf'), true) : false;
            $mu = WP_CONTENT_DIR . '/mu-plugins';
            $headers = (is_dir($mu) || wp_mkdir_p($mu)) && false !== file_put_contents($mu . '/sbqa-security-headers.php', "<?php\n// QA (archives-e2e.sh): security headers sent from PHP, as a theme or a plugin does.\n"
                . "add_action('send_headers', static function () { if (!is_admin()) { header('X-Frame-Options: SAMEORIGIN'); header('X-Content-Type-Options: nosniff'); } });\n");
            $pdf = get_term_by('slug', 'pdf', 'post_tag');
            $out(array('result' => $orphan && $child && $empty && $list && !in_array(0, $ids, true) && $tagged && $pdf && $headers ? 'PASS' : 'FAIL', 'posts' => $ids, 'list' => $list, 'author' => get_the_author_meta('user_nicename', $admin)));
        }
        if ('publish' === $action || 'unpublish' === $action) {
            $marker = preg_replace('/[^A-Za-z0-9_-]/', '', (string) ($argv[3] ?? ''));
            if ('publish' === $action) {
                $id = wp_insert_post(array('post_title' => $marker, 'post_name' => strtolower($marker), 'post_status' => 'publish', 'post_author' => $admin,
                    'post_date' => '2024-01-15 23:30:00', 'post_category' => array($category('sbqa-orfana')), 'post_content' => $marker), true);
            } else {
                $id = wp_update_post(array('ID' => (int) $marker, 'post_status' => 'draft'), true);
            }
            $out(array('result' => is_wp_error($id) ? 'FAIL' : 'PASS', 'post_id' => is_wp_error($id) ? 0 : (int) $id), !is_wp_error($id));
        }
        if ('term' === $action) {
            $id = $category(sanitize_title((string) ($argv[3] ?? '')));
            $out(array('result' => $id ? 'PASS' : 'FAIL', 'term_id' => $id), (bool) $id);
        }
        if ('rename' === $action) {
            $term = get_term_by('slug', sanitize_title((string) ($argv[3] ?? '')), 'category');
            $done = $term ? wp_update_term((int) $term->term_id, 'category', array('slug' => sanitize_title((string) ($argv[4] ?? '')))) : null;
            $out(array('result' => $term && !is_wp_error($done) ? 'PASS' : 'FAIL'), $term && !is_wp_error($done));
        }
        $out(array('result' => 'FAIL', 'reason' => 'unknown archives action'), false);
        // no break
    case 'htaccess':
        $out(array('result' => 'PASS') + $htaccess());
        // no break
    case 'inspect':
        $job = get_option(Static_Bridge_Plugin::JOB_OPTION, array());
        $current = realpath($staticRoot . '/current');
        $manifest = $current && is_file($current . '/manifest.json') ? json_decode((string) file_get_contents($current . '/manifest.json'), true) : null;
        $releases = array_values(array_diff(scandir($staticRoot . '/releases') ?: array(), array('.', '..')));
        $activity = array_slice((array) get_option(Static_Bridge_Plugin::ACTIVITY_OPTION, array()), 0, 8);
        $out(array(
            'result' => 'PASS',
            'plugin_active' => is_plugin_active($pluginFile),
            'job' => is_array($job) ? array_intersect_key($job, array_flip(array('status', 'discovered', 'processed', 'saved', 'skipped', 'errors', 'error_count', 'preserved', 'release_id', 'issues', 'message'))) : $job,
            'current_release' => $current ? basename($current) : null,
            'releases' => $releases,
            'manifest' => is_array($manifest) ? array_intersect_key($manifest, array_flip(array('release', 'pages_saved', 'generated_at', 'plugin_version', 'skipped', 'errors', 'preserved'))) : null,
            'enabled_marker' => is_file($staticRoot . '/.enabled'),
            'strict_marker' => is_file($staticRoot . '/.strict'),
            'activity' => $activity,
            'htaccess' => $htaccess(),
        ));
}
