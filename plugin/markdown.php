<?php
/**
 * The Markdown version of a page (2.5.0): HTML in, Markdown out, with no library and no WordPress function.
 *
 * Loaded by static-bridge.php only when it answers a ?format=markdown request. The plugin decides which page may be
 * served and passes its URL and the site origin; this class only converts. With ext-dom the HTML is read by
 * DOMDocument, without it by a small tokenizer of its own: both build the same tree, so the text does not depend on
 * how PHP was compiled.
 */

if (!defined('ABSPATH')) {
    exit;
}

final class Static_Bridge_Markdown
{
    /** The HTML read at most: beyond it the page is cut and the text ends with «[…]». */
    const MAX_HTML_BYTES = 2097152;
    /** The Markdown written at most, front matter excluded. */
    const MAX_MARKDOWN_BYTES = 1048576;
    /** The whole answer at most: front matter, text and what the filter static_bridge_markdown adds. */
    const MAX_DOCUMENT_BYTES = 2097152;
    /** Elements nested deeper than this become plain text. */
    const MAX_DEPTH = 100;
    /** Elements and text runs read at most. */
    const MAX_NODES = 200000;
    /** Seconds the conversion may take. */
    const TIME_BUDGET = 3.0;
    /** The open elements the tokenizer keeps at most, as libxml does. */
    const MAX_OPEN_ELEMENTS = 256;

    /** Elements left out with everything they hold, wherever they are: code, forms, embeds, navigation, the head. */
    const SKIPPED = array(
        'script' => 1, 'style' => 1, 'noscript' => 1, 'template' => 1, 'svg' => 1, 'math' => 1, 'iframe' => 1,
        'frame' => 1, 'frameset' => 1, 'noframes' => 1, 'noembed' => 1, 'object' => 1, 'embed' => 1, 'applet' => 1,
        'canvas' => 1, 'video' => 1, 'audio' => 1, 'form' => 1, 'button' => 1, 'input' => 1, 'select' => 1,
        'option' => 1, 'optgroup' => 1, 'textarea' => 1, 'datalist' => 1, 'dialog' => 1, 'head' => 1, 'title' => 1,
        'meta' => 1, 'link' => 1, 'base' => 1, 'map' => 1, 'area' => 1, 'param' => 1, 'source' => 1, 'track' => 1,
        'nav' => 1, 'aside' => 1, 'slot' => 1, 'xmp' => 1, 'plaintext' => 1,
    );
    /** ARIA roles of navigation, site header and footer, sidebars, search boxes and dialogs. */
    const SKIPPED_ROLES = array(
        'navigation' => 1, 'banner' => 1, 'contentinfo' => 1, 'complementary' => 1, 'search' => 1, 'dialog' => 1,
        'alertdialog' => 1, 'menu' => 1, 'menubar' => 1, 'toolbar' => 1,
    );
    const VOID = array(
        'area' => 1, 'base' => 1, 'br' => 1, 'col' => 1, 'embed' => 1, 'hr' => 1, 'img' => 1, 'input' => 1, 'keygen' => 1,
        'link' => 1, 'meta' => 1, 'param' => 1, 'source' => 1, 'track' => 1, 'wbr' => 1,
    );
    /** Elements whose content is text up to their closing tag. */
    const RAW_TEXT = array(
        'script' => 1, 'style' => 1, 'textarea' => 1, 'title' => 1, 'xmp' => 1, 'iframe' => 1, 'noembed' => 1,
        'noframes' => 1, 'noscript' => 1, 'plaintext' => 1,
    );
    /** Phrasing elements: they stay in the line of text around them. */
    const INLINE = array(
        'a' => 1, 'abbr' => 1, 'acronym' => 1, 'b' => 1, 'bdi' => 1, 'bdo' => 1, 'big' => 1, 'br' => 1, 'cite' => 1,
        'code' => 1, 'data' => 1, 'del' => 1, 'dfn' => 1, 'em' => 1, 'font' => 1, 'i' => 1, 'img' => 1, 'ins' => 1,
        'kbd' => 1, 'label' => 1, 'mark' => 1, 'q' => 1, 's' => 1, 'samp' => 1, 'small' => 1, 'span' => 1,
        'strike' => 1, 'strong' => 1, 'sub' => 1, 'sup' => 1, 'time' => 1, 'tt' => 1, 'u' => 1, 'var' => 1, 'wbr' => 1,
    );
    /** Start tags that close an open <p>, as in HTML. */
    const CLOSES_P = array(
        'address' => 1, 'article' => 1, 'aside' => 1, 'blockquote' => 1, 'details' => 1, 'dialog' => 1, 'dir' => 1,
        'div' => 1, 'dl' => 1, 'dd' => 1, 'dt' => 1, 'fieldset' => 1, 'figcaption' => 1, 'figure' => 1, 'footer' => 1,
        'form' => 1, 'h1' => 1, 'h2' => 1, 'h3' => 1, 'h4' => 1, 'h5' => 1, 'h6' => 1, 'header' => 1, 'hgroup' => 1,
        'hr' => 1, 'li' => 1, 'main' => 1, 'menu' => 1, 'nav' => 1, 'ol' => 1, 'p' => 1, 'pre' => 1, 'section' => 1,
        'table' => 1, 'ul' => 1,
    );

    private $page_url;
    private $origin;
    private $scheme;
    private $deadline;
    private $max_bytes;
    private $max_depth;
    private $force_tokenizer;
    private $truncated = false;
    private $stopped = false;
    private $nodes = 0;
    private $table_cell = 0;

    /**
     * $options: url (the page, absolute), origin (scheme://host[:port] of the site), deadline (microtime(true) value),
     * max_bytes, max_depth, tokenizer (true: never DOMDocument, for the tests).
     * Returns array('markdown' => string, 'truncated' => bool, 'lang' => the lang attribute of <html> or '').
     */
    public static function convert($html, array $options = array())
    {
        $converter = new self($options);
        return $converter->run(is_string($html) ? $html : '');
    }

    private function __construct(array $options)
    {
        $this->page_url = isset($options['url']) && is_string($options['url']) ? $options['url'] : '';
        $parts = parse_url($this->page_url);
        $this->scheme = is_array($parts) && isset($parts['scheme']) && 'http' === strtolower($parts['scheme']) ? 'http' : 'https';
        $origin = isset($options['origin']) && is_string($options['origin']) ? rtrim($options['origin'], '/') : '';
        if ('' === $origin && is_array($parts) && isset($parts['host'])) {
            $origin = $this->scheme . '://' . $parts['host'] . (isset($parts['port']) ? ':' . (int) $parts['port'] : '');
        }
        $this->origin = $origin;
        $this->deadline = isset($options['deadline']) && is_numeric($options['deadline']) ? (float) $options['deadline'] : microtime(true) + self::TIME_BUDGET;
        $this->max_bytes = isset($options['max_bytes']) ? max(1, (int) $options['max_bytes']) : self::MAX_MARKDOWN_BYTES;
        $this->max_depth = isset($options['max_depth']) ? max(1, (int) $options['max_depth']) : self::MAX_DEPTH;
        $this->force_tokenizer = !empty($options['tokenizer']);
    }

    private function run($html)
    {
        if (strlen($html) > self::MAX_HTML_BYTES) {
            $html = self::cut_utf8($html, self::MAX_HTML_BYTES);
            $this->truncated = true;
        }
        $html = self::valid_utf8($html);
        $tree = null;
        if (!$this->force_tokenizer && class_exists('DOMDocument')) {
            $tree = $this->dom_tree($html);
        }
        if (null === $tree) {
            $tree = $this->token_tree($html);
        }
        $html_element = self::find_element($tree, 'html');
        $lang = null !== $html_element && isset($html_element['a']['lang']) ? trim((string) $html_element['a']['lang']) : '';
        $root = $this->content_root($tree);
        $markdown = '';
        foreach ($this->blocks($root['c'], in_array($root['t'], array('main', 'article'), true), 0) as $block) {
            $add = ('' === $markdown ? '' : "\n\n") . $block;
            if (strlen($markdown) + strlen($add) > $this->max_bytes) {
                $this->truncated = true;
                break;
            }
            $markdown .= $add;
        }
        if ($this->truncated) {
            $markdown .= ('' === $markdown ? '' : "\n\n") . '[…]';
        }
        return array('markdown' => '' === $markdown ? '' : $markdown . "\n", 'truncated' => $this->truncated, 'lang' => $lang);
    }

    /** The first <main>; otherwise the only <article> (a list of several reads better whole); otherwise <body>. */
    private function content_root(array $tree)
    {
        $main = self::find_element($tree, 'main');
        if (null !== $main) {
            return $main;
        }
        $articles = array();
        self::collect_outer($tree, 'article', $articles);
        if (1 === count($articles)) {
            return $articles[0];
        }
        $body = self::find_element($tree, 'body');
        return null !== $body ? $body : $tree;
    }

    /** Depth-first, the first element named $tag, or null. */
    private static function find_element(array $node, $tag)
    {
        foreach ($node['c'] as $child) {
            if (!is_array($child)) {
                continue;
            }
            if ($tag === $child['t']) {
                return $child;
            }
            $found = self::find_element($child, $tag);
            if (null !== $found) {
                return $found;
            }
        }
        return null;
    }

    /** The elements named $tag that are not inside another one. */
    private static function collect_outer(array $node, $tag, array &$found)
    {
        foreach ($node['c'] as $child) {
            if (!is_array($child)) {
                continue;
            }
            if ($tag === $child['t']) {
                $found[] = $child;
            } else {
                self::collect_outer($child, $tag, $found);
            }
        }
    }

    /**
     * Whether an element is left out with all it holds: the SKIPPED tags, hidden elements (hidden, aria-hidden="true",
     * display:none or visibility:hidden in style), navigation and landmark roles, skip links and the header, footer
     * and popup templates of Elementor.
     */
    private static function skipped($tag, array $attributes)
    {
        if (isset(self::SKIPPED[$tag]) || array_key_exists('hidden', $attributes)) {
            return true;
        }
        if (isset($attributes['aria-hidden']) && 'true' === strtolower(trim((string) $attributes['aria-hidden']))) {
            return true;
        }
        if (isset($attributes['style']) && preg_match('~(?:^|;)\s*(?:display\s*:\s*none|visibility\s*:\s*hidden)\b~i', (string) $attributes['style'])) {
            return true;
        }
        if (isset($attributes['role']) && isset(self::SKIPPED_ROLES[strtolower(trim((string) $attributes['role']))])) {
            return true;
        }
        if (isset($attributes['data-elementor-type']) && in_array(strtolower(trim((string) $attributes['data-elementor-type'])), array('header', 'footer', 'popup'), true)) {
            return true;
        }
        return isset($attributes['class'])
            && (bool) preg_match('~(?:^|\s)(?:skip-link|elementor-location-(?:header|footer|popup))(?:\s|$)~i', (string) $attributes['class']);
    }

    /** The tree read by DOMDocument, or null when libxml refuses the HTML. */
    private function dom_tree($html)
    {
        if ('' === $html) {
            return null;
        }
        $previous = libxml_use_internal_errors(true);
        try {
            $document = new DOMDocument();
            $loaded = $document->loadHTML('<?xml encoding="utf-8" ?>' . $html, LIBXML_NONET | LIBXML_NOWARNING | LIBXML_NOERROR | LIBXML_COMPACT);
        } catch (Throwable $error) {
            $loaded = false;
        }
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if (!$loaded) {
            return null;
        }
        $root = array('t' => '#root', 'a' => array(), 'c' => array());
        foreach ($document->childNodes as $child) {
            $node = $this->dom_node($child);
            if (null !== $node) {
                $root['c'][] = $node;
            }
        }
        return $root;
    }

    /** One DOM node as the tree holds it: a string for text, an element array, or null for what is left out. */
    private function dom_node($node)
    {
        if (++$this->nodes > self::MAX_NODES) {
            $this->truncated = true;
            return null;
        }
        if ($node instanceof DOMText) {
            return $node->data;
        }
        if (!$node instanceof DOMElement) {
            return null;
        }
        $tag = strtolower($node->nodeName);
        $attributes = array();
        if ($node->attributes) {
            foreach ($node->attributes as $attribute) {
                $name = strtolower($attribute->nodeName);
                if (!isset($attributes[$name])) {
                    $attributes[$name] = (string) $attribute->nodeValue;
                }
            }
        }
        if (self::skipped($tag, $attributes)) {
            return null;
        }
        $children = array();
        foreach ($node->childNodes as $child) {
            $converted = $this->dom_node($child);
            if (null !== $converted) {
                $children[] = $converted;
            }
        }
        return array('t' => $tag, 'a' => $attributes, 'c' => $children);
    }

    /**
     * The tree read without ext-dom: comments, doctypes and processing instructions are dropped, raw text elements
     * jumped over, SKIPPED and hidden elements dropped with their content, and the end tags HTML implies (an open <p>
     * before a block, an <li> before the next one, table cells and rows) are closed as a browser would.
     */
    private function token_tree($html)
    {
        $store = array(array('t' => '#root', 'a' => array(), 'c' => array()));
        $stack = array(0);
        $phantoms = array();
        $skip = null;
        $length = strlen($html);
        $offset = 0;
        while ($offset < $length) {
            if (++$this->nodes > self::MAX_NODES) {
                $this->truncated = true;
                break;
            }
            $lt = strpos($html, '<', $offset);
            if (false === $lt) {
                $lt = $length;
            }
            if ($lt > $offset) {
                if (null === $skip) {
                    $store[end($stack)]['c'][] = self::decode(substr($html, $offset, $lt - $offset));
                }
                $offset = $lt;
                continue;
            }
            if (0 === substr_compare($html, '<!--', $offset, 4)) {
                $end = strpos($html, '-->', $offset + 4);
                $offset = false === $end ? $length : $end + 3;
                continue;
            }
            $next = $offset + 1 < $length ? $html[$offset + 1] : '';
            if ('!' === $next || '?' === $next) {
                $end = strpos($html, '>', $offset);
                $offset = false === $end ? $length : $end + 1;
                continue;
            }
            if (preg_match('~\G</([A-Za-z][^\s/>]*)[^>]*>~', $html, $match, 0, $offset)) {
                $offset += strlen($match[0]);
                $tag = strtolower($match[1]);
                if (null !== $skip) {
                    if ($tag === $skip['tag'] && 0 === --$skip['depth']) {
                        $skip = null;
                    }
                    continue;
                }
                if (!empty($phantoms[$tag])) {
                    $phantoms[$tag]--;
                    continue;
                }
                if ('body' === $tag || 'html' === $tag) {
                    continue;
                }
                $position = self::open_position($store, $stack, array($tag), array());
                if (null !== $position) {
                    $stack = array_slice($stack, 0, $position);
                }
                continue;
            }
            if (!preg_match('~\G<([A-Za-z][^\s/>]*+)((?:[^>"\']++|"[^"]*+"|\'[^\']*+\')*+)>~', $html, $match, 0, $offset)) {
                // A '<' that opens no tag is text.
                if (null === $skip) {
                    $store[end($stack)]['c'][] = '<';
                }
                $offset++;
                continue;
            }
            $offset += strlen($match[0]);
            $tag = strtolower($match[1]);
            if (isset(self::RAW_TEXT[$tag])) {
                $close = 'plaintext' === $tag ? false : self::closing_tag_position($html, $tag, $offset);
                if (false === $close) {
                    $offset = $length;
                } else {
                    $end = strpos($html, '>', $close);
                    $offset = false === $end ? $length : $end + 1;
                }
                continue;
            }
            // «<br/>», «<img src="x" />»; the slash of an unquoted value («<a href=/x/>») does not close the tag.
            $self_closing = (bool) preg_match('~(?:^|[\s"\'])/\s*$~', $match[2]);
            if (null !== $skip) {
                if ($tag === $skip['tag'] && !$self_closing && !isset(self::VOID[$tag])) {
                    $skip['depth']++;
                }
                continue;
            }
            $attributes = self::parse_attributes($match[2]);
            if (self::skipped($tag, $attributes)) {
                if (!$self_closing && !isset(self::VOID[$tag])) {
                    $skip = array('tag' => $tag, 'depth' => 1);
                }
                continue;
            }
            $stack = $this->implied_ends($store, $stack, $tag);
            if (count($stack) >= self::MAX_OPEN_ELEMENTS) {
                if (!$self_closing && !isset(self::VOID[$tag])) {
                    $phantoms[$tag] = (isset($phantoms[$tag]) ? $phantoms[$tag] : 0) + 1;
                }
                continue;
            }
            $id = count($store);
            $store[] = array('t' => $tag, 'a' => $attributes, 'c' => array());
            $store[end($stack)]['c'][] = $id;
            if (!$self_closing && !isset(self::VOID[$tag])) {
                $stack[] = $id;
            }
        }
        return self::assemble($store, 0);
    }

    /** The open elements left after the end tags that $tag implies. */
    private function implied_ends(array $store, array $stack, $tag)
    {
        $through = array_merge(self::INLINE, array('div' => 1, 'p' => 1, 'address' => 1));
        if (isset(self::CLOSES_P[$tag])) {
            $position = self::open_position($store, $stack, array('p'), self::INLINE);
            if (null !== $position) {
                $stack = array_slice($stack, 0, $position);
            }
        }
        $closes = array();
        if ('li' === $tag) {
            $closes = array('li');
        } elseif ('dt' === $tag || 'dd' === $tag) {
            $closes = array('dt', 'dd');
        } elseif ('td' === $tag || 'th' === $tag) {
            $closes = array('td', 'th');
        } elseif ('tr' === $tag) {
            $closes = array('tr');
            $through = array_merge($through, array('td' => 1, 'th' => 1));
        } elseif ('thead' === $tag || 'tbody' === $tag || 'tfoot' === $tag) {
            $closes = array('thead', 'tbody', 'tfoot');
            $through = array_merge($through, array('td' => 1, 'th' => 1, 'tr' => 1));
        } elseif (preg_match('/^h[1-6]$/', $tag)) {
            $top = $store[end($stack)]['t'];
            if (preg_match('/^h[1-6]$/', $top)) {
                array_pop($stack);
            }
        }
        if (!empty($closes)) {
            $position = self::open_position($store, $stack, $closes, $through);
            if (null !== $position) {
                $stack = array_slice($stack, 0, $position);
            }
        }
        return $stack;
    }

    /**
     * The index in $stack of the nearest open element named in $tags, looking down from the top through the elements
     * in $through only (an empty $through looks through everything); null when there is none.
     */
    private static function open_position(array $store, array $stack, array $tags, array $through)
    {
        for ($index = count($stack) - 1; $index > 0; $index--) {
            $open = $store[$stack[$index]]['t'];
            if (in_array($open, $tags, true)) {
                return $index;
            }
            if (!empty($through) && !isset($through[$open])) {
                return null;
            }
        }
        return null;
    }

    /** The nested tree of the tokenizer's flat store. */
    private static function assemble(array $store, $id)
    {
        $node = $store[$id];
        $children = array();
        foreach ($node['c'] as $child) {
            $children[] = is_int($child) ? self::assemble($store, $child) : $child;
        }
        $node['c'] = $children;
        return $node;
    }

    /** The attributes of a start tag, names in lower case, values with entities decoded; the first of a name wins. */
    private static function parse_attributes($raw)
    {
        $attributes = array();
        if (preg_match_all('~([^\s"\'>/=]+)(?:\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s>]+)))?~', (string) $raw, $pairs, PREG_SET_ORDER)) {
            foreach ($pairs as $pair) {
                $name = strtolower($pair[1]);
                if (isset($attributes[$name])) {
                    continue;
                }
                $value = isset($pair[4]) ? $pair[4] : (isset($pair[3]) && '' !== $pair[3] ? $pair[3] : (isset($pair[2]) ? $pair[2] : ''));
                $attributes[$name] = self::decode($value);
            }
        }
        return $attributes;
    }

    /** The offset of the first </$tag (any case) not followed by a name character, or false. */
    private static function closing_tag_position($html, $tag, $offset)
    {
        $needle = '</' . $tag;
        while (false !== ($position = stripos($html, $needle, $offset))) {
            $after = $position + strlen($needle);
            if ($after >= strlen($html) || !preg_match('/[A-Za-z0-9_-]/', $html[$after])) {
                return $position;
            }
            $offset = $after;
        }
        return false;
    }

    private static function decode($text)
    {
        return html_entity_decode((string) $text, ENT_QUOTES | ENT_HTML5 | ENT_SUBSTITUTE, 'UTF-8');
    }

    /** The blocks of a list of nodes: text and phrasing elements gather into paragraphs, the rest are blocks. */
    private function blocks(array $nodes, $in_section, $depth)
    {
        $blocks = array();
        $inline = '';
        foreach ($nodes as $node) {
            if ($this->out_of_time()) {
                break;
            }
            if (!is_array($node)) {
                $inline .= $this->text($node);
                continue;
            }
            $tag = $node['t'];
            // The site's header and footer; a <header> or <footer> of an article, of <main> or of a section stays.
            if (('header' === $tag || 'footer' === $tag) && !$in_section) {
                continue;
            }
            if ($depth >= $this->max_depth) {
                $inline .= ' ' . self::escape(self::collapse(self::text_content($node, false))) . ' ';
                continue;
            }
            if (isset(self::INLINE[$tag])) {
                $inline .= $this->inline_element($node, $depth + 1);
                continue;
            }
            $this->flush($inline, $blocks);
            foreach ($this->block_element($node, $in_section, $depth + 1) as $block) {
                $blocks[] = $block;
            }
        }
        $this->flush($inline, $blocks);
        return $blocks;
    }

    /** Adds the gathered line of text as a paragraph, if it has any, and empties it. */
    private function flush(&$inline, array &$blocks)
    {
        $paragraph = self::paragraph($inline);
        if ('' !== $paragraph) {
            $blocks[] = $paragraph;
        }
        $inline = '';
    }

    /** A paragraph from a line of inline Markdown: spaces collapsed, each <br> a hard line break («\» at the end of the line). */
    private static function paragraph($inline)
    {
        $lines = array();
        foreach (explode("\n", (string) $inline) as $line) {
            $line = trim(preg_replace('/ {2,}/', ' ', $line));
            if ('' !== $line) {
                $lines[] = $line;
            }
        }
        return implode("\\\n", $lines);
    }

    /** The Markdown blocks of one block element. */
    private function block_element(array $node, $in_section, $depth)
    {
        $tag = $node['t'];
        if (preg_match('/^h([1-6])$/', $tag, $level)) {
            $text = self::one_line($this->inline_children($node, $depth));
            if ('' === $text) {
                return array();
            }
            // «Title #» would lose its last # as a closing sequence.
            $text = preg_replace('/(\s)(#+)$/', '$1\\\\$2', $text);
            return array(str_repeat('#', (int) $level[1]) . ' ' . $text);
        }
        switch ($tag) {
            case 'p':
                $paragraph = self::paragraph($this->inline_children($node, $depth));
                return '' === $paragraph ? array() : array($paragraph);
            case 'ul':
            case 'ol':
            case 'menu':
                return $this->list_block($node, 'ol' === $tag, $in_section, $depth);
            case 'pre':
                return $this->code_block($node);
            case 'blockquote':
                $inner = $this->blocks($node['c'], $in_section, $depth);
                if (empty($inner)) {
                    return array();
                }
                $lines = array();
                foreach (explode("\n", implode("\n\n", $inner)) as $line) {
                    $lines[] = '' === $line ? '>' : '> ' . $line;
                }
                return array(implode("\n", $lines));
            case 'hr':
                return array('---');
            case 'table':
                return $this->table_block($node, $depth);
            case 'dl':
                $blocks = array();
                foreach ($node['c'] as $child) {
                    if (is_array($child) && 'dt' === $child['t']) {
                        $term = self::one_line($this->inline_children($child, $depth + 1));
                        if ('' !== $term) {
                            $blocks[] = '**' . $term . '**';
                        }
                    } elseif (is_array($child)) {
                        foreach ($this->blocks(array($child), $in_section, $depth) as $block) {
                            $blocks[] = $block;
                        }
                    }
                }
                return $blocks;
        }
        return $this->blocks($node['c'], $in_section || in_array($tag, array('article', 'main', 'section'), true), $depth);
    }

    /** A list: «- » or «N. » items, nested lists indented under their item, tight unless an item has paragraphs. */
    private function list_block(array $node, $ordered, $in_section, $depth)
    {
        $number = 1;
        if ($ordered && isset($node['a']['start']) && preg_match('/^\s*(\d{1,9})\s*$/', (string) $node['a']['start'], $start)) {
            $number = (int) $start[1];
        }
        $items = array();
        foreach ($node['c'] as $child) {
            if (!is_array($child)) {
                $blocks = array();
                $paragraph = self::paragraph($this->text($child));
                if ('' !== $paragraph) {
                    $blocks[] = $paragraph;
                }
            } else {
                $blocks = $this->blocks('li' === $child['t'] ? $child['c'] : array($child), $in_section, $depth);
            }
            if (empty($blocks)) {
                continue;
            }
            $body = '';
            foreach ($blocks as $index => $block) {
                $body .= (0 === $index ? '' : (self::is_list_block($block) ? "\n" : "\n\n")) . $block;
            }
            $marker = $ordered ? $number . '. ' : '- ';
            $number++;
            $indent = str_repeat(' ', strlen($marker));
            $lines = explode("\n", $body);
            foreach ($lines as $index => $line) {
                $lines[$index] = 0 === $index ? $marker . $line : ('' === $line ? '' : $indent . $line);
            }
            $items[] = implode("\n", $lines);
        }
        if (empty($items)) {
            return array();
        }
        $loose = false;
        foreach ($items as $item) {
            if (false !== strpos($item, "\n\n")) {
                $loose = true;
                break;
            }
        }
        return array(implode($loose ? "\n\n" : "\n", $items));
    }

    /** True for a block this converter wrote as a list (text that starts like one was escaped). */
    private static function is_list_block($block)
    {
        return (bool) preg_match('/^(?:- |\d{1,9}\. )/', $block);
    }

    /** A fenced code block, with a fence longer than any run of backticks in the code and the language of its class. */
    private function code_block(array $node)
    {
        $code = str_replace(array("\r\n", "\r"), "\n", self::text_content($node, true));
        $code = preg_replace('/^\n/', '', $code);
        $code = rtrim($code, " \t\n");
        if ('' === trim($code)) {
            return array();
        }
        $language = '';
        $candidates = array($node);
        foreach ($node['c'] as $child) {
            if (is_array($child) && 'code' === $child['t']) {
                $candidates[] = $child;
                break;
            }
        }
        foreach ($candidates as $candidate) {
            if (isset($candidate['a']['class']) && preg_match('/(?:^|\s)(?:language|lang)-([A-Za-z0-9_+#.-]{1,30})(?:\s|$)/', (string) $candidate['a']['class'], $match)) {
                $language = $match[1];
                break;
            }
        }
        $longest = 0;
        if (preg_match_all('/`+/', $code, $runs)) {
            foreach ($runs[0] as $run) {
                $longest = max($longest, strlen($run));
            }
        }
        $fence = str_repeat('`', max(3, $longest + 1));
        return array($fence . $language . "\n" . $code . "\n" . $fence);
    }

    /**
     * A table: a GFM table when every cell spans one row and one column and no table is nested (the first row is the
     * header); otherwise one paragraph per row, the cells separated by « | ». The caption comes first.
     */
    private function table_block(array $node, $depth)
    {
        $rows = array();
        $caption = '';
        $simple = true;
        $sections = array($node);
        while (!empty($sections)) {
            $section = array_shift($sections);
            foreach ($section['c'] as $child) {
                if (!is_array($child)) {
                    continue;
                }
                if ('caption' === $child['t']) {
                    $caption = self::paragraph($this->inline_children($child, $depth));
                } elseif (in_array($child['t'], array('thead', 'tbody', 'tfoot'), true)) {
                    $sections[] = $child;
                } elseif ('tr' === $child['t']) {
                    $cells = array();
                    foreach ($child['c'] as $cell) {
                        if (!is_array($cell) || ('td' !== $cell['t'] && 'th' !== $cell['t'])) {
                            continue;
                        }
                        foreach (array('colspan', 'rowspan') as $span) {
                            if (isset($cell['a'][$span]) && (int) $cell['a'][$span] > 1) {
                                $simple = false;
                            }
                        }
                        if (null !== self::find_element($cell, 'table')) {
                            $simple = false;
                        }
                        $cells[] = $cell;
                    }
                    if (!empty($cells)) {
                        $rows[] = $cells;
                    }
                }
            }
        }
        $blocks = '' === $caption ? array() : array($caption);
        if (empty($rows)) {
            return $blocks;
        }
        $text_rows = array();
        $columns = 0;
        $this->table_cell++;
        foreach ($rows as $cells) {
            $texts = array();
            foreach ($cells as $cell) {
                $texts[] = self::one_line($this->inline_children($cell, $depth + 1));
            }
            $columns = max($columns, count($texts));
            $text_rows[] = $texts;
        }
        $this->table_cell--;
        if ($simple) {
            $lines = array();
            foreach ($text_rows as $index => $texts) {
                $texts = array_pad($texts, $columns, '');
                $lines[] = '| ' . implode(' | ', $texts) . ' |';
                if (0 === $index) {
                    $lines[] = '|' . str_repeat(' --- |', $columns);
                }
            }
            $blocks[] = implode("\n", $lines);
            return $blocks;
        }
        foreach ($text_rows as $texts) {
            $texts = array_values(array_filter($texts, 'strlen'));
            if (!empty($texts)) {
                $blocks[] = implode(' | ', $texts);
            }
        }
        return $blocks;
    }

    /** The inline Markdown of an element's children; a block inside becomes text separated by spaces. */
    private function inline_children(array $node, $depth)
    {
        $out = '';
        foreach ($node['c'] as $child) {
            if ($this->out_of_time()) {
                break;
            }
            if (!is_array($child)) {
                $out .= $this->text($child);
            } elseif ($depth >= $this->max_depth) {
                $out .= ' ' . self::escape(self::collapse(self::text_content($child, false))) . ' ';
            } elseif (isset(self::INLINE[$child['t']])) {
                $out .= $this->inline_element($child, $depth + 1);
            } else {
                $out .= ' ' . $this->inline_children($child, $depth + 1) . ' ';
            }
        }
        return $out;
    }

    /** One phrasing element as inline Markdown. */
    private function inline_element(array $node, $depth)
    {
        switch ($node['t']) {
            case 'br':
                return $this->table_cell > 0 ? ' ' : "\n";
            case 'wbr':
                return '';
            case 'img':
                return $this->image($node);
            case 'a':
                return $this->link($node, $depth);
            case 'strong':
            case 'b':
                return self::emphasis($this->inline_children($node, $depth), '**');
            case 'em':
            case 'i':
                return self::emphasis($this->inline_children($node, $depth), '*');
            case 'del':
            case 's':
            case 'strike':
                return self::emphasis($this->inline_children($node, $depth), '~~');
            case 'code':
            case 'kbd':
            case 'samp':
            case 'tt':
                return $this->code_span(self::text_content($node, false));
        }
        return $this->inline_children($node, $depth);
    }

    /** Text between two markers, the spaces around it kept outside them. */
    private static function emphasis($inner, $marker)
    {
        $inner = str_replace("\n", ' ', $inner);
        $trimmed = trim($inner);
        if ('' === $trimmed) {
            return '' === $inner ? '' : ' ';
        }
        return (' ' === substr($inner, 0, 1) ? ' ' : '') . $marker . $trimmed . $marker . (' ' === substr($inner, -1) ? ' ' : '');
    }

    /** A code span whose backtick fence is longer than any run of backticks inside it. */
    private function code_span($code)
    {
        $code = str_replace(array("\r\n", "\r", "\n"), ' ', $code);
        if ('' === trim($code)) {
            return '';
        }
        if ($this->table_cell > 0) {
            $code = str_replace('|', '\\|', $code);
        }
        $longest = 0;
        if (preg_match_all('/`+/', $code, $runs)) {
            foreach ($runs[0] as $run) {
                $longest = max($longest, strlen($run));
            }
        }
        $fence = str_repeat('`', $longest + 1);
        $pad = ('`' === substr($code, 0, 1) || '`' === substr($code, -1)) ? ' ' : '';
        return $fence . $pad . $code . $pad . $fence;
    }

    /** [text](url), the text alone when the URL is refused, nothing when there is no text. */
    private function link(array $node, $depth)
    {
        $text = self::one_line($this->inline_children($node, $depth));
        if ('' === $text) {
            foreach (array('aria-label', 'title') as $attribute) {
                if (isset($node['a'][$attribute])) {
                    $text = self::escape(self::collapse($node['a'][$attribute]));
                    $text = trim($text);
                    if ('' !== $text) {
                        break;
                    }
                }
            }
        }
        if ('' === $text) {
            return '';
        }
        $url = isset($node['a']['href']) ? $this->url($node['a']['href'], false) : '';
        return '' === $url ? $text : '[' . $text . '](' . $url . ')';
    }

    /** ![alt](src): src, or the URL a lazy loader keeps in data-src, data-lazy-src or data-original; http(s) only. */
    private function image(array $node)
    {
        $url = '';
        foreach (array('src', 'data-src', 'data-lazy-src', 'data-original') as $attribute) {
            if (isset($node['a'][$attribute]) && '' !== trim($node['a'][$attribute])) {
                $url = $this->url($node['a'][$attribute], true);
                if ('' !== $url) {
                    break;
                }
            }
        }
        if ('' === $url) {
            return '';
        }
        $alt = isset($node['a']['alt']) ? trim(self::escape(self::collapse($node['a']['alt']))) : '';
        return '![' . $alt . '](' . $url . ')';
    }

    /**
     * An absolute URL for a link (http, https, mailto, tel) or an image (http, https), resolved on the page URL and the
     * site origin, without credentials and without query parameters that carry a nonce, a session or a token, encoded
     * for a Markdown destination; '' for anything else (javascript:, data:, vbscript:, file:…).
     */
    private function url($value, $image)
    {
        $value = trim((string) $value, "\x00..\x20");
        $value = str_replace(array("\t", "\n", "\r"), '', $value);
        if ('' === $value) {
            return '';
        }
        $value = str_replace('\\', '/', $value);
        if (preg_match('~^([A-Za-z][A-Za-z0-9+.-]*):~', $value, $match)) {
            $scheme = strtolower($match[1]);
            if ('mailto' === $scheme || 'tel' === $scheme) {
                return $image ? '' : self::encode_destination($value);
            }
            if ('http' !== $scheme && 'https' !== $scheme) {
                return '';
            }
            $absolute = $value;
        } elseif ('//' === substr($value, 0, 2)) {
            $absolute = $this->scheme . ':' . $value;
        } elseif ('/' === $value[0]) {
            if ('' === $this->origin) {
                return '';
            }
            $absolute = $this->origin . $value;
        } else {
            $absolute = $this->resolve_relative($value);
        }
        $parts = parse_url($absolute);
        if (!is_array($parts) || empty($parts['host']) || !isset($parts['scheme'])
            || !in_array(strtolower($parts['scheme']), array('http', 'https'), true) || isset($parts['user']) || isset($parts['pass'])) {
            return '';
        }
        return self::encode_destination(self::without_secret_parameters($absolute));
    }

    /** A relative reference (#fragment, ?query, path) resolved on the page URL, dot segments removed. */
    private function resolve_relative($value)
    {
        $page = parse_url($this->page_url);
        if (!is_array($page) || empty($page['host'])) {
            return '';
        }
        $base = $this->scheme . '://' . $page['host'] . (isset($page['port']) ? ':' . (int) $page['port'] : '');
        $path = isset($page['path']) && '' !== $page['path'] ? $page['path'] : '/';
        $query = isset($page['query']) ? '?' . $page['query'] : '';
        if ('#' === $value[0]) {
            return $base . $path . $query . $value;
        }
        if ('?' === $value[0]) {
            return $base . $path . $value;
        }
        $suffix = '';
        $cut = strcspn($value, '?#');
        if ($cut < strlen($value)) {
            $suffix = substr($value, $cut);
            $value = substr($value, 0, $cut);
        }
        $directory = substr($path, 0, (int) strrpos($path, '/') + 1);
        return $base . self::remove_dot_segments($directory . $value) . $suffix;
    }

    private static function remove_dot_segments($path)
    {
        $output = array();
        $segments = explode('/', $path);
        $last = count($segments) - 1;
        foreach ($segments as $index => $segment) {
            if ('.' === $segment) {
                if ($index === $last) {
                    $output[] = '';
                }
                continue;
            }
            if ('..' === $segment) {
                if (count($output) > 1) {
                    array_pop($output);
                }
                if ($index === $last) {
                    $output[] = '';
                }
                continue;
            }
            $output[] = $segment;
        }
        $result = implode('/', $output);
        return '/' === substr($result, 0, 1) ? $result : '/' . $result;
    }

    /** The URL without query parameters whose name holds «nonce», «session» or «token», or starts with «_wp». */
    private static function without_secret_parameters($url)
    {
        $fragment = '';
        $hash = strpos($url, '#');
        if (false !== $hash) {
            $fragment = substr($url, $hash);
            $url = substr($url, 0, $hash);
        }
        $question = strpos($url, '?');
        if (false === $question) {
            return $url . $fragment;
        }
        $kept = array();
        foreach (explode('&', substr($url, $question + 1)) as $pair) {
            if ('' === $pair) {
                continue;
            }
            $name = rawurldecode(str_replace('+', ' ', explode('=', $pair, 2)[0]));
            if (!preg_match('/nonce|session|token|^_wp|^sid$/i', $name)) {
                $kept[] = $pair;
            }
        }
        return substr($url, 0, $question) . (empty($kept) ? '' : '?' . implode('&', $kept)) . $fragment;
    }

    /** Spaces, controls, parentheses, angle brackets, quotes and backslashes percent-encoded. */
    private static function encode_destination($url)
    {
        return preg_replace_callback('/[\x00-\x20\x7F()<>"\\\\]/', function ($match) {
            return '%' . strtoupper(bin2hex($match[0]));
        }, $url);
    }

    /** A text run as Markdown: controls and the date markers of 2.5.0 dropped, spaces collapsed, specials escaped. */
    private function text($text)
    {
        return self::escape(self::collapse((string) $text));
    }

    private static function collapse($text)
    {
        $text = preg_replace('/[\x{10FFF1}-\x{10FFF3}]/u', '', (string) $text);
        $text = preg_replace('/[\x00-\x08\x0B\x0E-\x1F\x7F]/', '', (string) $text);
        $text = str_replace("\xC2\xA0", ' ', (string) $text);
        return preg_replace('/[ \t\n\r\f]+/', ' ', (string) $text);
    }

    /**
     * Markdown specials of a text run escaped with a backslash: \ ` * _ [ ] < ~ | everywhere, & before what reads as an
     * entity, and at the start of the run # + - = > and «1.» or «1)», which would start a heading, a list or a quote.
     */
    private static function escape($text)
    {
        $text = preg_replace('/([\\\\`*_\[\]<~|])/', '\\\\$1', (string) $text);
        $text = preg_replace('/&(?=#?[A-Za-z0-9]{1,32};)/', '\\\\&', $text);
        $text = preg_replace('/^(\s*)([#+=>-])/', '$1\\\\$2', $text);
        return preg_replace('/^(\s*\d{1,9})([.)])/', '$1\\\\$2', $text);
    }

    /** All the text of an element; $breaks keeps <br> as a new line (code blocks), otherwise a space. */
    private static function text_content(array $node, $breaks)
    {
        $text = '';
        foreach ($node['c'] as $child) {
            if (!is_array($child)) {
                $text .= $child;
            } elseif ('br' === $child['t']) {
                $text .= $breaks ? "\n" : ' ';
            } else {
                $text .= self::text_content($child, $breaks);
            }
        }
        return $text;
    }

    /** Inline Markdown on one line: new lines become spaces, spaces collapse. */
    private static function one_line($inline)
    {
        return trim(preg_replace('/\s{2,}/', ' ', str_replace("\n", ' ', (string) $inline)));
    }

    private function out_of_time()
    {
        if (!$this->stopped && microtime(true) > $this->deadline) {
            $this->stopped = true;
            $this->truncated = true;
        }
        return $this->stopped;
    }

    /** At most $bytes bytes of $text, never cutting a UTF-8 character. */
    private static function cut_utf8($text, $bytes)
    {
        $text = substr($text, 0, $bytes);
        return preg_replace('/[\xC0-\xFF][\x80-\xBF]*$/', '', $text);
    }

    /** The text with invalid UTF-8 sequences replaced. */
    private static function valid_utf8($text)
    {
        if ('' === $text || preg_match('//u', $text)) {
            return $text;
        }
        if (function_exists('mb_scrub')) {
            return mb_scrub($text, 'UTF-8');
        }
        return htmlspecialchars_decode(htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'), ENT_QUOTES);
    }
}
