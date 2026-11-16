<?php
/**
 * QA only — router for `php -S` that plays two Cloudflare roles for the disposable stack
 * (Static Bridge 2.0.17 Cloudflare E2E). Never contacts Cloudflare.
 *
 *   SBQA_CF_ROLE=edge  a reverse proxy in front of the origin web server (SBQA_CF_ORIGIN):
 *                      adds cf-ray/server headers; optionally challenges requests whose
 *                      User-Agent starts with "Static-Bridge/" (403 + cf-mitigated), rewrites
 *                      HTML like Rocket Loader and Email Obfuscation, and caches eligible
 *                      responses honouring the origin Cache-Control (Edge TTL "use cache-control
 *                      header if present, bypass if not"), with cf-cache-status.
 *   SBQA_CF_ROLE=api   POST /client/v4/zones/<zone>/purge_cache: checks zone and bearer token
 *                      against credentials.json, purges the edge cache, logs every call
 *                      (never the token), can be told to fail via api-mode.json.
 *
 * State lives in SBQA_CF_STATE (the stack's run/cf folder): edge-mode.json, api-mode.json,
 * credentials.json, cache/, edge.log, api.log.
 */
declare(strict_types=1);

$state = (string) getenv('SBQA_CF_STATE');
$role = (string) getenv('SBQA_CF_ROLE');
if ('' === $state || !is_dir($state) || !in_array($role, array('edge', 'api'), true)) {
    http_response_code(500);
    exit('QA fake misconfigured');
}
$json = static function (string $file, array $default = array()) use ($state): array {
    $data = json_decode((string) @file_get_contents($state . '/' . $file), true);
    return is_array($data) ? $data + $default : $default;
};
$log = static function (string $file, array $entry) use ($state): void {
    $entry['t'] = microtime(true);
    file_put_contents($state . '/' . $file, json_encode($entry, JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND | LOCK_EX);
};
@mkdir($state . '/cache', 0700);

if ('api' === $role) {
    header('Content-Type: application/json');
    $path = (string) parse_url((string) $_SERVER['REQUEST_URI'], PHP_URL_PATH);
    $credentials = $json('credentials.json');
    $auth = (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? '');
    $body = json_decode((string) file_get_contents('php://input'), true);
    $zoneOk = 1 === preg_match('#^/client/v4/zones/([a-f0-9]{32})/purge_cache$#', $path, $m) && hash_equals((string) ($credentials['zone'] ?? ''), $m[1]);
    $authOk = '' !== (string) ($credentials['token'] ?? '') && hash_equals('Bearer ' . $credentials['token'], $auth);
    $entry = array('method' => $_SERVER['REQUEST_METHOD'], 'path' => $path, 'zone_ok' => $zoneOk, 'auth_ok' => $authOk, 'body' => $body);
    $mode = $json('api-mode.json', array('fail' => 0));
    if ((int) $mode['fail'] > 0) {
        $mode['fail'] = (int) $mode['fail'] - 1;
        file_put_contents($state . '/api-mode.json', json_encode($mode));
        $log('api.log', $entry + array('answer' => 'injected-failure'));
        http_response_code(503);
        exit('{"success":false,"errors":[{"code":1101,"message":"QA injected failure"}],"messages":[],"result":null}');
    }
    if ('POST' !== $_SERVER['REQUEST_METHOD'] || !$zoneOk || !$authOk) {
        $log('api.log', $entry + array('answer' => 'auth-error'));
        http_response_code(403);
        exit('{"success":false,"errors":[{"code":10000,"message":"Authentication error"}],"messages":[],"result":null}');
    }
    if (is_array($body) && true === ($body['purge_everything'] ?? null) && 1 === count($body)) {
        foreach (glob($state . '/cache/*.json') ?: array() as $file) {
            unlink($file);
        }
    } elseif (is_array($body) && isset($body['files']) && is_array($body['files']) && 1 === count($body) && count($body['files']) >= 1 && count($body['files']) <= 30) {
        foreach ($body['files'] as $url) {
            @unlink($state . '/cache/' . sha1((string) $url) . '.json');
        }
    } else {
        $log('api.log', $entry + array('answer' => 'bad-request'));
        http_response_code(400);
        exit('{"success":false,"errors":[{"code":1012,"message":"Request must contain one of purge_everything or files (max 30)"}],"messages":[],"result":null}');
    }
    $log('api.log', $entry + array('answer' => 'ok'));
    exit('{"success":true,"errors":[],"messages":[],"result":{"id":"' . $m[1] . '"}}');
}

// ---- edge -------------------------------------------------------------------------------
$origin = (string) getenv('SBQA_CF_ORIGIN');
if (!preg_match('#^http://127\.0\.0\.1:[0-9]{2,5}$#', $origin)) {
    http_response_code(500);
    exit('QA edge origin misconfigured');
}
$mode = $json('edge-mode.json', array('challenge' => false, 'rewrite' => false, 'cache' => false));
$method = (string) $_SERVER['REQUEST_METHOD'];
$uri = (string) $_SERVER['REQUEST_URI'];
$host = (string) ($_SERVER['HTTP_HOST'] ?? '');
$path = (string) parse_url($uri, PHP_URL_PATH);
$query = (string) parse_url($uri, PHP_URL_QUERY);
$ua = (string) ($_SERVER['HTTP_USER_AGENT'] ?? '');
$cookie = (string) ($_SERVER['HTTP_COOKIE'] ?? '');
$ray = bin2hex(random_bytes(8)) . '-QA';
$entry = array('method' => $method, 'uri' => $uri, 'ua' => substr($ua, 0, 60), 'render' => false !== strpos($query, 'static_bridge_render'));
header('cf-ray: ' . $ray);
header('server: cloudflare');

if (!empty($mode['challenge']) && 0 === strpos($ua, 'Static-Bridge/')) {
    $log('edge.log', $entry + array('answer' => 'challenge'));
    http_response_code(403);
    header('cf-mitigated: challenge');
    header('Content-Type: text/html; charset=UTF-8');
    header('Cache-Control: private, max-age=0, no-store, no-cache, must-revalidate');
    exit('<!DOCTYPE html><html><head><title>Just a moment...</title></head><body><script src="/cdn-cgi/challenge-platform/h/g/orchestrate/chl_page/v1?ray=' . $ray . '"></script></body></html>');
}

$eligible = !empty($mode['cache']) && in_array($method, array('GET', 'HEAD'), true) && '' === $query
    && '/' === substr($path, -1)
    && !preg_match('/wordpress_logged_in_|comment_author_|wp-postpass_|woocommerce_items_in_cart|woocommerce_cart_hash|wp_woocommerce_session_/', $cookie)
    && !preg_match('#^/(?:wp-admin|wp-json|wp-content|wp-includes)/#i', $path)
    && false === stripos($path, '/feed/');
$key = $state . '/cache/' . sha1('http://' . $host . $uri) . '.json';
if ($eligible && is_file($key)) {
    $cached = json_decode((string) file_get_contents($key), true);
    if (is_array($cached) && $cached['expires'] > time()) {
        http_response_code((int) $cached['status']);
        foreach ($cached['headers'] as $line) {
            header($line, false);
        }
        header('cf-cache-status: HIT');
        $log('edge.log', $entry + array('answer' => 'HIT ' . $cached['status']));
        echo 'HEAD' === $method ? '' : $cached['body'];
        exit;
    }
}

$forward = array('Host: ' . $host, 'X-Forwarded-For: 127.0.0.1', 'CF-Connecting-IP: 127.0.0.1', 'CF-Ray: ' . $ray, 'Accept-Encoding: identity');
foreach (getallheaders() as $name => $value) {
    if (!in_array(strtolower((string) $name), array('host', 'connection', 'content-length', 'accept-encoding', 'transfer-encoding'), true)) {
        $forward[] = $name . ': ' . $value;
    }
}
$curl = curl_init($origin . $uri);
curl_setopt_array($curl, array(
    CURLOPT_CUSTOMREQUEST => $method,
    CURLOPT_HTTPHEADER => $forward,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HEADER => true,
    CURLOPT_FOLLOWLOCATION => false,
    CURLOPT_TIMEOUT => 120,
    CURLOPT_NOBODY => 'HEAD' === $method,
));
if (!in_array($method, array('GET', 'HEAD'), true)) {
    curl_setopt($curl, CURLOPT_POSTFIELDS, (string) file_get_contents('php://input'));
}
$raw = curl_exec($curl);
if (false === $raw) {
    $log('edge.log', $entry + array('answer' => '502'));
    http_response_code(502);
    exit('QA edge: origin unreachable');
}
$status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
$headerSize = (int) curl_getinfo($curl, CURLINFO_HEADER_SIZE);
$body = (string) substr($raw, $headerSize);
$lines = array();
$contentType = '';
$cacheControl = '';
$setCookie = false;
foreach (preg_split('/\r\n/', (string) substr($raw, 0, $headerSize)) as $line) {
    if (false === strpos($line, ':')) {
        continue;
    }
    [$name, $value] = array_map('trim', explode(':', $line, 2));
    $lower = strtolower($name);
    if (in_array($lower, array('transfer-encoding', 'connection', 'content-length', 'content-encoding', 'server', 'keep-alive', 'date'), true)) {
        continue;
    }
    if ('content-type' === $lower) {
        $contentType = $value;
    } elseif ('cache-control' === $lower) {
        $cacheControl = strtolower($value);
    } elseif ('set-cookie' === $lower) {
        $setCookie = true;
    }
    $lines[] = $name . ': ' . $value;
}
if (!empty($mode['rewrite']) && 200 === $status && false !== stripos($contentType, 'text/html') && false !== stripos($body, '</body>')) {
    $body = preg_replace('#<script(?![^>]*\btype=)(?=[^>]*\bsrc=)#i', '<script type="9f2b3c1d5e7a8b0c4d6e8f10-text/javascript"', $body);
    $body = str_ireplace('</body>', '<p>QA contatto: <a href="/cdn-cgi/l/email-protection#0f6a" class="__cf_email__" data-cfemail="0f6a776e627f636a">[email&#160;protected]</a></p>'
        . '<script data-cfasync="false" src="/cdn-cgi/scripts/5c5dd728/cloudflare-static/email-decode.min.js"></script>'
        . '<script src="/cdn-cgi/scripts/7d0fa10a/cloudflare-static/rocket-loader.min.js" data-cf-settings="9f2b3c1d5e7a8b0c4d6e8f10-|49" defer></script></body>', $body);
}
$ttl = 0;
if (preg_match('/(?:^|,)\s*s-maxage=(\d+)/', $cacheControl, $match) || preg_match('/(?:^|,)\s*max-age=(\d+)/', $cacheControl, $match)) {
    $ttl = (int) $match[1];
}
$storable = $eligible && in_array($status, array(200, 404), true) && $ttl > 0 && !$setCookie
    && !preg_match('/no-store|private|no-cache/', $cacheControl);
$cacheStatus = $eligible ? ($storable ? 'MISS' : 'BYPASS') : 'DYNAMIC';
if ($storable && 'GET' === $method) {
    file_put_contents($key, json_encode(array('status' => $status, 'headers' => $lines, 'body' => $body, 'expires' => time() + $ttl, 'url' => 'http://' . $host . $uri)));
}
http_response_code($status);
foreach ($lines as $line) {
    header($line, false);
}
header('cf-cache-status: ' . $cacheStatus);
$log('edge.log', $entry + array('answer' => $cacheStatus . ' ' . $status));
echo 'HEAD' === $method ? '' : $body;
