<?php
/**
 * Drives the Static Bridge admin GUI endpoints over real HTTP against the
 * disposable loopback QA stack selected by SBQA_STACK (see profile.sh: Nginx or
 * Apache, 127.0.0.1 only), mimicking admin.js.
 *
 * Usage: php http-gui.php <role> <command> [args]
 *   nonce                 print whether the admin page exposes a nonce
 *   export                status -> start/resume -> tick until terminal state
 *   call <action> [k=v..] single admin-ajax call
 *   get <path>            GET with the role's cookies, print status/headers
 */
declare(strict_types=1);

require __DIR__ . '/profile.php';
$env = SBQA_ENV;
$role = (string) ($argv[1] ?? '');
$command = (string) ($argv[2] ?? '');
$cookieFile = $env . '/run/cookie-' . preg_replace('/[^a-z_]/', '', $role) . '.txt';
$cookie = 'anonymous' === $role ? '' : trim((string) @file_get_contents($cookieFile));
if ('anonymous' !== $role && '' === $cookie) {
    fwrite(STDERR, "No cookie for role {$role}; run the harness 'session' mode first.\n");
    exit(2);
}

function http(string $method, string $path, string $cookie, array $form = null): array
{
    $headers = array('User-Agent: static-bridge-qa-gui');
    if ('' !== $cookie) {
        $headers[] = $cookie;
    }
    $content = '';
    if (null !== $form) {
        $content = http_build_query($form);
        $headers[] = 'Content-Type: application/x-www-form-urlencoded';
    }
    $ctx = stream_context_create(array('http' => array(
        'method' => $method, 'header' => implode("\r\n", $headers), 'content' => $content,
        'ignore_errors' => true, 'follow_location' => 0, 'timeout' => 190,
    )));
    $start = microtime(true);
    $body = @file_get_contents(BASE . $path, false, $ctx);
    $raw = function_exists('http_get_last_response_headers') ? (http_get_last_response_headers() ?: array()) : ($http_response_header ?? array());
    $status = 0;
    $hdr = array();
    foreach ($raw as $i => $line) {
        if (0 === $i && preg_match('#^HTTP/\S+\s+(\d+)#', $line, $m)) {
            $status = (int) $m[1];
        } elseif (false !== strpos($line, ':')) {
            [$k, $v] = explode(':', $line, 2);
            $hdr[strtolower(trim($k))][] = trim($v);
        }
    }
    return array('status' => $status, 'headers' => $hdr, 'body' => false === $body ? '' : $body, 'ms' => (int) round((microtime(true) - $start) * 1000));
}

function admin_nonce(string $cookie): string
{
    $page = http('GET', '/wp-admin/admin.php?page=static-bridge', $cookie);
    if (preg_match('#var StaticBridgeAdmin = (\{.*?\});#s', $page['body'], $m)) {
        $cfg = json_decode($m[1], true);
        return is_array($cfg) ? (string) ($cfg['nonce'] ?? '') : '';
    }
    return '';
}

function ajax(string $action, string $nonce, string $cookie, array $params = array()): array
{
    $r = http('POST', '/wp-admin/admin-ajax.php', $cookie, array_merge(array('action' => $action, 'nonce' => $nonce), $params));
    $json = json_decode($r['body'], true);
    return array('http' => $r['status'], 'ms' => $r['ms'], 'json' => $json, 'raw' => is_array($json) ? null : substr($r['body'], 0, 300));
}

switch ($command) {
    case 'nonce':
        $page = http('GET', '/wp-admin/admin.php?page=static-bridge', $cookie);
        echo json_encode(array('page_http' => $page['status'], 'nonce_present' => '' !== admin_nonce($cookie)), JSON_PRETTY_PRINT), PHP_EOL;
        break;
    case 'call':
        $params = array();
        foreach (array_slice($argv, 4) as $kv) {
            [$k, $v] = array_pad(explode('=', $kv, 2), 2, '');
            $params[$k] = $v;
        }
        $nonce = 'bad-nonce' === ($params['nonce'] ?? '') ? 'bad-nonce' : admin_nonce($cookie);
        unset($params['nonce']);
        echo json_encode(ajax((string) ($argv[3] ?? ''), $nonce, $cookie, $params), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), PHP_EOL;
        break;
    case 'diagnose':
        // Runs the full Diagnostica like the admin screen does and prints one line per check.
        $nonce = admin_nonce($cookie);
        $data = ajax('static_bridge_diagnostic_start', $nonce, $cookie)['json']['data'] ?? array();
        for ($i = 0; $i < 200 && 'running' === (string) ($data['status'] ?? ''); $i++) {
            usleep(200000);
            $data = ajax('static_bridge_diagnostic_tick', $nonce, $cookie)['json']['data'] ?? array();
        }
        $rows = array();
        foreach ((array) ($data['results'] ?? array()) as $r) {
            $rows[] = array('id' => (string) ($r['id'] ?? ''), 'status' => (string) ($r['status'] ?? ''), 'label' => (string) ($r['label'] ?? ''), 'message' => substr((string) ($r['summary'] ?? $r['message'] ?? ''), 0, 160));
        }
        echo json_encode(array('status' => $data['status'] ?? null, 'results' => $rows), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), PHP_EOL;
        break;
    case 'export':
        $nonce = admin_nonce($cookie);
        $status = ajax('static_bridge_status', $nonce, $cookie);
        $state = (string) ($status['json']['data']['status'] ?? '');
        $kick = in_array($state, array('paused'), true) ? 'static_bridge_resume' : 'static_bridge_start';
        $first = ajax($kick, $nonce, $cookie);
        $log = array(array('call' => $kick, 'http' => $first['http'], 'status' => $first['json']['data']['status'] ?? null, 'message' => $first['json']['data']['message'] ?? null));
        $data = $first['json']['data'] ?? array();
        $ticks = 0;
        $started = microtime(true);
        while ($first['http'] === 200 && $ticks < 600 && in_array((string) ($data['status'] ?? ''), array('running', 'queued', 'discovering', 'crawling', 'publishing'), true)) {
            usleep(!empty($data['workerBusy']) ? 1000000 : 350000);
            $tick = ajax('static_bridge_tick', $nonce, $cookie);
            ++$ticks;
            $data = $tick['json']['data'] ?? array();
            if (200 !== $tick['http']) {
                $log[] = array('call' => 'tick', 'http' => $tick['http'], 'message' => $data['message'] ?? $tick['raw']);
                $status = ajax('static_bridge_status', $nonce, $cookie);
                $data = $status['json']['data'] ?? array();
            }
        }
        $summary = array_intersect_key(is_array($data) ? $data : array(), array_flip(array('status', 'discovered', 'processed', 'saved', 'skipped', 'errorCount', 'error_count', 'preserved', 'preservedCount', 'message', 'releaseId', 'release', 'percent')));
        echo json_encode(array('kick' => $log, 'ticks' => $ticks, 'seconds' => round(microtime(true) - $started, 2), 'final' => $summary, 'final_keys' => array_keys(is_array($data) ? $data : array())), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), PHP_EOL;
        break;
    case 'get':
        $r = http('GET', (string) ($argv[3] ?? '/'), $cookie);
        echo json_encode(array('status' => $r['status'], 'route' => sbqa_route($r['headers']), 'php' => isset($r['headers']['x-powered-by']), 'wordpress_body' => false !== stripos($r['body'], 'wp-content/themes/') || false !== stripos($r['body'], 'wp-includes/'), 'location' => $r['headers']['location'][0] ?? '', 'xfo' => implode(', ', $r['headers']['x-frame-options'] ?? array()), 'nosniff' => implode(', ', $r['headers']['x-content-type-options'] ?? array()), 'bytes' => strlen($r['body']), 'sha256' => hash('sha256', $r['body'])), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), PHP_EOL;
        break;
    default:
        fwrite(STDERR, "Unknown command.\n");
        exit(2);
}
