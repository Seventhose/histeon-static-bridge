<?php
/**
 * Removes everything Static Bridge created when the plugin is deleted from
 * WordPress: routing markers and .htaccess blocks first (so the web server
 * stops serving static copies), then the static releases, scheduled events,
 * custom tables and options. Deactivation keeps all of this; only deletion
 * through the WordPress Plugins screen calls this class (see uninstall.php).
 *
 * Kept free of the main plugin class so that uninstall never runs the
 * plugin's constructor, hooks or render recovery.
 */

if (!defined('ABSPATH')) {
    exit;
}

final class Static_Bridge_Uninstaller
{
    const OPTION_PREFIX = 'static_bridge_';

    const CRON_HOOKS = array(
        'static_bridge_process_export_queue',
        'static_bridge_process_incremental_queue',
        'static_bridge_release_retention',
        'static_bridge_cloudflare_purge',
        'static_bridge_served_copy_maintenance',
        // «Export completo pianificato» (2.5.0): its single event.
        'static_bridge_scheduled_export',
        // The link check after a publication with Strict (2.5.0): its single event.
        'static_bridge_link_check',
        // «Pagine da aggiornare ogni notte» and the waited refresh after a menu or widget change (2.5.0).
        'static_bridge_nightly_paths',
        'static_bridge_layout_refresh',
        // The export after an update with «Rigenera dopo gli aggiornamenti» (2.5.0).
        'static_bridge_after_update_export',
    );

    // Protected post meta of Static Bridge: the editor checkbox «Non salvare questa pagina in statico» (2.5.0).
    const POST_META_KEYS = array(
        '_static_bridge_exclude',
    );

    // Options added from 2.5.0 on, also deleted by name: the prefix query below finds them too, and this list
    // removes them even when that query fails. «Non salvare questa pagina in statico» (scheda 42), the state of
    // «Attesa dopo l'ultima modifica» and of the e-mail sent when an update fails, the report of the link check
    // after a publication, the menu and widget changes waiting for their refresh, the changes after an update, the
    // routes written and not yet purged of «Non riscrivere le pagine identiche», the short lock of one write of the
    // incremental queue (2.5.0).
    const OPTIONS = array(
        'static_bridge_editor_excluded',
        'static_bridge_incremental_wait',
        'static_bridge_failure_notice',
        'static_bridge_link_check',
        'static_bridge_layout_refresh',
        'static_bridge_after_update',
        'static_bridge_unsettled_routes',
        'static_bridge_incremental_queue_write_lock',
    );

    // Same suffixes as Static_Bridge_Export_Queue, Static_Bridge_Dependency_Index
    // and Static_Bridge_Header_Fragment_Index (checked by the test suite).
    const TABLE_SUFFIXES = array(
        'static_bridge_export_urls',
        'static_bridge_v2_state',
        'static_bridge_v2_routes',
        'static_bridge_v2_edges',
        'static_bridge_v2_fragments',
    );

    private $wpdb;
    private $content_dir;
    private $abspath;

    public function __construct($wpdb, $content_dir, $abspath)
    {
        $this->wpdb = $wpdb;
        $this->content_dir = rtrim((string) $content_dir, '/\\');
        $this->abspath = rtrim((string) $abspath, '/\\') . '/';
    }

    public function run()
    {
        $report = array(
            'cron_hooks_cleared' => 0,
            'htaccess_cleaned' => $this->remove_htaccess_blocks(),
            'object_cache_dropin_removed' => $this->remove_object_cache_dropin(),
            'files_removed' => false,
            'tables_dropped' => 0,
            'options_deleted' => 0,
            'post_meta_deleted' => 0,
        );
        foreach (self::CRON_HOOKS as $hook) {
            if (function_exists('wp_unschedule_hook')) {
                wp_unschedule_hook($hook);
            } else {
                wp_clear_scheduled_hook($hook);
            }
            ++$report['cron_hooks_cleared'];
        }
        $report['files_removed'] = $this->remove_static_root();
        foreach (self::TABLE_SUFFIXES as $suffix) {
            $table = $this->wpdb->prefix . $suffix;
            if (preg_match('/\A[A-Za-z0-9_]+\z/', $table) && false !== $this->wpdb->query('DROP TABLE IF EXISTS `' . $table . '`')) {
                ++$report['tables_dropped'];
            }
        }
        $report['options_deleted'] = $this->delete_options();
        $report['post_meta_deleted'] = $this->delete_post_meta();
        return $report;
    }

    /** Removes the post meta keys of POST_META_KEYS from every content; other meta is not touched. */
    private function delete_post_meta()
    {
        $deleted = 0;
        foreach (self::POST_META_KEYS as $key) {
            if (function_exists('delete_post_meta_by_key')) {
                // Also clears the meta cache of each content.
                $deleted += delete_post_meta_by_key($key) ? 1 : 0;
            } elseif (isset($this->wpdb->postmeta) && preg_match('/\A[A-Za-z0-9_]+\z/', (string) $this->wpdb->postmeta)
                && false !== $this->wpdb->query($this->wpdb->prepare("DELETE FROM {$this->wpdb->postmeta} WHERE meta_key = %s", $key))) {
                ++$deleted;
            }
        }
        return $deleted;
    }

    private function delete_options()
    {
        $like = $this->wpdb->esc_like(self::OPTION_PREFIX) . '%';
        $transient_like = $this->wpdb->esc_like('_transient_' . self::OPTION_PREFIX) . '%';
        $names = $this->wpdb->get_col($this->wpdb->prepare(
            "SELECT option_name FROM {$this->wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
            $like,
            $transient_like
        ));
        $deleted = 0;
        foreach (is_array($names) ? $names : array() as $name) {
            $name = (string) $name;
            if (0 === strpos($name, '_transient_timeout_' . self::OPTION_PREFIX)) {
                continue;
            }
            if (0 === strpos($name, '_transient_' . self::OPTION_PREFIX)) {
                // delete_transient() also removes the timeout row and the object cache entry.
                delete_transient(substr($name, strlen('_transient_')));
                ++$deleted;
            } elseif (0 === strpos($name, self::OPTION_PREFIX) && delete_option($name)) {
                ++$deleted;
            }
        }
        foreach (self::OPTIONS as $name) {
            if (delete_option($name)) {
                ++$deleted;
            }
        }
        return $deleted;
    }

    private function remove_htaccess_blocks()
    {
        $htaccess = $this->abspath . '.htaccess';
        if (is_link($htaccess) || !is_file($htaccess) || !is_readable($htaccess) || !is_writable($htaccess)) {
            return false;
        }
        $contents = file_get_contents($htaccess);
        if (false === $contents) {
            return false;
        }
        // Same patterns the plugin uses when it rewrites its own blocks.
        $cleaned = preg_replace('~^# BEGIN Static Bridge Archive Protection\R.*?^# END Static Bridge Archive Protection\h*(?:\R\h*(?=\R|$))*\R?~ms', '', $contents, 1);
        $cleaned = preg_replace('~^# BEGIN Static Bridge\R.*?^# END Static Bridge\h*(?:\R\h*(?=\R|$))*\R?~ms', '', (string) $cleaned, 1);
        // The compression and file-cache block of the Server tab (2.3.0).
        $cleaned = preg_replace('~^# BEGIN StaticBridge-Server\R.*?^# END StaticBridge-Server\h*(?:\R\h*(?=\R|$))*\R?~ms', '', (string) $cleaned, 1);
        if (!is_string($cleaned) || $cleaned === $contents) {
            return is_string($cleaned);
        }
        $temporary = $htaccess . '.static-bridge-uninstall-' . bin2hex(random_bytes(4)) . '.tmp';
        if (file_exists($temporary) || is_link($temporary) || strlen($cleaned) !== file_put_contents($temporary, $cleaned, LOCK_EX)) {
            @unlink($temporary);
            return false;
        }
        $mode = fileperms($htaccess);
        if (false !== $mode) {
            @chmod($temporary, $mode & 0777);
        }
        if (!@rename($temporary, $htaccess)) {
            @unlink($temporary);
            return false;
        }
        return true;
    }

    /** Static Bridge's own object-cache.php only, recognised by its signature; any other drop-in stays. */
    private function remove_object_cache_dropin()
    {
        $dropin = $this->content_dir . '/object-cache.php';
        if (is_link($dropin) || !is_file($dropin)) {
            return false;
        }
        $head = @file_get_contents($dropin, false, null, 0, 2048);
        if (!is_string($head) || false === strpos($head, 'Static-Bridge-Object-Cache-Dropin')) {
            return false;
        }
        return @unlink($dropin);
    }

    private function remove_static_root()
    {
        $content_real = realpath($this->content_dir);
        $root = $this->content_dir . '/static-bridge';
        // Refuse a symlinked or relocated root: its target is not ours to delete.
        if (!$content_real || is_link($root) || !is_dir($root) || realpath($root) !== $content_real . '/static-bridge') {
            return false;
        }
        // Stop static routing before deleting anything the server may be serving.
        foreach (array('.enabled', '.strict') as $marker) {
            if (is_file($root . '/' . $marker) || is_link($root . '/' . $marker)) {
                @unlink($root . '/' . $marker);
            }
        }
        try {
            $entries = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($entries as $entry) {
                $path = $entry->getPathname();
                if (is_link($path) || !$entry->isDir()) {
                    // Symlinks are removed as links; their targets are never touched.
                    if (!@unlink($path)) {
                        return false;
                    }
                } elseif (!@rmdir($path)) {
                    return false;
                }
            }
        } catch (Exception $exception) {
            return false;
        }
        return @rmdir($root);
    }
}
