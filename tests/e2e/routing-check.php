<?php
/**
 * Real HTTP routing checks against the disposable PHP-FPM 8.4 QA stack selected by
 * SBQA_STACK (see profile.sh; Nginx 127.0.0.1:18697 by default). Read-only
 * except for one anonymous POST to a public route, which WordPress handles.
 * Usage: php routing-check.php [expect-static|expect-wordpress]
 */
declare(strict_types=1);

require __DIR__ . '/profile.php';
$env = SBQA_ENV;
$current = realpath($env . '/wordpress/wp-content/static-bridge/current');
$expect = (string) ($argv[1] ?? 'expect-static');
$adminCookie = trim((string) @file_get_contents($env . '/run/cookie-administrator.txt'));

function req(string $path, string $method = 'GET', array $headers = array(), string $content = ''): array
{
    $ctx = stream_context_create(array('http' => array('method' => $method, 'header' => implode("\r\n", array_merge(array('User-Agent: static-bridge-qa-routing'), $headers)), 'content' => $content, 'ignore_errors' => true, 'follow_location' => 0, 'timeout' => 30)));
    $body = @file_get_contents(BASE . $path, false, $ctx);
    $raw = http_get_last_response_headers() ?: array();
    $out = array('status' => 0, 'route' => sbqa_route(sbqa_headers($raw)), 'type' => '', 'cache' => '', 'location' => '', 'body' => false === $body ? '' : $body);
    foreach ($raw as $i => $line) {
        if (0 === $i && preg_match('#^HTTP/\S+\s+(\d+)#', $line, $m)) {
            $out['status'] = (int) $m[1];
        } elseif (preg_match('#^(Content-Type|Cache-Control|Location):\s*(.*)$#i', $line, $m)) {
            $out[array('content-type' => 'type', 'cache-control' => 'cache', 'location' => 'location')[strtolower($m[1])]] = trim($m[2]);
        }
    }
    return $out;
}

$results = array();
$check = static function (string $name, bool $ok, array $r) use (&$results) {
    $results[] = array('ok' => $ok, 'name' => $name, 'status' => $r['status'], 'route' => $r['route'], 'type' => $r['type'], 'location' => $r['location']);
};

$wpMarker = static function (string $body): bool {
    return false !== stripos($body, 'wp-content/themes/') || false !== stripos($body, 'wp-includes/');
};
$routes = array('/' => 'index.html', '/hello-world/' => 'hello-world/index.html', '/sample-page/' => 'sample-page/index.html');
foreach ($routes as $path => $file) {
    $r = req($path);
    if ('expect-static' === $expect) {
        $same = $current && is_file($current . '/' . $file) && hash_equals((string) hash_file('sha256', $current . '/' . $file), hash('sha256', $r['body']));
        $check("anonymous GET {$path} served static and byte-identical to release", 200 === $r['status'] && 'static' === strtolower($r['route']) && $same && 0 === stripos($r['type'], 'text/html'), $r);
        $h = req($path, 'HEAD');
        $check("anonymous HEAD {$path} served static", 200 === $h['status'] && 'static' === strtolower($h['route']), $h);
    } else {
        $check("anonymous GET {$path} served by WordPress (no static header)", 200 === $r['status'] && '' === $r['route'] && $wpMarker($r['body']), $r);
    }
}
$r = req('/hello-world/?utm_source=qa');
$check('query string bypasses static and reaches WordPress', 200 === $r['status'] && '' === $r['route'], $r);
$r = req('/', 'GET', array('Cookie: wordpress_logged_in_qa=1'));
$check('logged-in-looking cookie bypasses static', 200 === $r['status'] && '' === $r['route'], $r);
if ('' !== $adminCookie) {
    $r = req('/hello-world/', 'GET', array($adminCookie));
    $check('real authenticated admin sees WordPress (admin bar), not static', 200 === $r['status'] && '' === $r['route'] && false !== strpos($r['body'], 'wpadminbar'), $r);
}
$r = req('/hello-world/', 'POST', array('Content-Type: application/x-www-form-urlencoded'), 'qa=1');
$check('POST to a public route bypasses static', '' === $r['route'] && $r['status'] > 0, $r);
$r = req('/wp-admin/');
$check('/wp-admin/ unauthenticated -> 302 to login (WordPress)', 302 === $r['status'] && false !== strpos($r['location'], 'wp-login.php') && '' === $r['route'], $r);
$r = req('/wp-admin');
$check('/wp-admin without slash -> 301 normalization', 301 === $r['status'], $r);
$r = req('/wp-login.php');
$check('/wp-login.php reachable (WordPress)', 200 === $r['status'] && '' === $r['route'] && false !== stripos($r['body'], 'user_login'), $r);
$r = req('/wp-json/');
$check('REST /wp-json/ reachable (WordPress JSON)', 200 === $r['status'] && '' === $r['route'] && is_array(json_decode($r['body'], true)), $r);
$r = req('/wp-admin/admin-ajax.php', 'POST', array('Content-Type: application/x-www-form-urlencoded'), 'action=static_bridge_status');
$check('admin-ajax anonymous call reaches WordPress and is refused (400/403)', in_array($r['status'], array(400, 403), true) && '' === $r['route'], $r);
$r = req('/hello-world/?preview=true');
$check('preview query bypasses static', '' === $r['route'], $r);
$r = req('/feed/');
$check('feed reaches WordPress', 200 === $r['status'] && '' === $r['route'] && false !== stripos($r['type'], 'xml'), $r);
$r = req('/wp-sitemap.xml');
// The baseline clone has blog_public=0, so WordPress itself disables core
// sitemaps and answers 404; what matters here is that it was not static.
$check('core sitemap reaches WordPress (404 from WP: blog_public=0)', in_array($r['status'], array(200, 404), true) && '' === $r['route'] && ($wpMarker($r['body']) || false !== stripos($r['type'], 'xml')), $r);
$r = req('/robots.txt');
$check('robots.txt reaches WordPress', 200 === $r['status'] && '' === $r['route'], $r);
$r = req('/route-that-does-not-exist-qa/');
$check('unknown public route -> WordPress 404 (non-strict fallback)', 404 === $r['status'] && '' === $r['route'] && $wpMarker($r['body']), $r);
if ($current) {
    $rel = basename($current);
    foreach (array('/wp-content/static-bridge/current/index.html', '/wp-content/static-bridge/releases/' . $rel . '/manifest.json', '/wp-content/static-bridge/.enabled', '/wp-content/static-bridge/current/hello-world/index.html', '/wp-content/ai1wm-backups/x.wpress', '/wp-content/static-bridge/', '/wp-content/static-bridge/current/manifest.json', '/wp-content/static-bridge/releases/') as $private) {
        $r = req($private);
        $check("private path denied: {$private}", 403 === $r['status'] || 404 === $r['status'], $r);
    }
}
$r = req('/wp-includes/css/dist/block-library/style.min.css');
$check('core static asset served directly by the web server', 200 === $r['status'] && 0 === stripos($r['type'], 'text/css'), $r);

$pass = count(array_filter($results, static fn ($x) => $x['ok']));
foreach ($results as $x) {
    echo ($x['ok'] ? '[PASS] ' : '[FAIL] ') . $x['name'] . ($x['ok'] ? '' : ' => ' . json_encode(array_diff_key($x, array('ok' => 1, 'name' => 1)))) . PHP_EOL;
}
echo "{$pass}/" . count($results) . " routing checks passed ({$expect}).\n";
exit($pass === count($results) ? 0 : 1);
