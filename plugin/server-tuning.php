<?php
/**
 * What the Server tab can change, each by a single explicit action of an administrator:
 * a compression and file-cache block in .htaccess (Apache, LiteSpeed), the same rules as text
 * for Nginx, and emptying Aruba's HiSpeed Cache after Static Bridge publishes.
 *
 * The .htaccess block has markers of its own, which share no prefix with the routing blocks,
 * so the routing rewrite never touches it and it never touches them.
 */

if (!defined('ABSPATH')) {
    exit;
}

final class Static_Bridge_Server_Tuning
{
    const HTACCESS_BEGIN = '# BEGIN StaticBridge-Server';
    const HTACCESS_END = '# END StaticBridge-Server';
    const HTACCESS_PATTERN = '~^# BEGIN StaticBridge-Server\R.*?^# END StaticBridge-Server\h*(?:\R\h*(?=\R|$))*\R?~ms';
    /** Pages are never in these lists: their freshness stays with Static Bridge's own Cache-Control. */
    const COMPRESSED_TYPES = 'text/html text/plain text/css text/xml text/javascript application/javascript application/json application/xml application/rss+xml application/atom+xml image/svg+xml';
    const FILE_LIFETIMES = array(
        'text/css' => '30 days',
        'text/javascript' => '30 days',
        'application/javascript' => '30 days',
        'image/jpeg' => '30 days',
        'image/png' => '30 days',
        'image/gif' => '30 days',
        'image/webp' => '30 days',
        'image/avif' => '30 days',
        'image/svg+xml' => '30 days',
        'image/x-icon' => '30 days',
        'font/woff2' => '1 year',
        'font/woff' => '1 year',
    );

    public static function htaccess_block()
    {
        $expires = '';
        foreach (self::FILE_LIFETIMES as $type => $lifetime) {
            $expires .= 'ExpiresByType ' . $type . ' "access plus ' . $lifetime . "\"\n";
        }
        return self::HTACCESS_BEGIN . "\n"
            . "# Compressione e cache del browser per i file, dalla scheda Server di Static Bridge.\n"
            . "# Le pagine HTML non hanno una scadenza qui: la loro cache resta quella di Static Bridge.\n"
            . "<IfModule mod_deflate.c>\n<IfModule mod_filter.c>\n"
            . 'AddOutputFilterByType DEFLATE ' . self::COMPRESSED_TYPES . "\n"
            . "</IfModule>\n</IfModule>\n"
            . "<IfModule mod_expires.c>\nExpiresActive On\n"
            . $expires
            . "</IfModule>\n"
            . self::HTACCESS_END;
    }

    public static function htaccess_has_block($contents)
    {
        return is_string($contents) && 1 === preg_match(self::HTACCESS_PATTERN, $contents);
    }

    /** $contents with the block removed ($enabled false) or as the first lines of the file ($enabled true). */
    public static function htaccess_contents($contents, $enabled)
    {
        $contents = (string) preg_replace(self::HTACCESS_PATTERN, '', (string) $contents, 1);
        if (!$enabled) {
            return $contents;
        }
        return self::htaccess_block() . "\n\n" . ltrim($contents);
    }

    /**
     * Adds or removes the block in $file with an atomic rename. The file must exist already (WordPress
     * writes it when the permalinks are saved): Static Bridge does not create a .htaccess of its own here.
     *
     * @return true|WP_Error
     */
    public static function write_htaccess_block($file, $enabled)
    {
        if (is_link($file) || !is_file($file) || !is_readable($file) || !is_writable($file)) {
            return new WP_Error('static_bridge_htaccess_unwritable', __('Il file .htaccess non esiste o non è scrivibile da WordPress.', 'static-bridge'));
        }
        $contents = file_get_contents($file);
        if (false === $contents) {
            return new WP_Error('static_bridge_htaccess_unreadable', __('Il file .htaccess non si può leggere.', 'static-bridge'));
        }
        $updated = self::htaccess_contents($contents, $enabled);
        if ($updated === $contents) {
            return true;
        }
        $temporary = $file . '.static-bridge-server-' . bin2hex(random_bytes(4)) . '.tmp';
        if (file_exists($temporary) || is_link($temporary) || strlen($updated) !== @file_put_contents($temporary, $updated, LOCK_EX)) {
            @unlink($temporary);
            return new WP_Error('static_bridge_htaccess_write', __('Scrittura del file .htaccess non riuscita.', 'static-bridge'));
        }
        $mode = fileperms($file);
        if (false !== $mode) {
            @chmod($temporary, $mode & 0777);
        }
        if (!@rename($temporary, $file)) {
            @unlink($temporary);
            return new WP_Error('static_bridge_htaccess_write', __('Scrittura del file .htaccess non riuscita.', 'static-bridge'));
        }
        return true;
    }

    /** The same rules for Nginx, to copy: 'http' goes in the http block, 'server' in the site's server block. */
    public static function nginx_snippets()
    {
        $lifetimes = '';
        foreach (array('~^text/css' => '30d', '~javascript' => '30d', '~^image/' => '30d', '~^font/' => '365d') as $type => $lifetime) {
            $lifetimes .= '    ' . $type . ' ' . $lifetime . ";\n";
        }
        return array(
            'http' => "# Static Bridge: compressione e scadenza dei file (blocco http)\n"
                . "gzip on;\n"
                . "gzip_vary on;\n"
                . "gzip_types text/plain text/css text/xml text/javascript application/javascript application/json application/xml application/rss+xml application/atom+xml image/svg+xml;\n"
                . "map \$sent_http_content_type \$static_bridge_expires {\n"
                . "    default off;\n"
                . $lifetimes
                . "}",
            'server' => "# Static Bridge: scadenza dei file (blocco server del sito)\n"
                . "expires \$static_bridge_expires;",
        );
    }

    /** True when the Aruba HiSpeed Cache plugin is loaded and offers its purger. */
    public static function aruba_purge_available()
    {
        return class_exists('\ArubaSPA\HiSpeedCache\Purger\WpPurger') && defined('AHSC_PURGER')
            && method_exists('\ArubaSPA\HiSpeedCache\Purger\WpPurger', 'setPurger');
    }

    /**
     * Empties Aruba's HiSpeed Cache through its own plugin: 'everything' or a list of same-site URLs.
     * Returns null when the plugin is not there, false when the purge failed.
     */
    public static function purge_aruba($targets)
    {
        if (!self::aruba_purge_available()) {
            return null;
        }
        try {
            $purger = new \ArubaSPA\HiSpeedCache\Purger\WpPurger();
            $purger->setPurger(AHSC_PURGER);
            if ('everything' === $targets) {
                $purger->purgeAll();
            } else {
                $urls = array_values(array_filter(array_map('strval', (array) $targets)));
                if (empty($urls)) {
                    return true;
                }
                $purger->purgeUrls($urls);
            }
            return true;
        } catch (Throwable $error) {
            return false;
        }
    }
}
