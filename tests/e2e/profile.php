<?php
/**
 * Shared by the PHP harness scripts: the stack selected by SBQA_STACK (see profile.sh).
 * Exact values only; anything else is refused, and so is a stack folder that setup-stack.sh
 * did not build for exactly this stack (marker file <env>/.sbqa-stack).
 */
declare(strict_types=1);

const SBQA_PROFILES = array(
    'nginx' => array('env' => 'wp69', 'port' => 18697, 'db' => 'sb_claude_wp69', 'server_software' => 'nginx/1.26.1', 'web' => 'nginx', 'wp' => '6.9', 'ff' => false),
    'apache' => array('env' => 'wp69a', 'port' => 18699, 'db' => 'sb_claude_wp69a', 'server_software' => 'Apache/2.4.43 (Unix)', 'web' => 'apache', 'wp' => '6.9', 'ff' => false),
    'nginx71' => array('env' => 'wp71', 'port' => 18731, 'db' => 'sb_claude_wp71', 'server_software' => 'nginx/1.26.1', 'web' => 'nginx', 'wp' => '7.1', 'ff' => false),
    'ff-nginx' => array('env' => 'wpff', 'port' => 18721, 'db' => 'sb_claude_ff', 'server_software' => 'nginx/1.26.1', 'web' => 'nginx', 'wp' => '6.9', 'ff' => true),
    'ff-apache' => array('env' => 'wpffa', 'port' => 18723, 'db' => 'sb_claude_ffa', 'server_software' => 'Apache/2.4.43 (Unix)', 'web' => 'apache', 'wp' => '6.9', 'ff' => true),
    'wc-nginx' => array('env' => 'wpwc', 'port' => 18741, 'db' => 'sb_claude_wc', 'server_software' => 'nginx/1.26.1', 'web' => 'nginx', 'wp' => '7.1', 'ff' => false, 'wc' => true),
    'wc-apache' => array('env' => 'wpwca', 'port' => 18743, 'db' => 'sb_claude_wca', 'server_software' => 'Apache/2.4.43 (Unix)', 'web' => 'apache', 'wp' => '7.1', 'ff' => false, 'wc' => true),
    // OpenLiteSpeed reads the .htaccess: the plugin writes Apache rules there. LSWS_EDITION is what OpenLiteSpeed
    // sets for PHP; with SBQA_NO_SERVER=1 the harness names no server at all, as WP-CLI and the system cron.
    'litespeed' => array('env' => 'wp69l', 'port' => 18751, 'db' => 'sb_claude_wp69l', 'server_software' => 'LiteSpeed', 'lsws_edition' => 'Openlitespeed 1.9.4', 'web' => 'apache', 'wp' => '6.9', 'ff' => false),
);
$sbqaStack = (string) (getenv('SBQA_STACK') ?: 'nginx');
if (!isset(SBQA_PROFILES[$sbqaStack])) {
    fwrite(STDERR, "REFUSED: SBQA_STACK must be 'nginx', 'apache', 'nginx71', 'ff-nginx', 'ff-apache', 'wc-nginx', 'wc-apache' or 'litespeed'.\n");
    exit(2);
}
define('SBQA_STACK', $sbqaStack);
$sbqaProfile = SBQA_PROFILES[$sbqaStack];
if ('1' === getenv('SBQA_NO_SERVER')) {
    $sbqaProfile['server_software'] = '';
    unset($sbqaProfile['lsws_edition']);
}
define('SBQA_PROFILE', $sbqaProfile);
if (isset(SBQA_PROFILE['lsws_edition'])) {
    $_SERVER['LSWS_EDITION'] = SBQA_PROFILE['lsws_edition'];
}
define('BASE', 'http://127.0.0.1:' . SBQA_PROFILE['port']);
// Stack data root: exported by profile.sh (default tests/e2e/stacks).
$sbqaHome = (string) (getenv('SBQA_HOME') ?: __DIR__ . '/stacks');
$sbqaEnv = '/' === ($sbqaHome[0] ?? '') ? realpath(rtrim($sbqaHome, '/') . '/' . SBQA_PROFILE['env']) : false;
if (!$sbqaEnv || $sbqaStack !== trim((string) @file_get_contents($sbqaEnv . '/.sbqa-stack'))) {
    fwrite(STDERR, "REFUSED: no stack built by setup-stack.sh for SBQA_STACK={$sbqaStack} under {$sbqaHome}.\n");
    exit(2);
}
define('SBQA_ENV', $sbqaEnv);

/**
 * Which handler produced a response, from lower-cased header names => values.
 * Nginx: the plugin's own X-Static-Bridge-Route header. Apache: the plugin's
 * .htaccess rules set no header, so the QA vhost tags (on success only) any
 * file Apache served from wp-content/static-bridge/ with X-SBQA-Apache-File.
 * OpenLiteSpeed (litespeed stack) has no per-directory header for a rewritten request: a file it
 * serves carries an ETag and no X-Powered-By, which no WordPress page sends together.
 */
function sbqa_route(array $headers): string
{
    $value = static function (string $name) use ($headers): string {
        $v = $headers[$name] ?? '';
        return strtolower(trim((string) (is_array($v) ? ($v[0] ?? '') : $v)));
    };
    if ('litespeed' === SBQA_STACK) {
        return '' !== $value('etag') && '' === $value('x-powered-by') ? 'static' : '';
    }
    if ('apache' === SBQA_PROFILE['web']) {
        return '' !== $value('x-sbqa-apache-file') ? 'static' : '';
    }
    return $value('x-static-bridge-route');
}

/** Parses raw "Name: value" header lines into lower-cased name => list of values. */
function sbqa_headers(array $raw): array
{
    $out = array();
    foreach ($raw as $line) {
        if (false !== strpos((string) $line, ':') && !preg_match('#^HTTP/#', (string) $line)) {
            [$k, $v] = explode(':', (string) $line, 2);
            $out[strtolower(trim($k))][] = trim($v);
        }
    }
    return $out;
}
