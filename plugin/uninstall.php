<?php
/**
 * Runs only when the plugin is deleted from the WordPress Plugins screen.
 * Deactivation keeps settings, queues and static releases; deletion removes them.
 */

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

require_once __DIR__ . '/uninstaller.php';

global $wpdb;
(new Static_Bridge_Uninstaller($wpdb, WP_CONTENT_DIR, ABSPATH))->run();
