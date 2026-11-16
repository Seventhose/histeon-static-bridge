<?php
/**
 * Small, indexed queue used by long Static Bridge exports.
 *
 * This is intentionally separate from the job option: thousands of URLs must
 * not be serialized into wp_options on every worker request.
 */

if (!defined('ABSPATH')) {
    exit;
}

final class Static_Bridge_Export_Queue
{
    const TABLE_SUFFIX = 'static_bridge_export_urls';
    const BATCH_SIZE = 100;

    private $wpdb;
    private $table;

    public function __construct($wpdb = null)
    {
        if (null === $wpdb && isset($GLOBALS['wpdb'])) {
            $wpdb = $GLOBALS['wpdb'];
        }
        $this->wpdb = is_object($wpdb) ? $wpdb : null;
        $prefix = $this->wpdb && isset($this->wpdb->prefix) ? (string) $this->wpdb->prefix : '';
        $this->table = preg_match('/^[A-Za-z0-9_]+$/', $prefix)
            ? $prefix . self::TABLE_SUFFIX
            : '';
    }

    public function install()
    {
        if (!$this->wpdb || '' === $this->table || !method_exists($this->wpdb, 'prepare') || !method_exists($this->wpdb, 'get_var')) {
            return false;
        }

        $like = method_exists($this->wpdb, 'esc_like')
            ? $this->wpdb->esc_like($this->table)
            : addcslashes($this->table, '_%\\');
        $exists = $this->wpdb->get_var($this->wpdb->prepare('SHOW TABLES LIKE %s', $like));
        if ($this->table === $exists) {
            return true;
        }
        if (!function_exists('dbDelta')) {
            $upgrade_file = ABSPATH . 'wp-admin/includes/upgrade.php';
            if (!is_file($upgrade_file)) {
                return false;
            }
            require_once $upgrade_file;
        }
        if (!function_exists('dbDelta') || !method_exists($this->wpdb, 'get_charset_collate')) {
            return false;
        }

        $charset_collate = $this->wpdb->get_charset_collate();
        $sql = "CREATE TABLE {$this->table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            job_id char(36) NOT NULL,
            url_hash char(40) NOT NULL,
            url text NOT NULL,
            status varchar(12) NOT NULL DEFAULT 'pending',
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY job_hash (job_id,url_hash),
            KEY job_status_id (job_id,status,id)
        ) {$charset_collate};";
        dbDelta($sql);

        $exists = $this->wpdb->get_var($this->wpdb->prepare('SHOW TABLES LIKE %s', $like));
        return $this->table === $exists;
    }

    public function enqueue_batch($job_id, array $urls)
    {
        if (!$this->valid_job_id($job_id) || !$this->wpdb || '' === $this->table || !method_exists($this->wpdb, 'prepare') || !method_exists($this->wpdb, 'query')) {
            return false;
        }

        $rows = array();
        $seen = array();
        foreach ($urls as $url) {
            if (!is_string($url) || strlen($url) > 2048 || !$this->valid_http_url($url)) {
                continue;
            }
            $hash = sha1($url);
            if (isset($seen[$hash])) {
                continue;
            }
            $seen[$hash] = true;
            $rows[] = array($job_id, $hash, $url, 'pending', gmdate('Y-m-d H:i:s'));
        }
        if (empty($rows)) {
            return 0;
        }

        $inserted = 0;
        foreach (array_chunk($rows, self::BATCH_SIZE) as $chunk) {
            $value_sql = array();
            $arguments = array();
            foreach ($chunk as $row) {
                $value_sql[] = '(%s,%s,%s,%s,%s)';
                foreach ($row as $value) {
                    $arguments[] = $value;
                }
            }
            $sql = "INSERT IGNORE INTO {$this->table} (job_id,url_hash,url,status,created_at) VALUES " . implode(',', $value_sql);
            $prepared = $this->wpdb->prepare($sql, $arguments);
            if (!is_string($prepared)) {
                return false;
            }
            $result = $this->wpdb->query($prepared);
            if (false === $result) {
                return false;
            }
            $inserted += (int) $result;
        }
        return $inserted;
    }

    public function next_pending($job_id)
    {
        if (!$this->valid_job_id($job_id) || !$this->wpdb || '' === $this->table || !method_exists($this->wpdb, 'prepare') || !method_exists($this->wpdb, 'get_row')) {
            return false;
        }
        $sql = $this->wpdb->prepare(
            "SELECT id,url_hash,url FROM {$this->table} WHERE job_id = %s AND status = 'pending' ORDER BY id ASC LIMIT 1",
            $job_id
        );
        $row = $this->wpdb->get_row($sql, defined('ARRAY_A') ? ARRAY_A : 'ARRAY_A');
        if (null === $row && !empty($this->wpdb->last_error)) {
            return false;
        }
        return is_array($row) && isset($row['id'], $row['url_hash'], $row['url']) ? $row : null;
    }

    public function next_pending_batch($job_id, $limit = 1)
    {
        if (!$this->valid_job_id($job_id)
            || !$this->wpdb
            || '' === $this->table
            || !method_exists($this->wpdb, 'prepare')
            || !method_exists($this->wpdb, 'get_results')) {
            return false;
        }
        $limit = max(1, min(100, (int) $limit));
        $sql = $this->wpdb->prepare(
            "SELECT id,url_hash,url FROM {$this->table} WHERE job_id = %s AND status = 'pending' ORDER BY id ASC LIMIT %d",
            array($job_id, $limit)
        );
        $rows = $this->wpdb->get_results($sql, defined('ARRAY_A') ? ARRAY_A : 'ARRAY_A');
        if (!is_array($rows) || (empty($rows) && !empty($this->wpdb->last_error))) {
            return false;
        }

        $pending = array();
        foreach ($rows as $row) {
            if (!is_array($row)
                || !isset($row['id'], $row['url_hash'], $row['url'])
                || !is_numeric($row['id'])
                || (int) $row['id'] < 1
                || !is_string($row['url_hash'])
                || !preg_match('/^[a-f0-9]{40}$/', $row['url_hash'])
                || !is_string($row['url'])
                || !$this->valid_http_url($row['url'])) {
                return false;
            }
            $pending[] = array_intersect_key($row, array_flip(array('id', 'url_hash', 'url')));
        }
        return $pending;
    }

    public function mark($job_id, $url_hash, $status)
    {
        if (!$this->valid_job_id($job_id)
            || !is_string($url_hash)
            || !preg_match('/^[a-f0-9]{40}$/', $url_hash)
            || !in_array($status, array('done', 'skipped', 'deferred'), true)
            || !$this->wpdb
            || '' === $this->table
            || !method_exists($this->wpdb, 'update')) {
            return false;
        }
        $updated = $this->wpdb->update(
            $this->table,
            array('status' => $status),
            array('job_id' => $job_id, 'url_hash' => $url_hash, 'status' => 'pending'),
            array('%s'),
            array('%s', '%s', '%s')
        );
        return 1 === (int) $updated;
    }

    public function count_pending($job_id)
    {
        if (!$this->valid_job_id($job_id) || !$this->wpdb || '' === $this->table || !method_exists($this->wpdb, 'prepare') || !method_exists($this->wpdb, 'get_var')) {
            return false;
        }
        $sql = $this->wpdb->prepare("SELECT COUNT(*) FROM {$this->table} WHERE job_id = %s AND status = 'pending'", $job_id);
        $count = $this->wpdb->get_var($sql);
        return is_numeric($count) && (int) $count >= 0 ? (int) $count : false;
    }

    /** URLs the server rate limited (HTTP 429/503) during the export, kept for its slow recovery round. */
    public function count_deferred($job_id)
    {
        if (!$this->valid_job_id($job_id) || !$this->wpdb || '' === $this->table || !method_exists($this->wpdb, 'prepare') || !method_exists($this->wpdb, 'get_var')) {
            return false;
        }
        $sql = $this->wpdb->prepare("SELECT COUNT(*) FROM {$this->table} WHERE job_id = %s AND status = 'deferred'", $job_id);
        $count = $this->wpdb->get_var($sql);
        return is_numeric($count) && (int) $count >= 0 ? (int) $count : false;
    }

    /** Put the deferred URLs of a job back in the queue; the number moved, or false. */
    public function requeue_deferred($job_id)
    {
        if (!$this->valid_job_id($job_id) || !$this->wpdb || '' === $this->table || !method_exists($this->wpdb, 'update')) {
            return false;
        }
        $updated = $this->wpdb->update(
            $this->table,
            array('status' => 'pending'),
            array('job_id' => $job_id, 'status' => 'deferred'),
            array('%s'),
            array('%s', '%s')
        );
        return false === $updated ? false : (int) $updated;
    }

    public function count_total($job_id)
    {
        if (!$this->valid_job_id($job_id) || !$this->wpdb || '' === $this->table || !method_exists($this->wpdb, 'prepare') || !method_exists($this->wpdb, 'get_var')) {
            return false;
        }
        $sql = $this->wpdb->prepare("SELECT COUNT(*) FROM {$this->table} WHERE job_id = %s", $job_id);
        $count = $this->wpdb->get_var($sql);
        return is_numeric($count) && (int) $count >= 0 ? (int) $count : false;
    }

    public function delete_job($job_id)
    {
        if (!$this->valid_job_id($job_id) || !$this->wpdb || '' === $this->table || !method_exists($this->wpdb, 'delete')) {
            return false;
        }
        return false !== $this->wpdb->delete($this->table, array('job_id' => $job_id), array('%s'));
    }

    public function table_name()
    {
        return $this->table;
    }

    private function valid_job_id($job_id)
    {
        return is_string($job_id) && (bool) preg_match('/^[a-f0-9]{8}-(?:[a-f0-9]{4}-){3}[a-f0-9]{12}$/i', $job_id);
    }

    private function valid_http_url($url)
    {
        $parts = function_exists('wp_parse_url') ? wp_parse_url($url) : parse_url($url);
        if (!is_array($parts)) {
            return false;
        }
        if (!isset($parts['scheme'], $parts['host'])
            || !in_array(strtolower($parts['scheme']), array('http', 'https'), true)
            || '' === (string) $parts['host']
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['query'])
            || isset($parts['fragment'])) {
            return false;
        }
        if (!function_exists('home_url') || !function_exists('wp_parse_url')) {
            return true;
        }
        $home = wp_parse_url(home_url('/'));
        if (!is_array($home) || empty($home['scheme']) || empty($home['host'])) {
            return false;
        }
        $scheme = strtolower($parts['scheme']);
        $home_scheme = strtolower($home['scheme']);
        $default_port = 'https' === $scheme ? 443 : 80;
        $candidate_port = isset($parts['port']) ? (int) $parts['port'] : $default_port;
        $home_port = isset($home['port']) ? (int) $home['port'] : ('https' === $home_scheme ? 443 : 80);
        return $scheme === $home_scheme
            && rtrim(strtolower($parts['host']), '.') === rtrim(strtolower($home['host']), '.')
            && $candidate_port === $home_port;
    }
}
