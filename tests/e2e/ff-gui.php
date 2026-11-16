<?php
/**
 * The Static Bridge "Moduli" tab over real HTTP on the disposable stack (SBQA_STACK), as an
 * administrator (cookie from wp69-harness.php session administrator).
 * Usage: php ff-gui.php refresh on|off [release]   submit the nonce-refresh switch form
 *        php ff-gui.php tab                         print facts read from the rendered tab
 */
declare(strict_types=1);

require __DIR__ . '/profile.php';
$cookie = trim((string) @file_get_contents(SBQA_ENV . '/run/cookie-administrator.txt'));
if ('' === $cookie) {
    fwrite(STDERR, "No administrator cookie.\n");
    exit(2);
}
function request(string $method, string $path, string $cookie, ?array $form = null): array
{
    $headers = array('User-Agent: static-bridge-qa-gui', $cookie);
    $content = '';
    if (null !== $form) {
        $content = http_build_query($form);
        $headers[] = 'Content-Type: application/x-www-form-urlencoded';
    }
    $ctx = stream_context_create(array('http' => array('method' => $method, 'header' => implode("\r\n", $headers), 'content' => $content, 'ignore_errors' => true, 'follow_location' => 0, 'timeout' => 60)));
    $body = (string) @file_get_contents(BASE . $path, false, $ctx);
    $raw = function_exists('http_get_last_response_headers') ? (http_get_last_response_headers() ?: array()) : ($http_response_header ?? array());
    $status = isset($raw[0]) && preg_match('#^HTTP/\S+\s+(\d+)#', $raw[0], $m) ? (int) $m[1] : 0;
    $location = '';
    foreach ($raw as $line) {
        if (0 === stripos($line, 'Location:')) {
            $location = trim(substr($line, 9));
        }
    }
    return array('status' => $status, 'body' => $body, 'location' => $location);
}
$tab = request('GET', '/wp-admin/admin.php?page=static-bridge&tab=forms', $cookie);
$html = $tab['body'];
$command = (string) ($argv[1] ?? '');
if ('tab' === $command) {
    preg_match_all('~<article class="sb-form-entry">.*?</article>~s', $html, $entries);
    $rows = array();
    foreach ($entries[0] as $entry) {
        preg_match('~<a href="[^"]*"[^>]*>([^<]+)<span~', $entry, $path);
        preg_match('~<span class="sb-form-state is-([a-z]+)">([^<]+)</span>~', $entry, $state);
        preg_match('~<small>([^<]*)</small>~', $entry, $small);
        $rows[] = array('path' => $path[1] ?? '', 'state' => $state[1] ?? '', 'label' => html_entity_decode($state[2] ?? ''), 'note' => html_entity_decode($small[1] ?? ''), 'select_disabled' => (bool) preg_match('~<select[^>]*disabled~', $entry));
    }
    echo json_encode(array(
        'http' => $tab['status'],
        'switch_present' => false !== strpos($html, 'value="static_bridge_save_form_nonce_refresh"'),
        'switch_checked' => (bool) preg_match('~<input[^>]+name="fluent_forms_nonce_refresh"[^>]+checked~', $html),
        'pages' => $rows,
    ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), PHP_EOL;
    exit(0);
}
if ('refresh' !== $command || !in_array($argv[2] ?? '', array('on', 'off'), true)) {
    fwrite(STDERR, "Usage: php ff-gui.php refresh on|off [release] | tab\n");
    exit(2);
}
if (!preg_match('~value="static_bridge_save_form_nonce_refresh">.*?name="_wpnonce" value="([a-f0-9]+)"~s', $html, $nonce)) {
    echo json_encode(array('result' => 'FAIL', 'reason' => 'switch form or nonce not found', 'http' => $tab['status'])), PHP_EOL;
    exit(1);
}
$form = array('action' => 'static_bridge_save_form_nonce_refresh', '_wpnonce' => $nonce[1], '_wp_http_referer' => '/wp-admin/admin.php?page=static-bridge&tab=forms');
if ('on' === $argv[2]) {
    $form['fluent_forms_nonce_refresh'] = '1';
}
if ('release' === ($argv[3] ?? '')) {
    $form['release_token_dynamic_modes'] = '1';
}
$post = request('POST', '/wp-admin/admin-post.php', $cookie, $form);
$ok = 302 === $post['status'] && false !== strpos($post['location'], 'forms-updated=1');
echo json_encode(array('result' => $ok ? 'PASS' : 'FAIL', 'http' => $post['status'], 'location' => $post['location'])), PHP_EOL;
exit($ok ? 0 : 1);
