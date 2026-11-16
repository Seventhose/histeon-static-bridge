<?php
/**
 * Byte-preserving Elementor header extraction and SSI safety helpers.
 *
 * This module does not enable SSI by itself. The caller must opt in only when
 * the web-server configuration has been tested and the fragment files are
 * published inside the active release.
 */

if (!defined('ABSPATH')) {
    exit;
}

final class Static_Bridge_Header_Fragments
{
    const MAX_HTML_BYTES = 5242880;
    const INCLUDE_PREFIX = '/wp-content/static-bridge/current/fragments/headers/';

    public static function contains_ssi_directive($html)
    {
        // Apache mod_include and Nginx SSI commands; perl is the one ngx_http_perl_module adds
        // (hardening note 35 of the 3 November 2026 audit).
        return is_string($html) && 1 === preg_match(
            '~<!--\s*#\s*(?:block|endblock|include|exec|echo|config|set|if|elif|else|endif|printenv|fsize|flastmod|perl)(?=\s|-->)~i',
            $html
        );
    }

    /**
     * Read actual Elementor document attributes from HTML tags only.
     * Comments and raw-text elements are skipped, so serialized snippets do
     * not create false dependency edges or invalidate an otherwise safe index.
     */
    public static function extract_elementor_document_references($html)
    {
        if (!is_string($html) || '' === $html || strlen($html) > self::MAX_HTML_BYTES) {
            return array('complete' => false, 'dependencies' => array());
        }

        $dependencies = array();
        $cursor = 0;
        $length = strlen($html);
        while ($cursor < $length) {
            $token = self::next_html_token($html, $cursor);
            if (!is_array($token)) {
                $malformed_start = strpos($html, '<', $cursor);
                if (false === $malformed_start) {
                    break;
                }
                $next_start = strpos($html, '<', $malformed_start + 1);
                $malformed = substr($html, $malformed_start, false === $next_start ? null : $next_start - $malformed_start);
                if (preg_match('/\bdata-elementor-type\s*=/i', $malformed)) {
                    return self::extract_elementor_document_references_fallback($html);
                }
                $cursor = $malformed_start + 1;
                continue;
            }
            $previous = $cursor;
            $cursor = self::advance_past_raw_text($html, $token);
            if ($cursor <= $previous) {
                return self::extract_elementor_document_references_fallback($html);
            }
            if ('tag' !== $token['kind'] || !empty($token['closing'])) {
                continue;
            }

            $attributes = self::parse_opening_attributes($token);
            if (!is_array($attributes)) {
                if (preg_match('/\bdata-elementor-type\s*=/i', (string) $token['raw'])) {
                    return self::extract_elementor_document_references_fallback($html);
                }
                continue;
            }
            if (!array_key_exists('data-elementor-type', $attributes)) {
                continue;
            }
            $type = $attributes['data-elementor-type'];
            $id = $attributes['data-elementor-id'] ?? null;
            if (!is_string($type) || !preg_match('/\A[a-z0-9_-]{1,64}\z/iD', $type)
                || !is_string($id) || !preg_match('/\A[A-Za-z0-9_-]{1,64}\z/D', $id)) {
                return self::extract_elementor_document_references_fallback($html);
            }
            $dependencies[$id] = hash('sha256', strtolower($type) . ':' . $id);
        }

        return array('complete' => true, 'dependencies' => $dependencies);
    }

    /** Resolve ambiguous markup with independent parsers; disagreement fails closed. */
    private static function extract_elementor_document_references_fallback($html)
    {
        $wordpress = class_exists('WP_HTML_Tag_Processor')
            ? self::extract_elementor_document_references_wordpress($html)
            : null;
        $dom = class_exists('DOMDocument') && class_exists('DOMXPath')
            ? self::extract_elementor_document_references_dom($html)
            : null;

        if (is_array($wordpress) && is_array($dom)) {
            $wordpress_dependencies = (array) ($wordpress['dependencies'] ?? array());
            $dom_dependencies = (array) ($dom['dependencies'] ?? array());
            ksort($wordpress_dependencies, SORT_STRING);
            ksort($dom_dependencies, SORT_STRING);
            return !empty($wordpress['complete']) && !empty($dom['complete'])
                && $wordpress_dependencies === $dom_dependencies
                ? array('complete' => true, 'dependencies' => $wordpress_dependencies)
                : array('complete' => false, 'dependencies' => array());
        }
        if (is_array($wordpress)) {
            return $wordpress;
        }
        if (is_array($dom)) {
            return $dom;
        }
        return array('complete' => false, 'dependencies' => array());
    }

    /** Parse ambiguous markup with WordPress core's HTML5-aware tag processor. */
    private static function extract_elementor_document_references_wordpress($html)
    {
        try {
            $processor = new WP_HTML_Tag_Processor($html);
            $dependencies = array();
            while ($processor->next_tag()) {
                $type = $processor->get_attribute('data-elementor-type');
                if (null === $type) {
                    continue;
                }
                $id = $processor->get_attribute('data-elementor-id');
                if (!is_string($type) || !preg_match('/\A[a-z0-9_-]{1,64}\z/iD', $type)
                    || !is_string($id) || !preg_match('/\A[A-Za-z0-9_-]{1,64}\z/D', $id)) {
                    return array('complete' => false, 'dependencies' => array());
                }
                $dependencies[$id] = hash('sha256', strtolower($type) . ':' . $id);
            }
            return array('complete' => true, 'dependencies' => $dependencies);
        } catch (Throwable $exception) {
            return array('complete' => false, 'dependencies' => array());
        }
    }

    /** Resolve ambiguous malformed HTML through libxml's HTML tree builder. */
    private static function extract_elementor_document_references_dom($html)
    {
        if (!class_exists('DOMDocument') || !class_exists('DOMXPath')
            || !defined('LIBXML_NONET') || !is_string($html) || strlen($html) > self::MAX_HTML_BYTES) {
            return array('complete' => false, 'dependencies' => array());
        }
        try {
            $document = new DOMDocument();
            $loaded = @$document->loadHTML($html, LIBXML_NONET);
            if (!$loaded) {
                return array('complete' => false, 'dependencies' => array());
            }
            $xpath = new DOMXPath($document);
            $nodes = $xpath->query('//*[@data-elementor-type]');
            if (!$nodes instanceof DOMNodeList) {
                return array('complete' => false, 'dependencies' => array());
            }
            $dependencies = array();
            foreach ($nodes as $node) {
                if (!$node instanceof DOMElement) {
                    return array('complete' => false, 'dependencies' => array());
                }
                $type = $node->getAttribute('data-elementor-type');
                $id = $node->getAttribute('data-elementor-id');
                if (!preg_match('/\A[a-z0-9_-]{1,64}\z/iD', $type)
                    || !preg_match('/\A[A-Za-z0-9_-]{1,64}\z/D', $id)) {
                    return array('complete' => false, 'dependencies' => array());
                }
                $dependencies[$id] = hash('sha256', strtolower($type) . ':' . $id);
            }
            return array('complete' => true, 'dependencies' => $dependencies);
        } catch (Throwable $exception) {
            return array('complete' => false, 'dependencies' => array());
        }
    }

    /**
     * Locate one Elementor Theme Builder header without reserializing HTML.
     *
     * Returns source-byte offsets so replacing the wrapper preserves every
     * byte outside the extracted header. Ambiguous or malformed markup fails
     * closed.
     */
    public static function extract_unique_elementor_header($html)
    {
        if (!is_string($html) || '' === $html || strlen($html) > self::MAX_HTML_BYTES) {
            return null;
        }

        $openings = array();
        $cursor = 0;
        $length = strlen($html);
        while ($token = self::next_html_token($html, $cursor)) {
            $cursor = self::advance_past_raw_text($html, $token);
            if (self::has_elementor_header_class($token)) {
                $openings[] = $token;
            }
            if ($cursor >= $length) {
                break;
            }
        }
        if (1 !== count($openings)) {
            return null;
        }

        $opening = $openings[0];
        $end = self::find_element_end($html, $opening);
        if (null === $end || $end <= $opening['start']) {
            return null;
        }
        $markup = substr($html, $opening['start'], $end - $opening['start']);
        if (1 !== substr_count($html, $markup)) {
            return null;
        }
        $owner_type = self::read_opening_attribute($opening, 'data-elementor-type');
        $owner_id = self::read_opening_attribute($opening, 'data-elementor-id');
        if ('header' !== strtolower((string) $owner_type)
            || !is_string($owner_id)
            || !preg_match('/\A[A-Za-z0-9_-]{1,64}\z/D', $owner_id)) {
            return null;
        }

        return array(
            'start' => $opening['start'],
            'end' => $end,
            'markup' => $markup,
            'owner_type' => $owner_type,
            'owner_id' => $owner_id,
            'markup_hash' => hash('sha256', $markup),
        );
    }

    public static function include_filename($document_id, $variant_id)
    {
        $document_id = (string) $document_id;
        $variant_id = (string) $variant_id;
        if (!preg_match('/\A[A-Za-z0-9_-]{1,64}\z/D', $document_id)
            || !preg_match('/\A[a-f0-9]{64}\z/D', $variant_id)) {
            return false;
        }
        return 'elementor-' . $document_id . '-' . $variant_id . '.html';
    }

    public static function include_uri($document_id, $variant_id)
    {
        $filename = self::include_filename($document_id, $variant_id);
        return is_string($filename)
            ? self::INCLUDE_PREFIX . $document_id . '/current/' . $filename
            : false;
    }

    /**
     * SSI refreshes only a fragment, so reject known current-page/user-specific
     * Elementor data and widgets. Unknown or unreadable data is not certified.
     */
    public static function document_is_context_independent($document_id)
    {
        if (!preg_match('/\A[1-9][0-9]{0,19}\z/D', (string) $document_id) || !function_exists('get_post_meta')) {
            return false;
        }
        $raw = get_post_meta((int) $document_id, '_elementor_data', true);
        if (is_array($raw)) {
            $document = $raw;
        } elseif (is_string($raw) && strlen($raw) <= 1048576) {
            $document = json_decode($raw, true);
        } else {
            return false;
        }
        if (!is_array($document)) {
            return false;
        }
        return !self::contains_contextual_widget($document);
    }

    public static function replace_header_with_include($html, $header, $document_id, $variant_id)
    {
        if (!is_string($html) || !is_array($header) || self::contains_ssi_directive($html)
            || !isset($header['start'], $header['end'], $header['markup'], $header['owner_id'])
            || !is_int($header['start']) || !is_int($header['end'])
            || $header['start'] < 0 || $header['end'] <= $header['start']
            || $header['end'] > strlen($html) || (string) $header['owner_id'] !== (string) $document_id
            || substr($html, $header['start'], $header['end'] - $header['start']) !== $header['markup']) {
            return false;
        }
        $uri = self::include_uri($document_id, $variant_id);
        if (!is_string($uri)) {
            return false;
        }
        $directive = '<!--#include virtual="' . $uri . '" -->';
        $transformed = substr($html, 0, $header['start']) . $directive . substr($html, $header['end']);
        return self::contains_ssi_directive(substr($html, 0, $header['start']))
            ? false
            : $transformed;
    }

    private static function next_html_token($html, $offset)
    {
        $length = strlen($html);
        $start = strpos($html, '<', $offset);
        if (false === $start) {
            return null;
        }

        if (0 === substr_compare($html, '<!--', $start, 4)) {
            $comment_end = strpos($html, '-->', $start + 4);
            return array('kind' => 'comment', 'start' => $start, 'end' => false === $comment_end ? $length : $comment_end + 3);
        }
        if (0 === substr_compare($html, '<![CDATA[', $start, 9)) {
            $cdata_end = strpos($html, ']]>', $start + 9);
            return array('kind' => 'declaration', 'start' => $start, 'end' => false === $cdata_end ? $length : $cdata_end + 3);
        }
        if (preg_match('/\A<(!|\?)/', substr($html, $start), $declaration)) {
            $quote = '';
            for ($cursor = $start + strlen($declaration[0]); $cursor < $length; $cursor++) {
                $character = $html[$cursor];
                if ('' !== $quote) {
                    if ($character === $quote) {
                        $quote = '';
                    }
                    continue;
                }
                if ('"' === $character || "'" === $character) {
                    $quote = $character;
                } elseif ('>' === $character) {
                    return array('kind' => 'declaration', 'start' => $start, 'end' => $cursor + 1);
                }
            }
            return array('kind' => 'declaration', 'start' => $start, 'end' => $length);
        }
        if (!preg_match('/\G<(\/?)\s*([A-Za-z][A-Za-z0-9:-]*)\b/', $html, $match, 0, $start)) {
            return array('kind' => 'text', 'start' => $start, 'end' => $start + 1);
        }

        $quote = '';
        $tag_end = null;
        for ($cursor = $start + strlen($match[0]); $cursor < $length; $cursor++) {
            $character = $html[$cursor];
            if ('' !== $quote) {
                if ($character === $quote) {
                    $quote = '';
                }
                continue;
            }
            if ('"' === $character || "'" === $character) {
                $quote = $character;
            } elseif ('>' === $character) {
                $tag_end = $cursor + 1;
                break;
            }
        }
        if (null === $tag_end) {
            return null;
        }

        $raw = substr($html, $start, $tag_end - $start);
        return array(
            'kind' => 'tag',
            'tag' => strtolower($match[2]),
            'closing' => '/' === $match[1],
            'self_closing' => str_ends_with(rtrim(substr($raw, 0, -1)), '/'),
            'start' => $start,
            'end' => $tag_end,
            'raw' => $raw,
        );
    }

    private static function advance_past_raw_text($html, $token)
    {
        if ('tag' !== $token['kind'] || $token['closing']
            || !in_array($token['tag'], array('script', 'style', 'textarea', 'title'), true)) {
            return $token['end'];
        }
        $needle = '</' . $token['tag'];
        $cursor = $token['end'];
        while (false !== ($close = stripos($html, $needle, $cursor))) {
            $after_name = $close + strlen($needle);
            if ($after_name >= strlen($html) || preg_match('/[\s>\/]/', $html[$after_name])) {
                return $close;
            }
            $cursor = $after_name;
        }
        return strlen($html);
    }

    private static function has_elementor_header_class($token)
    {
        if ('tag' !== $token['kind'] || $token['closing']) {
            return false;
        }
        $inside = substr($token['raw'], 1, -1);
        if (!preg_match('/\A[A-Za-z][A-Za-z0-9:-]*(.*)\z/s', $inside, $parts)
            || !preg_match_all('/(?:^|\s)class\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s>]+))/i', $parts[1], $attributes, PREG_SET_ORDER)
            || 1 !== count($attributes)) {
            return false;
        }
        $class_value = '';
        foreach (array(1, 2, 3) as $capture) {
            if (isset($attributes[0][$capture]) && '' !== $attributes[0][$capture]) {
                $class_value = $attributes[0][$capture];
                break;
            }
        }
        return (bool) preg_match('/(?:\A|\s)elementor-location-header(?:\s|\z)/', $class_value);
    }

    private static function read_opening_attribute($token, $attribute_name)
    {
        if ('tag' !== $token['kind'] || $token['closing']
            || !preg_match('/\A[A-Za-z][A-Za-z0-9:-]*(.*)\z/s', substr($token['raw'], 1, -1), $parts)) {
            return null;
        }
        $pattern = '/(?:^|\s)' . preg_quote($attribute_name, '/')
            . '\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s>]+))/i';
        if (!preg_match_all($pattern, $parts[1], $attributes, PREG_SET_ORDER) || 1 !== count($attributes)) {
            return null;
        }
        foreach (array(1, 2, 3) as $capture) {
            if (isset($attributes[0][$capture]) && '' !== $attributes[0][$capture]) {
                return $attributes[0][$capture];
            }
        }
        return '';
    }

    /** Read Elementor dependency attributes without matching text inside values. */
    private static function parse_opening_attributes($token)
    {
        if ('tag' !== ($token['kind'] ?? '') || !isset($token['raw'])
            || !preg_match('/\A[A-Za-z][A-Za-z0-9:-]*/', substr($token['raw'], 1, -1), $tag_name)) {
            return false;
        }
        $source = substr(substr($token['raw'], 1, -1), strlen($tag_name[0]));
        $attributes = array();
        $cursor = 0;
        $length = strlen($source);
        while ($cursor < $length) {
            while ($cursor < $length && ctype_space($source[$cursor])) {
                $cursor++;
            }
            if ($cursor >= $length) {
                break;
            }
            if ('/' === $source[$cursor]) {
                $cursor++;
                while ($cursor < $length && ctype_space($source[$cursor])) {
                    $cursor++;
                }
                if ($cursor === $length) {
                    break;
                }
                return false;
            }

            $name_start = $cursor;
            while ($cursor < $length && preg_match('/[A-Za-z0-9:._-]/', $source[$cursor])) {
                $cursor++;
            }
            if ($cursor === $name_start || !preg_match('/[A-Za-z_:]/', $source[$name_start])) {
                while ($cursor < $length && !ctype_space($source[$cursor])) {
                    $cursor++;
                }
                continue;
            }
            $name = strtolower(substr($source, $name_start, $cursor - $name_start));
            while ($cursor < $length && ctype_space($source[$cursor])) {
                $cursor++;
            }

            $value = '';
            $quoted_value = false;
            if ($cursor < $length && '=' === $source[$cursor]) {
                $cursor++;
                while ($cursor < $length && ctype_space($source[$cursor])) {
                    $cursor++;
                }
                if ($cursor >= $length) {
                    return false;
                }
                if ('"' === $source[$cursor] || "'" === $source[$cursor]) {
                    $quoted_value = true;
                    $quote = $source[$cursor++];
                    $value_start = $cursor;
                    $value_end = strpos($source, $quote, $cursor);
                    if (false === $value_end) {
                        return false;
                    }
                    $value = substr($source, $value_start, $value_end - $value_start);
                    $cursor = $value_end + 1;
                } else {
                    $value_start = $cursor;
                    while ($cursor < $length && !ctype_space($source[$cursor])) {
                        $cursor++;
                    }
                    if ($cursor === $value_start) {
                        return false;
                    }
                    $value = substr($source, $value_start, $cursor - $value_start);
                }
            }
            if (in_array($name, array('data-elementor-type', 'data-elementor-id'), true)) {
                if (!$quoted_value || array_key_exists($name, $attributes)) {
                    return false;
                }
                $attributes[$name] = $value;
            }
        }

        return $attributes;
    }

    private static function find_element_end($html, $opening)
    {
        $void_tags = array('area', 'base', 'br', 'col', 'embed', 'hr', 'img', 'input', 'link', 'meta', 'param', 'source', 'track', 'wbr');
        if ($opening['self_closing'] || in_array($opening['tag'], $void_tags, true)) {
            return $opening['end'];
        }
        $depth = 1;
        $cursor = $opening['end'];
        while ($token = self::next_html_token($html, $cursor)) {
            $cursor = self::advance_past_raw_text($html, $token);
            if ('tag' !== $token['kind'] || $opening['tag'] !== $token['tag']) {
                continue;
            }
            if ($token['closing']) {
                $depth--;
                if (0 === $depth) {
                    return $token['end'];
                }
            } elseif (!$token['self_closing'] && !in_array($token['tag'], $void_tags, true)) {
                $depth++;
            }
        }
        return null;
    }

    private static function contains_contextual_widget($value)
    {
        if (!is_array($value)) {
            return false;
        }
        $contextual_widgets = array(
            'shortcode', 'form', 'posts', 'archive-posts', 'post-title', 'post-content', 'post-info',
            'author-box', 'featured-image', 'search', 'login', 'woocommerce-products', 'woocommerce-cart',
        );
        foreach ($value as $key => $child) {
            if ('__dynamic__' === (string) $key
                && ((is_array($child) && !empty($child)) || (is_scalar($child) && '' !== (string) $child))) {
                return true;
            }
            if (in_array((string) $key, array('widgetType', 'widget_type'), true)
                && is_string($child) && in_array(strtolower($child), $contextual_widgets, true)) {
                return true;
            }
            if (self::contains_contextual_widget($child)) {
                return true;
            }
        }
        return false;
    }
}

/** Persistent representative-route map and retry queue for shared headers. */
final class Static_Bridge_Header_Fragment_Index
{
    const TABLE_SUFFIX = 'static_bridge_v2_fragments';
    const SCHEMA_VERSION = 1;
    private static $verified_tables = array();
    private $wpdb;
    private $table;

    public function __construct($wpdb = null)
    {
        if (null === $wpdb && isset($GLOBALS['wpdb'])) {
            $wpdb = $GLOBALS['wpdb'];
        }
        $this->wpdb = is_object($wpdb) ? $wpdb : null;
        $prefix = $this->wpdb && isset($this->wpdb->prefix) ? (string) $this->wpdb->prefix : '';
        $this->table = preg_match('/\A[A-Za-z0-9_]+\z/', $prefix) ? $prefix . self::TABLE_SUFFIX : '';
    }

    public function install()
    {
        if (!$this->wpdb || '' === $this->table || !method_exists($this->wpdb, 'get_charset_collate')
            || !method_exists($this->wpdb, 'prepare') || !method_exists($this->wpdb, 'get_var')) {
            return false;
        }
        $cache_key = hash('sha256', self::SCHEMA_VERSION . "\0" . $this->table);
        if (!empty(self::$verified_tables[$cache_key])) {
            return true;
        }
        if (!function_exists('dbDelta')) {
            $upgrade_file = ABSPATH . 'wp-admin/includes/upgrade.php';
            if (!is_file($upgrade_file)) {
                return false;
            }
            require_once $upgrade_file;
        }
        if (!function_exists('dbDelta')) {
            return false;
        }
        $collate = $this->wpdb->get_charset_collate();
        dbDelta("CREATE TABLE {$this->table} (
            release_key char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            dependency_id varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            variant_id char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            markup_hash char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            file_name varchar(160) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            representative_path text NOT NULL,
            queue_status varchar(12) NOT NULL DEFAULT '',
            queued_at datetime NOT NULL,
            available_at datetime NOT NULL,
            token char(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            attempts int(10) unsigned NOT NULL DEFAULT 0,
            last_error text NOT NULL,
            PRIMARY KEY  (release_key,dependency_id,variant_id),
            KEY current_markup (release_key,dependency_id,markup_hash),
            KEY queue_lookup (release_key,queue_status,queued_at)
        ) ENGINE=InnoDB {$collate};");

        $like = method_exists($this->wpdb, 'esc_like') ? $this->wpdb->esc_like($this->table) : addcslashes($this->table, '_%\\');
        if ($this->table !== $this->wpdb->get_var($this->wpdb->prepare('SHOW TABLES LIKE %s', $like))) {
            return false;
        }
        $engine = $this->wpdb->get_var($this->wpdb->prepare(
            'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s',
            $this->table
        ));
        if ('InnoDB' !== $engine) {
            return false;
        }
        self::$verified_tables[$cache_key] = true;
        return true;
    }

    public function begin_release($release_id)
    {
        if (!$this->valid_release_id($release_id) || !$this->install()) {
            return false;
        }
        return false !== $this->wpdb->query($this->wpdb->prepare(
            "DELETE FROM {$this->table} WHERE release_key=%s",
            $this->release_key($release_id)
        ));
    }

    /** Register a rendered output variant and retain one route as its context. */
    public function register_variant($release_id, $document_id, $markup, $route_path)
    {
        if (!$this->valid_release_id($release_id) || !$this->valid_document_id($document_id)
            || !is_string($markup) || '' === $markup || strlen($markup) > Static_Bridge_Header_Fragments::MAX_HTML_BYTES
            || Static_Bridge_Header_Fragments::contains_ssi_directive($markup)
            || !$this->valid_route_path($route_path) || !$this->install()) {
            return false;
        }
        $markup_hash = hash('sha256', $markup);
        $release_key = $this->release_key($release_id);
        $existing = $this->wpdb->get_row($this->wpdb->prepare(
            "SELECT variant_id,file_name,markup_hash FROM {$this->table}
             WHERE release_key=%s AND dependency_id=%s AND markup_hash=%s
             ORDER BY variant_id ASC LIMIT 1",
            $release_key,
            (string) $document_id,
            $markup_hash
        ), defined('ARRAY_A') ? ARRAY_A : 'ARRAY_A');
        if (is_array($existing) && !empty($existing['variant_id']) && !empty($existing['file_name'])) {
            return $this->valid_variant_row($existing, $document_id)
                && isset($existing['markup_hash'])
                && hash_equals($markup_hash, (string) $existing['markup_hash'])
                ? $existing
                : false;
        }
        if (!empty($this->wpdb->last_error)) {
            return false;
        }

        $variant_id = $markup_hash;
        $file_name = Static_Bridge_Header_Fragments::include_filename($document_id, $variant_id);
        if (!is_string($file_name)) {
            return false;
        }
        $inserted = $this->wpdb->query($this->wpdb->prepare(
            "INSERT INTO {$this->table}
                (release_key,dependency_id,variant_id,markup_hash,file_name,representative_path,queue_status,queued_at,available_at,token,attempts,last_error)
             VALUES (%s,%s,%s,%s,%s,%s,'',UTC_TIMESTAMP(),UTC_TIMESTAMP(),%s,0,'')
             ON DUPLICATE KEY UPDATE markup_hash=VALUES(markup_hash),file_name=VALUES(file_name),representative_path=IF(representative_path='',VALUES(representative_path),representative_path)",
            $release_key,
            (string) $document_id,
            $variant_id,
            $markup_hash,
            $file_name,
            $route_path,
            wp_generate_uuid4()
        ));
        return false !== $inserted
            ? array('variant_id' => $variant_id, 'file_name' => $file_name, 'markup_hash' => $markup_hash)
            : false;
    }

    public function enqueue_document($release_id, $document_id)
    {
        if (!$this->valid_release_id($release_id) || !$this->valid_document_id($document_id) || !$this->install()) {
            return false;
        }
        $changed = $this->wpdb->query($this->wpdb->prepare(
            "UPDATE {$this->table} SET queue_status='pending',queued_at=UTC_TIMESTAMP(),available_at=UTC_TIMESTAMP(),token=%s,attempts=0,last_error=''
             WHERE release_key=%s AND dependency_id=%s",
            wp_generate_uuid4(),
            $this->release_key($release_id),
            (string) $document_id
        ));
        return false === $changed || !empty($this->wpdb->last_error) ? false : (int) $changed;
    }

    /** Claim every due variant of one document as a single refresh batch. */
    public function claim_document_variants($release_id, $item)
    {
        if (!$this->valid_queue_item($release_id, $item) || !$this->install()) {
            return false;
        }
        $release_key = $this->release_key($release_id);
        $document_id = (string) $item['dependency_id'];
        if (false === $this->wpdb->query('START TRANSACTION')) {
            return false;
        }
        $rows = $this->wpdb->get_results($this->wpdb->prepare(
            "SELECT dependency_id,variant_id,markup_hash,file_name,representative_path,queue_status,queued_at,available_at,token,attempts,last_error,
                    (queue_status='pending' AND available_at<=UTC_TIMESTAMP()) AS sb_due,
                    (queue_status='running' AND queued_at<DATE_SUB(UTC_TIMESTAMP(),INTERVAL 10 MINUTE)) AS sb_stale
             FROM {$this->table} WHERE release_key=%s AND dependency_id=%s ORDER BY variant_id ASC FOR UPDATE",
            $release_key,
            $document_id
        ), defined('ARRAY_A') ? ARRAY_A : 'ARRAY_A');
        if (!is_array($rows) || empty($rows) || !empty($this->wpdb->last_error)) {
            $this->wpdb->query('ROLLBACK');
            return false;
        }

        $claimable = array();
        $found_current = false;
        foreach ($rows as $row) {
            if (!is_array($row) || !$this->valid_variant_row($row, $document_id)
                || !$this->valid_route_path((string) ($row['representative_path'] ?? ''))) {
                $this->wpdb->query('ROLLBACK');
                return false;
            }
            $status = (string) ($row['queue_status'] ?? '');
            $token = (string) ($row['token'] ?? '');
            $is_current = (string) $row['variant_id'] === (string) $item['variant_id'];
            if ($is_current && 'running' === $status && hash_equals((string) $item['token'], $token)) {
                $found_current = true;
                $claimable[] = array($row, false);
                continue;
            }
            if ('pending' === $status && !empty($row['sb_due'])) {
                $claimable[] = array($row, true);
                continue;
            }
            if ('running' === $status && !empty($row['sb_stale'])) {
                $claimable[] = array($row, true);
                continue;
            }
            if ('' !== $status) {
                $this->wpdb->query('ROLLBACK');
                return false;
            }
        }
        if (!$found_current || empty($claimable)) {
            $this->wpdb->query('ROLLBACK');
            return false;
        }

        $claimed = array();
        foreach ($claimable as $claim) {
            list($row, $replace_token) = $claim;
            if ($replace_token) {
                $token = function_exists('wp_generate_uuid4') ? wp_generate_uuid4() : bin2hex(random_bytes(16));
                $changed = $this->wpdb->query($this->wpdb->prepare(
                    "UPDATE {$this->table} SET queue_status='running',queued_at=UTC_TIMESTAMP(),token=%s
                     WHERE release_key=%s AND dependency_id=%s AND variant_id=%s
                     AND ((queue_status='pending' AND available_at<=UTC_TIMESTAMP()) OR (queue_status='running' AND queued_at<DATE_SUB(UTC_TIMESTAMP(),INTERVAL 10 MINUTE)))",
                    $token,
                    $release_key,
                    $document_id,
                    (string) $row['variant_id']
                ));
                if (1 !== (int) $changed) {
                    $this->wpdb->query('ROLLBACK');
                    return false;
                }
                $row['token'] = $token;
                $row['queue_status'] = 'running';
            }
            $row['url'] = home_url((string) $row['representative_path']);
            $row['_release_id'] = (string) $release_id;
            $row['attempts'] = absint($row['attempts'] ?? 0);
            $claimed[] = $row;
        }
        if (false === $this->wpdb->query('COMMIT')) {
            $this->wpdb->query('ROLLBACK');
            return false;
        }
        return $claimed;
    }

    public function next_pending($release_id)
    {
        if (!$this->valid_release_id($release_id) || !$this->install()) {
            return false;
        }
        $release_key = $this->release_key($release_id);
        $row = $this->wpdb->get_row($this->wpdb->prepare(
            "SELECT dependency_id,variant_id,markup_hash,file_name,representative_path,attempts,last_error
             FROM {$this->table} WHERE release_key=%s
             AND ((queue_status='pending' AND available_at<=UTC_TIMESTAMP()) OR (queue_status='running' AND queued_at<DATE_SUB(UTC_TIMESTAMP(),INTERVAL 10 MINUTE)))
             ORDER BY queued_at ASC,dependency_id ASC,variant_id ASC LIMIT 1",
            $release_key
        ), defined('ARRAY_A') ? ARRAY_A : 'ARRAY_A');
        if (null === $row) {
            return empty($this->wpdb->last_error) ? null : false;
        }
        if (!is_array($row) || !$this->valid_variant_row($row, (string) ($row['dependency_id'] ?? ''))
            || !$this->valid_route_path((string) ($row['representative_path'] ?? '')) || !function_exists('home_url')) {
            return false;
        }
        $token = wp_generate_uuid4();
        $changed = $this->wpdb->query($this->wpdb->prepare(
            "UPDATE {$this->table} SET queue_status='running',queued_at=UTC_TIMESTAMP(),token=%s
             WHERE release_key=%s AND dependency_id=%s AND variant_id=%s
             AND ((queue_status='pending' AND available_at<=UTC_TIMESTAMP()) OR (queue_status='running' AND queued_at<DATE_SUB(UTC_TIMESTAMP(),INTERVAL 10 MINUTE)))",
            $token,
            $release_key,
            (string) $row['dependency_id'],
            (string) $row['variant_id']
        ));
        if (1 !== (int) $changed) {
            return null;
        }
        $row['url'] = home_url((string) $row['representative_path']);
        $row['token'] = $token;
        $row['attempts'] = absint($row['attempts']);
        $row['last_error'] = sanitize_text_field((string) $row['last_error']);
        $row['_release_id'] = (string) $release_id;
        return $row;
    }

    public function complete($release_id, $item, $markup_hash)
    {
        if (!$this->valid_queue_item($release_id, $item) || !preg_match('/\A[a-f0-9]{64}\z/D', (string) $markup_hash)) {
            return false;
        }
        return 1 === (int) $this->wpdb->query($this->wpdb->prepare(
            "UPDATE {$this->table} SET markup_hash=%s,queue_status='',queued_at=UTC_TIMESTAMP(),available_at=UTC_TIMESTAMP(),attempts=0,last_error=''
             WHERE release_key=%s AND dependency_id=%s AND variant_id=%s AND queue_status='running' AND token=%s",
            (string) $markup_hash,
            $this->release_key($release_id),
            (string) $item['dependency_id'],
            (string) $item['variant_id'],
            (string) $item['token']
        ));
    }

    public function retry($release_id, $item, $message, $delay = 15)
    {
        if (!$this->valid_queue_item($release_id, $item)) {
            return false;
        }
        return 1 === (int) $this->wpdb->query($this->wpdb->prepare(
            "UPDATE {$this->table} SET queue_status='pending',queued_at=UTC_TIMESTAMP(),available_at=DATE_ADD(UTC_TIMESTAMP(),INTERVAL %d SECOND),attempts=attempts+1,last_error=%s
             WHERE release_key=%s AND dependency_id=%s AND variant_id=%s AND queue_status='running' AND token=%s",
            max(0, min(3600, (int) $delay)),
            sanitize_text_field((string) $message),
            $this->release_key($release_id),
            (string) $item['dependency_id'],
            (string) $item['variant_id'],
            (string) $item['token']
        ));
    }

    public function pending_count($release_id)
    {
        if (!$this->valid_release_id($release_id) || !$this->install()) {
            return false;
        }
        $count = $this->wpdb->get_var($this->wpdb->prepare(
            "SELECT COUNT(*) FROM {$this->table} WHERE release_key=%s AND queue_status IN ('pending','running')",
            $this->release_key($release_id)
        ));
        return is_numeric($count) && empty($this->wpdb->last_error) ? (int) $count : false;
    }

    public function documents($release_id)
    {
        if (!$this->valid_release_id($release_id) || !$this->install()) {
            return false;
        }
        $rows = $this->wpdb->get_col($this->wpdb->prepare(
            "SELECT DISTINCT dependency_id FROM {$this->table} WHERE release_key=%s ORDER BY dependency_id ASC",
            $this->release_key($release_id)
        ));
        if (!is_array($rows) || !empty($this->wpdb->last_error)) {
            return false;
        }
        foreach ($rows as $document_id) {
            if (!$this->valid_document_id((string) $document_id)) {
                return false;
            }
        }
        return array_values(array_map('strval', $rows));
    }

    public function next_pending_delay($release_id)
    {
        if (!$this->valid_release_id($release_id) || !$this->install()) {
            return false;
        }
        $seconds = $this->wpdb->get_var($this->wpdb->prepare(
            "SELECT MIN(GREATEST(0,TIMESTAMPDIFF(SECOND,UTC_TIMESTAMP(),CASE WHEN queue_status='pending' THEN available_at ELSE DATE_ADD(queued_at,INTERVAL 10 MINUTE) END)))
             FROM {$this->table} WHERE release_key=%s AND queue_status IN ('pending','running')",
            $this->release_key($release_id)
        ));
        return is_numeric($seconds) && empty($this->wpdb->last_error) ? max(0, (int) $seconds) : false;
    }

    public function delete_release($release_id)
    {
        if (!$this->valid_release_id($release_id) || !$this->install()) {
            return false;
        }
        return false !== $this->wpdb->query($this->wpdb->prepare(
            "DELETE FROM {$this->table} WHERE release_key=%s",
            $this->release_key($release_id)
        ));
    }

    /** Release keys with fragment rows; false on any query error (fail closed). */
    public function referenced_release_keys()
    {
        if (!$this->wpdb || '' === $this->table || !method_exists($this->wpdb, 'get_col')
            || !method_exists($this->wpdb, 'get_var') || !method_exists($this->wpdb, 'prepare')) {
            return false;
        }
        $like = method_exists($this->wpdb, 'esc_like') ? $this->wpdb->esc_like($this->table) : addcslashes($this->table, '_%\\');
        $exists = $this->wpdb->get_var($this->wpdb->prepare('SHOW TABLES LIKE %s', $like));
        if (!empty($this->wpdb->last_error)) {
            return false;
        }
        if ($this->table !== $exists) {
            return array();
        }
        $column = $this->wpdb->get_col("SELECT DISTINCT release_key FROM {$this->table}");
        if (!is_array($column) || !empty($this->wpdb->last_error)) {
            return false;
        }
        foreach ($column as $key) {
            if (!is_string($key) || !preg_match('/\A[a-f0-9]{64}\z/D', $key)) {
                return false;
            }
        }
        return array_values(array_unique($column));
    }

    public function purge_except($release_id)
    {
        if (!$this->valid_release_id($release_id) || !$this->install()) {
            return false;
        }
        return false !== $this->wpdb->query($this->wpdb->prepare(
            "DELETE FROM {$this->table} WHERE release_key<>%s",
            $this->release_key($release_id)
        ));
    }

    private function valid_queue_item($release_id, $item)
    {
        return $this->valid_release_id($release_id) && is_array($item)
            && $this->valid_document_id((string) ($item['dependency_id'] ?? ''))
            && preg_match('/\A[a-f0-9]{64}\z/D', (string) ($item['variant_id'] ?? ''))
            && preg_match('/\A[A-Za-z0-9_-]{1,36}\z/D', (string) ($item['token'] ?? ''));
    }

    private function valid_variant_row($row, $document_id)
    {
        return is_array($row) && $this->valid_document_id((string) $document_id)
            && preg_match('/\A[a-f0-9]{64}\z/D', (string) ($row['variant_id'] ?? ''))
            && Static_Bridge_Header_Fragments::include_filename((string) $document_id, (string) $row['variant_id']) === (string) ($row['file_name'] ?? '');
    }

    private function valid_release_id($release_id)
    {
        return is_string($release_id) && (bool) preg_match('/\A[A-Za-z0-9_-]{1,100}\z/D', $release_id);
    }

    private function valid_document_id($document_id)
    {
        return is_string($document_id) && (bool) preg_match('/\A[A-Za-z0-9_-]{1,64}\z/D', $document_id);
    }

    private function valid_route_path($path)
    {
        return is_string($path) && strlen($path) <= 2048 && '/' === substr($path, 0, 1)
            && false === strpos($path, "\0") && false === strpos($path, '..')
            && false === strpos($path, '?') && false === strpos($path, '#');
    }

    private function release_key($release_id)
    {
        return hash('sha256', (string) $release_id);
    }
}
