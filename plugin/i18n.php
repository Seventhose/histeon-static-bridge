<?php
/**
 * Static Bridge's own interface languages: written in Italian, English for every other language
 * (languages/static-bridge-en_US), unless a language has a file of its own.
 */

if (!defined('ABSPATH')) {
    exit;
}

final class Static_Bridge_I18n
{
    const DOMAIN = 'static-bridge';
    const FALLBACK = 'en_US';

    public static function init(): void
    {
        add_action('init', array(self::class, 'load'), 0);
        add_filter('load_translation_file', array(self::class, 'fallback'), 10, 3);
        add_filter('load_script_translation_file', array(self::class, 'script_fallback'), 10, 3);
    }

    public static function load(): void
    {
        load_plugin_textdomain(self::DOMAIN, false, dirname(plugin_basename(__DIR__ . '/static-bridge.php')) . '/languages');
    }

    /** A classic script of the plugin that uses wp.i18n with this domain. */
    public static function script(string $handle): void
    {
        wp_set_script_translations($handle, self::DOMAIN, __DIR__ . '/languages');
    }

    private static function needs_fallback(string $locale): bool
    {
        return '' !== $locale && !str_starts_with($locale, 'it') && self::FALLBACK !== $locale;
    }

    public static function fallback(string $file, string $domain, string $locale = ''): string
    {
        if (self::DOMAIN !== $domain || !self::needs_fallback($locale) || is_readable($file)) {
            return $file;
        }
        $english = (string) preg_replace('/-' . preg_quote($locale, '/') . '(\.l10n\.php|\.mo)$/', '-' . self::FALLBACK . '$1', $file);
        return $english !== $file && is_readable($english) ? $english : $file;
    }

    public static function script_fallback(string|false $file, string $handle, string $domain): string|false
    {
        if (false === $file || self::DOMAIN !== $domain || is_readable($file) || 1 !== preg_match('/-([a-z]{2,3}(?:_[A-Za-z0-9]+)*)-[0-9a-f]{32}\.json$/', $file, $m) || !self::needs_fallback($m[1])) {
            return $file;
        }
        $english = (string) preg_replace('/-' . preg_quote($m[1], '/') . '-([0-9a-f]{32}\.json)$/', '-' . self::FALLBACK . '-$1', $file);
        return is_readable($english) ? $english : $file;
    }
}

Static_Bridge_I18n::init();
