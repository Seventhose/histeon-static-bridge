<?php
/**
 * Looks for data that must never reach a public static page: anything that only a
 * logged-in user should see, per-session values, or Static Bridge's own internal markers.
 * Read-only. Works on a release directory or on public URLs (e.g. a pilot site).
 *
 * Usage: php html-leak-scan.php <release-dir | URL>...
 * Exit 1 if a "critical" finding exists; "warning" findings are listed but do not fail.
 */
declare(strict_types=1);

const CHECKS = array(
    // Shown only to logged-in users: their presence means the page was rendered as someone.
    array('critical', 'admin_bar', '~id=["\']wpadminbar["\']~i'),
    array('critical', 'logged_in_body_class', '~<body[^>]*class=["\'][^"\']*\b(?:logged-in|admin-bar)\b~i'),
    array('critical', 'edit_post_link', '~/wp-admin/post\.php\?post=\d+(?:&|&amp;|&#038;)action=edit~i'),
    array('critical', 'comment_form_logged_in_as', '~class=["\']logged-in-as["\']~i'),
    array('critical', 'customize_support_script', '~wp-customize-support|customize-support~i'),
    array('critical', 'auth_check', '~wp-auth-check~i'),
    array('critical', 'current_user_json', '~"(?:currentUser|current_user_id|user_email|user_login)"\s*:\s*(?!0\b|null|""|\[\]|\{\})~i'),
    array('critical', 'wordpress_auth_cookie_value', '~wordpress_(?:logged_in_|sec_)[a-f0-9]{32}=~i'),
    // Static Bridge internals that must be stripped before saving.
    array('critical', 'render_token_leak', '~static_bridge_render=[a-f0-9]{8,}|static-bridge-render:[a-f0-9]{8,}~i'),
    array('critical', 'bypass_query_leak', '~[?&](?:amp;|#038;)?static_bridge_bypass=1~i'),
    // The same inside another URL, percent-encoded once or more (share links: sharer.php?u=…%3Fstatic_bridge_bypass%3D1…).
    array('critical', 'render_token_leak_encoded', '~static_bridge_render%(?:25)*3D[a-f0-9]{8,}~i'),
    array('critical', 'bypass_query_leak_encoded', '~%(?:25)*(?:3F|26)(?:amp%(?:25)*3B|%(?:25)*23(?:0*38|x0*26)%(?:25)*3B)?static_bridge_bypass%(?:25)*3D1~i'),
    // 2.5.0: a marker of «Date relative aggiornate nel browser» (U+10FFF1-U+10FFF3), raw or as a JSON escape.
    array('critical', 'relative_date_marker', '~\xF4\x8F\xBF[\xB1-\xB3]|\\\\+udbff\\\\+udff[1-3]|&#x0*10fff[1-3]|&#0*111409[7-9]|%(?:25)*F4%(?:25)*8F%(?:25)*BF~i'),
    array('critical', 'preview_link', '~[?&](?:amp;|#038;)?preview(?:_id|_nonce)?=~i'),
    // Values that expire or belong to a session: harmless for anonymous visitors, but stale in a static copy.
    array('warning', 'rest_nonce', '~wpApiSettings[^<]{0,400}"nonce"\s*:\s*"[a-f0-9]{10}"~i'),
    array('warning', 'generic_wpnonce', '~name=["\']_wpnonce["\'][^>]*value=["\'][a-f0-9]{10}["\']~i'),
    array('warning', 'woocommerce_session', '~wc_cart_hash|woocommerce_items_in_cart|wc-cart-fragments~i'),
    array('warning', 'fluent_forms_nonce', '~_fluentform_\d+_fluentformnonce["\'][^>]*value=["\'][a-f0-9]{10}~i'),
);

$targets = array_slice($argv, 1);
if (!$targets) {
    fwrite(STDERR, "Usage: php html-leak-scan.php <release-dir | URL>...\n");
    exit(2);
}
$pages = array();
foreach ($targets as $target) {
    if (preg_match('~^https?://~i', $target)) {
        $ctx = stream_context_create(array('http' => array('timeout' => 20, 'ignore_errors' => true, 'header' => "User-Agent: static-bridge-leak-scan\r\n")));
        $body = @file_get_contents($target, false, $ctx);
        if (false !== $body) {
            $pages[$target] = $body;
        }
    } elseif (is_dir($target)) {
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($target, FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            if ($file->isFile() && !$file->isLink() && 'html' === strtolower($file->getExtension())) {
                $pages[substr($file->getPathname(), strlen(rtrim($target, '/')) + 1)] = (string) file_get_contents($file->getPathname());
            }
        }
    }
}
$findings = array();
$critical = 0;
foreach ($pages as $name => $html) {
    foreach (CHECKS as [$level, $id, $pattern]) {
        if (preg_match_all($pattern, $html, $m)) {
            $findings[] = array('level' => $level, 'check' => $id, 'page' => $name, 'count' => count($m[0]), 'sample' => substr($m[0][0], 0, 90));
            $critical += 'critical' === $level ? 1 : 0;
        }
    }
}
echo json_encode(array('pages_scanned' => count($pages), 'critical' => $critical, 'warnings' => count($findings) - $critical, 'findings' => $findings), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), PHP_EOL;
exit($critical > 0 ? 1 : 0);
