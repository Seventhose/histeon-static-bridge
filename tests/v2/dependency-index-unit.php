<?php
/** Pure tests for the V2 collector and migration-safe route-path model. */

declare(strict_types=1);

define('ABSPATH', __DIR__ . DIRECTORY_SEPARATOR);
$GLOBALS['sb_v2_test_home'] = 'https://example.test/wp';
function home_url($path = '')
{
    return rtrim($GLOBALS['sb_v2_test_home'], '/') . '/' . ltrim((string) $path, '/');
}
function wp_parse_url($url, $component = -1)
{
    return parse_url($url, $component);
}
function trailingslashit($value)
{
    return rtrim((string) $value, '/') . '/';
}

require_once __DIR__ . '/../../plugin/dependency-index.php';

$reflection = new ReflectionClass('Static_Bridge_Dependency_Index');
$index = $reflection->newInstanceWithoutConstructor();
$extract = $reflection->getMethod('extract_elementor_dependencies');
$extract->setAccessible(true);
$portable = $reflection->getMethod('portable_route_path');
$portable->setAccessible(true);
$failures = array();
$checks = 0;
$assert = static function ($condition, $name) use (&$failures, &$checks): void {
    $checks++;
    if (!$condition) {
        $failures[] = $name;
        fwrite(STDERR, "[FAIL] {$name}\n");
    } else {
        fwrite(STDOUT, "[PASS] {$name}\n");
    }
};

$html = '<html><body><header data-elementor-type="header" data-elementor-id="81"></header>'
    . '<main data-elementor-id="200" data-elementor-type="wp-page"></main>'
    . '<footer data-elementor-type=\'footer\' data-elementor-id=\'92\'></footer>'
    . '<div data-elementor-type="footer" data-elementor-id="92"></div></body></html>';
$parsed = $extract->invoke($index, $html);
$assert(!empty($parsed['complete']) && 3 === count($parsed['dependencies']), 'Collector finds and deduplicates valid Elementor document references');
$assert(isset($parsed['dependencies']['81'], $parsed['dependencies']['200'], $parsed['dependencies']['92']), 'Collector accepts either attribute order and quote style');

$embeddedMarkup = '<!-- <section data-elementor-type="header" data-elementor-id="900"></section> -->'
    . '<script type="application/json">{"sample":"<div data-elementor-type=header data-elementor-id=901>"}</script>'
    . '<div data-label="data-elementor-type=header" data-elementor-type="footer" data-elementor-id="902"></div>';
$embedded = $extract->invoke($index, $embeddedMarkup);
$assert(!empty($embedded['complete']) && 1 === count($embedded['dependencies']) && isset($embedded['dependencies']['902']), 'Collector skips comment/script examples and attribute-value lookalikes');

$malformedUnrelatedTag = '<a href="https://example.test/<span class="icon"></span>text</a>'
    . '<main data-elementor-type="wp-page" data-elementor-id="903"></main>';
$recovered = $extract->invoke($index, $malformedUnrelatedTag);
$assert(!empty($recovered['complete']) && isset($recovered['dependencies']['903']), 'Collector ignores malformed unrelated markup without losing following Elementor documents');

$missingId = $extract->invoke($index, '<div data-elementor-type="header"></div>');
$assert(empty($missingId['complete']), 'Collector fails closed when a typed document has no stable ID');

$malformed = $extract->invoke($index, '<div data-elementor-type="header" data-elementor-id="../99"></div>');
$assert(empty($malformed['complete']), 'Collector fails closed on malformed document IDs');

$unquoted = $extract->invoke($index, '<div data-elementor-type=header data-elementor-id="81"></div>');
$html_parser_available = class_exists('WP_HTML_Tag_Processor') || class_exists('DOMDocument');
$assert($html_parser_available
    ? (!empty($unquoted['complete']) && isset($unquoted['dependencies']['81']))
    : empty($unquoted['complete']), 'Ambiguous Elementor attributes use an HTML5 parser when available and otherwise fail closed');

$portablePath = $portable->invoke($index, '/wp/news/item/');
$assert('/news/item/' === $portablePath, 'Stored route path strips the WordPress home subdirectory');
$rootPath = $portable->invoke($index, '/wp/');
$assert('/' === $rootPath, 'Stored homepage route remains portable across host migrations');
$outsideHome = $portable->invoke($index, '/other/item/');
$assert('' === $outsideHome, 'Collector rejects routes outside the configured WordPress home path');

if (!empty($failures)) {
    fwrite(STDERR, count($failures) . " V2 collector test(s) failed.\n");
    exit(1);
}
fwrite(STDOUT, $checks . " V2 dependency-index unit tests passed.\n");
