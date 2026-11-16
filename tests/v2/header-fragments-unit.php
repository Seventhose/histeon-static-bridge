<?php
declare(strict_types=1);

define('ABSPATH', __DIR__ . DIRECTORY_SEPARATOR);
$GLOBALS['sb_header_fragment_meta'] = array();
function get_post_meta($post_id, $meta_key, $single = false)
{
    return $GLOBALS['sb_header_fragment_meta'][$post_id][$meta_key] ?? '';
}
require_once __DIR__ . '/../../plugin/header-fragments.php';

$passed = 0;
$check = static function (bool $condition, string $label) use (&$passed): void {
    if (!$condition) {
        fwrite(STDERR, "[FAIL] {$label}\n");
        exit(1);
    }
    $passed++;
    echo "[PASS] {$label}\n";
};

$header = '<div class="elementor-location-header" data-elementor-type="header" data-elementor-id="42"><header><span>Logo</span></header></div>';
$html = "<!doctype html>\n<html><body><main>before</main>" . $header . "<footer>after</footer></body></html>";
$extracted = Static_Bridge_Header_Fragments::extract_unique_elementor_header($html);
$check(is_array($extracted) && $extracted['markup'] === $header, 'extracts the header as exact source bytes');
$check(is_array($extracted) && '42' === $extracted['owner_id'] && 'header' === $extracted['owner_type'], 'captures the Elementor header document identity');

$variant = hash('sha256', $header);
$filename = Static_Bridge_Header_Fragments::include_filename('42', $variant);
$uri = Static_Bridge_Header_Fragments::include_uri('42', $variant);
$transformed = Static_Bridge_Header_Fragments::replace_header_with_include($html, $extracted, '42', $variant);
$expectedDirective = '<!--#include virtual="/wp-content/static-bridge/current/fragments/headers/42/current/' . $filename . '" -->';
$check(is_string($filename) && str_starts_with($filename, 'elementor-42-') && str_ends_with($filename, '.html'), 'generates a fixed-name fragment path from a validated ID and hash');
$check(is_string($uri) && $uri === Static_Bridge_Header_Fragments::INCLUDE_PREFIX . '42/current/' . $filename, 'uses only the private Static Bridge include namespace and generation pointer');
$check(is_string($transformed) && $transformed === str_replace($header, $expectedDirective, $html), 'replaces only the header wrapper with the trusted SSI include');
$check(is_string($transformed) && !Static_Bridge_Header_Fragments::contains_ssi_directive(substr($html, 0, $extracted['start'])), 'preserves surrounding route bytes without introducing source directives');

$check(null === Static_Bridge_Header_Fragments::extract_unique_elementor_header('<main>no Elementor header</main>'), 'fails closed when no unique header exists');
$check(null === Static_Bridge_Header_Fragments::extract_unique_elementor_header($html . $header), 'fails closed when multiple headers exist');
$badId = str_replace('data-elementor-id="42"', 'data-elementor-id="../42"', $html);
$check(null === Static_Bridge_Header_Fragments::extract_unique_elementor_header($badId), 'rejects an unsafe Elementor document ID');
$check(false === Static_Bridge_Header_Fragments::include_filename('../42', $variant), 'rejects traversal in a fragment document ID');
$check(false === Static_Bridge_Header_Fragments::include_filename('42', '../' . $variant), 'rejects traversal in a fragment variant ID');
$check(false === Static_Bridge_Header_Fragments::replace_header_with_include($html, $extracted, '43', $variant), 'refuses to replace a header under a mismatched owner ID');
$check(false === Static_Bridge_Header_Fragments::replace_header_with_include($html . '<!--#include file="/etc/passwd" -->', $extracted, '42', $variant), 'refuses to transform a route containing an SSI directive');

$commands = array('block', 'endblock', 'include', 'exec', 'echo', 'config', 'set', 'if', 'elif', 'else', 'endif', 'printenv', 'fsize', 'flastmod', 'perl');
$detected = 0;
foreach ($commands as $command) {
    if (Static_Bridge_Header_Fragments::contains_ssi_directive('<!--#' . $command . ' -->')) {
        $detected++;
    }
}
$check(count($commands) === $detected, 'rejects every Nginx SSI command spelling recognized by the guard');
$check(Static_Bridge_Header_Fragments::contains_ssi_directive('<!-- # include virtual="/x" -->'), 'rejects whitespace-obfuscated SSI syntax');
$check(!Static_Bridge_Header_Fragments::contains_ssi_directive('<main>ordinary HTML <!-- a harmless comment --></main>'), 'accepts ordinary comments and HTML');
$check(!Static_Bridge_Header_Fragments::contains_ssi_directive('<!--#include-not-a-command -->'), 'does not reject a non-command comment prefix');
$GLOBALS['sb_header_fragment_meta'][42]['_elementor_data'] = '[{"elType":"widget","widgetType":"heading","settings":{"title":"Site"}}]';
$check(Static_Bridge_Header_Fragments::document_is_context_independent('42'), 'certifies a basic static Elementor header document');
$GLOBALS['sb_header_fragment_meta'][42]['_elementor_data'] = '[{"elType":"widget","widgetType":"heading","settings":{"title":"Site","__dynamic__":{"title":"post-title"}}}]';
$check(!Static_Bridge_Header_Fragments::document_is_context_independent('42'), 'rejects Elementor dynamic tags for fragment-only refresh');
$GLOBALS['sb_header_fragment_meta'][42]['_elementor_data'] = '[{"elType":"widget","widgetType":"shortcode","settings":{"shortcode":"[contextual]"}}]';
$check(!Static_Bridge_Header_Fragments::document_is_context_independent('42'), 'rejects contextual shortcode widgets');
$check(!Static_Bridge_Header_Fragments::document_is_context_independent('../42'), 'rejects an invalid ID before reading template metadata');

echo "{$passed} header-fragment unit tests passed.\n";
