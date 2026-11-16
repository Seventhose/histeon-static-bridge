<?php
/**
 * Plugin Name: Static Bridge QA fault injector (disposable stack only)
 * Description: Simulates infrastructure faults for specific routes. Reads
 * ../../run/fault.json (outside the document root); does nothing without it.
 *
 * fault.json: {"/sample-page/": {"mode": "stale"|"404"|"500"|"500-then-ok"|"slow", "count": N, "seconds": S}}
 *  - stale:       200 with old HTML and no render proof (cache/CDN ignoring bypass signals)
 *  - 404 / 500:   always that status
 *  - 500-then-ok: 500 for the first N PHP hits on the path, then normal WordPress
 *  - slow:        sleep S seconds, then normal WordPress
 */
if (!defined('ABSPATH')) {
    exit;
}
(static function () {
    $run = dirname(ABSPATH) . '/run';
    $file = $run . '/fault.json';
    if (!is_file($file)) {
        return;
    }
    $faults = json_decode((string) file_get_contents($file), true);
    $path = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
    if (!is_array($faults) || !isset($faults[$path]) || !is_array($faults[$path])) {
        return;
    }
    $fault = $faults[$path];
    $log = static function (string $what) use ($run, $path) {
        file_put_contents($run . '/fault-hits.log', gmdate('c') . " {$path} {$what}\n", FILE_APPEND);
    };
    switch ((string) ($fault['mode'] ?? '')) {
        case 'stale':
            $log('stale');
            header('Content-Type: text/html; charset=UTF-8');
            header('X-Cache: HIT (sbqa simulated)');
            echo "<!doctype html><html><head><title>STALE CACHE COPY</title></head><body><p>SBQA_STALE_CACHE_BODY</p></body></html>";
            exit;
        case '404':
        case '500':
            $log((string) $fault['mode']);
            http_response_code((int) $fault['mode']);
            header('Content-Type: text/html; charset=UTF-8');
            echo '<!doctype html><title>SBQA ' . (int) $fault['mode'] . '</title>';
            exit;
        case '500-then-ok':
            $counter = $run . '/fault-counter-' . md5($path);
            $n = (int) @file_get_contents($counter);
            file_put_contents($counter, (string) ($n + 1));
            if ($n < (int) ($fault['count'] ?? 1)) {
                $log('500 (' . ($n + 1) . ')');
                http_response_code(500);
                echo '<!doctype html><title>SBQA transient 500</title>';
                exit;
            }
            $log('pass-through after ' . $n . ' failures');
            return;
        case 'slow':
            $log('slow ' . (int) ($fault['seconds'] ?? 5));
            sleep((int) ($fault['seconds'] ?? 5));
            return;
    }
})();
