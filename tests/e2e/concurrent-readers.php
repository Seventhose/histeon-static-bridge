<?php
/**
 * Anonymous readers hammer static routes while an export publishes a new
 * release on the disposable stack. Every response must be a complete 200 whose
 * body matches that route's file in some published release (old or new).
 * A 200 served dynamically by WordPress is counted separately: while a route
 * is being rendered its static file is moved aside on purpose (render bypass).
 * Usage: php concurrent-readers.php <seconds> [replaced-ok]
 * replaced-ok: pages are also rewritten in place during the run (pushes on Nginx, where
 * `current` is served directly): a complete older body that is no longer on disk is then
 * reported as body_replaced_in_place instead of failing; body_invalid still fails.
 *
 * Known Apache race (reproduced without the plugin, see docs/SERVER-SETUP.md): Apache takes the
 * file size during its path checks and opens the file afterwards, so a file or `current` link
 * replaced in between yields the new body cut (or padded) to the old size, with a matching
 * Content-Length. Mismatches are classified so that this signature is recognised at once.
 * Since 2.0.20 Apache serves immutable copies (static-bridge/served/<id>/): every body must
 * then also match a file there. `body_invalid` counts bodies that are not a complete page
 * (no closing </html>, NUL padding, or a Content-Length different from the body) on any server.
 */
declare(strict_types=1);

require __DIR__ . '/profile.php';
$env = SBQA_ENV;
$releases = $env . '/wordpress/wp-content/static-bridge/releases';
$served = $env . '/wordpress/wp-content/static-bridge/served';
$seconds = max(1, (int) ($argv[1] ?? 8));
$replacedOk = 'replaced-ok' === ($argv[2] ?? '');
$routes = array('/' => 'index.html', '/hello-world/' => 'hello-world/index.html', '/sample-page/' => 'sample-page/index.html');
$results = array('requests' => 0, 'ok' => 0, 'non200' => 0, 'dynamic_fallback_200' => 0, 'body_mismatch' => 0, 'body_invalid' => 0, 'curl_errors' => 0);
$declared = array(); // body hash => Content-Length header of the first response with that body
$bodies = array();
$deadline = microtime(true) + $seconds;
while (microtime(true) < $deadline) {
    $multi = curl_multi_init();
    $handles = array();
    foreach ($routes as $path => $file) {
        for ($i = 0; $i < 4; $i++) {
            $h = curl_init(BASE . $path);
            curl_setopt_array($h, array(CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_TIMEOUT => 10));
            curl_multi_add_handle($multi, $h);
            $handles[] = array($h, $path, $file);
        }
    }
    do {
        curl_multi_exec($multi, $running);
        curl_multi_select($multi, 0.2);
    } while ($running > 0);
    while ($info = curl_multi_info_read($multi)) {
        if (CURLE_OK !== $info['result']) {
            ++$results['curl_errors'];
        }
    }
    foreach ($handles as [$h, $path, $file]) {
        $raw = (string) curl_multi_getcontent($h);
        $code = (int) curl_getinfo($h, CURLINFO_RESPONSE_CODE);
        $headerSize = (int) curl_getinfo($h, CURLINFO_HEADER_SIZE);
        $headers = substr($raw, 0, $headerSize);
        $body = substr($raw, $headerSize);
        ++$results['requests'];
        if (200 !== $code) {
            ++$results['non200'];
        } elseif ('static' !== sbqa_route(sbqa_headers(explode("\r\n", $headers)))) {
            ++$results['dynamic_fallback_200'];
        } else {
            $length = (int) curl_getinfo($h, CURLINFO_CONTENT_LENGTH_DOWNLOAD);
            if (1 !== preg_match('~</html>\s*\z~i', $body) || false !== strpos($body, "\0") || ($length >= 0 && $length !== strlen($body))) {
                ++$results['body_invalid'];
            }
            $h256 = hash('sha256', $body);
            if (!isset($bodies[$file][$h256])) {
                $bodies[$file][$h256] = $body; // kept once per distinct body, to explain any mismatch
                $declared[$h256] = $length;
            }
            ++$results['ok'];
        }
        curl_multi_remove_handle($multi, $h);
        curl_close($h);
    }
    curl_multi_close($multi);
}
// Every observed body must equal the route file of some release or served copy on disk
// (served copies keep the versions Apache served for at least 60 s after they are retired).
$known = array();
foreach (array_merge(glob($releases . '/*', GLOB_ONLYDIR) ?: array(), glob($served . '/*', GLOB_ONLYDIR) ?: array()) as $release) {
    foreach ($routes as $file) {
        if (is_file($release . '/' . $file)) {
            $known[$file][hash_file('sha256', $release . '/' . $file)] = true;
        }
    }
}
$distinct = 0;
foreach ($bodies as $file => $hashes) {
    foreach (array_keys($hashes) as $hash) {
        ++$distinct;
        if (!isset($known[$file][$hash])) {
            ++$results['body_mismatch'];
            // Classify: a truncated copy of a release file is a real defect; a complete
            // page that is simply no longer on disk was replaced in place afterwards.
            $body = $bodies[$file][$hash];
            $results['mismatches'][] = array(
                'route_file' => $file,
                'bytes' => strlen($body),
                'ends_with_html_close' => 1 === preg_match('~</html>\s*$~i', $body),
                // Equal to the body: the server itself sent this length (not a cut connection).
                'content_length_header' => $declared[$hash] ?? -1,
                // Apache size race: the length of another version of the same file on disk.
                'length_of_other_release_file' => (static function () use ($releases, $served, $file, $body) {
                    foreach (array_merge(glob($releases . '/*/' . $file) ?: array(), glob($served . '/*/' . $file) ?: array()) as $candidate) {
                        if (filesize($candidate) === strlen($body)) {
                            return basename(dirname($candidate, substr_count($file, '/') + 1));
                        }
                    }
                    return '';
                })(),
                'prefix_of_release_file' => (static function () use ($releases, $served, $file, $body) {
                    foreach (array_merge(glob($releases . '/*/' . $file) ?: array(), glob($served . '/*/' . $file) ?: array()) as $candidate) {
                        $c = (string) file_get_contents($candidate);
                        if (strlen($body) < strlen($c) && 0 === strncmp($c, $body, strlen($body))) {
                            return basename(dirname($candidate, substr_count($file, '/') + 1));
                        }
                    }
                    return '';
                })(),
            );
            @file_put_contents($env . '/run/readers-mismatch-' . substr($hash, 0, 12) . '.html', $body);
        }
    }
}
$results['distinct_bodies_seen'] = $distinct;
if ($replacedOk) {
    $results['body_replaced_in_place'] = $results['body_mismatch'];
    $results['body_mismatch'] = 0;
}
$results['result'] = $results['requests'] > 0 && 0 === $results['non200'] + $results['body_mismatch'] + $results['body_invalid'] + $results['curl_errors'] ? 'PASS' : 'FAIL';
echo json_encode($results), PHP_EOL;
exit('PASS' === $results['result'] ? 0 : 1);
