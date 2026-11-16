<?php
/**
 * Persistent route/dependency index and bounded refresh queue for Static Bridge V2.
 *
 * This module is intentionally conservative: it indexes rendered Elementor
 * document references only. Callers must use a full shared-route refresh when
 * the index is missing, incomplete, or does not contain the requested document.
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('Static_Bridge_Header_Fragments', false)
    && is_readable(__DIR__ . '/header-fragments.php')) {
    require_once __DIR__ . '/header-fragments.php';
}

final class Static_Bridge_Dependency_Index
{
    const STATE_SUFFIX = 'static_bridge_v2_state';
    const ROUTES_SUFFIX = 'static_bridge_v2_routes';
    const EDGES_SUFFIX = 'static_bridge_v2_edges';
    const TABLE_SCHEMA_VERSION = 1;

    private static $verified_schemas = array();

    private $wpdb;
    private $state_table;
    private $routes_table;
    private $edges_table;

    public function __construct($wpdb = null)
    {
        if (null === $wpdb && isset($GLOBALS['wpdb'])) {
            $wpdb = $GLOBALS['wpdb'];
        }
        $this->wpdb = is_object($wpdb) ? $wpdb : null;
        $prefix = $this->wpdb && isset($this->wpdb->prefix) ? (string) $this->wpdb->prefix : '';
        $safe_prefix = preg_match('/\A[A-Za-z0-9_]+\z/', $prefix) ? $prefix : '';
        $this->state_table = '' !== $safe_prefix ? $safe_prefix . self::STATE_SUFFIX : '';
        $this->routes_table = '' !== $safe_prefix ? $safe_prefix . self::ROUTES_SUFFIX : '';
        $this->edges_table = '' !== $safe_prefix ? $safe_prefix . self::EDGES_SUFFIX : '';
    }

    public function install()
    {
        if (!$this->wpdb || '' === $this->state_table || !method_exists($this->wpdb, 'get_charset_collate')
            || !method_exists($this->wpdb, 'prepare') || !method_exists($this->wpdb, 'get_var')) {
            return false;
        }
        $cache_key = hash('sha256', implode("\0", array(
            self::TABLE_SCHEMA_VERSION,
            $this->state_table,
            $this->routes_table,
            $this->edges_table,
        )));
        if (isset(self::$verified_schemas[$cache_key]) && true === self::$verified_schemas[$cache_key]) {
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
        $sql = array(
            "CREATE TABLE {$this->state_table} (
                release_id varchar(100) NOT NULL,
                release_key char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                status varchar(16) NOT NULL DEFAULT 'building',
                expected_routes bigint(20) unsigned NOT NULL DEFAULT 0,
                indexed_routes bigint(20) unsigned NOT NULL DEFAULT 0,
                error_count bigint(20) unsigned NOT NULL DEFAULT 0,
                updated_at datetime NOT NULL,
                PRIMARY KEY  (release_id),
                UNIQUE KEY release_key (release_key)
            ) ENGINE=InnoDB {$collate};",
            "CREATE TABLE {$this->routes_table} (
                release_key char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                route_hash char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                route_path text NOT NULL,
                url text NOT NULL,
                html_hash char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                indexed tinyint(1) NOT NULL DEFAULT 0,
                queue_status varchar(12) NOT NULL DEFAULT '',
                queue_action varchar(12) NOT NULL DEFAULT 'refresh',
                queue_role varchar(12) NOT NULL DEFAULT 'dependent',
                post_id bigint(20) unsigned NOT NULL DEFAULT 0,
                builders text NOT NULL,
                shared_content tinyint(1) NOT NULL DEFAULT 0,
                queued_at datetime NOT NULL,
                available_at datetime NOT NULL,
                token char(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                attempts int(10) unsigned NOT NULL DEFAULT 0,
                last_error text NOT NULL,
                PRIMARY KEY  (release_key,route_hash),
                KEY queue_status (release_key,queue_status,queued_at)
            ) ENGINE=InnoDB {$collate};",
            "CREATE TABLE {$this->edges_table} (
                release_key char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                route_hash char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                dependency_hash char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                builder varchar(20) NOT NULL,
                dependency_type varchar(32) NOT NULL,
                source_id varchar(64) NOT NULL,
                dependency_id varchar(64) NOT NULL,
                markup_hash char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                PRIMARY KEY  (release_key,route_hash,dependency_hash),
                KEY dep_lookup (release_key,dependency_hash,route_hash)
            ) ENGINE=InnoDB {$collate};",
        );
        foreach ($sql as $statement) {
            dbDelta($statement);
        }

        foreach (array($this->state_table, $this->routes_table, $this->edges_table) as $table) {
            $like = method_exists($this->wpdb, 'esc_like') ? $this->wpdb->esc_like($table) : addcslashes($table, '_%\\');
            if ($table !== $this->wpdb->get_var($this->wpdb->prepare('SHOW TABLES LIKE %s', $like))) {
                return false;
            }
            $engine = $this->wpdb->get_var($this->wpdb->prepare(
                'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s',
                $table
            ));
            if ('InnoDB' !== $engine) {
                return false;
            }
        }
        self::$verified_schemas[$cache_key] = true;
        return true;
    }

    public function begin_release($release_id)
    {
        if (!$this->valid_release_id($release_id) || !$this->install()) {
            return false;
        }
        $release_key = $this->release_key($release_id);
        if (false === $this->wpdb->query($this->wpdb->prepare(
            "DELETE FROM {$this->edges_table} WHERE release_key = %s",
            $release_key
        )) || false === $this->wpdb->query($this->wpdb->prepare(
            "DELETE FROM {$this->routes_table} WHERE release_key = %s",
            $release_key
        ))) {
            return false;
        }
        $sql = $this->wpdb->prepare(
            "INSERT INTO {$this->state_table} (release_id,release_key,status,expected_routes,indexed_routes,error_count,updated_at)
             VALUES (%s,%s,'building',0,0,0,UTC_TIMESTAMP())
             ON DUPLICATE KEY UPDATE release_key=VALUES(release_key),status='building',expected_routes=0,indexed_routes=0,error_count=0,updated_at=UTC_TIMESTAMP()",
            $release_id,
            $release_key
        );
        return false !== $this->wpdb->query($sql);
    }

    /**
     * Store a route and its supported dependencies only after its HTML file has
     * been written successfully. Any failure makes the release non-selective.
     */
    public function index_route($release_id, $route_path, $url, $html)
    {
        if (!$this->valid_release_id($release_id) || !$this->valid_route_path($route_path)
            || !is_string($url) || '' === $url || !is_string($html) || strlen($html) > 5242880
            || !$this->install()) {
            return false;
        }
        $portable_path = $this->portable_route_path($route_path);
        if (!$this->valid_route_path($portable_path)) {
            $this->mark_failed($release_id);
            return false;
        }
        $state = $this->state($release_id);
        if (!is_array($state) || !in_array($state['status'], array('building', 'ready'), true)) {
            return false;
        }
        $parsed = $this->extract_elementor_dependencies($html);
        if (empty($parsed['complete'])) {
            $this->mark_failed($release_id);
            return false;
        }

        $release_key = $this->release_key($release_id);
        $route_hash = hash('sha256', $portable_path);
        $html_hash = hash('sha256', $html);
        $existing_indexed = $this->wpdb->get_var($this->wpdb->prepare(
            "SELECT indexed FROM {$this->routes_table} WHERE release_key = %s AND route_hash = %s",
            $release_key,
            $route_hash
        ));
        if (!empty($this->wpdb->last_error)) {
            $this->mark_failed($release_id);
            return false;
        }

        if (false === $this->wpdb->query('START TRANSACTION')) {
            $this->mark_failed($release_id);
            return false;
        }
        $ok = true;
        $delete = $this->wpdb->prepare(
            "DELETE FROM {$this->edges_table} WHERE release_key = %s AND route_hash = %s",
            $release_key,
            $route_hash
        );
        $ok = false !== $this->wpdb->query($delete);

        $route_sql = $this->wpdb->prepare(
            "INSERT INTO {$this->routes_table}
                (release_key,route_hash,route_path,url,html_hash,indexed,queue_status,queue_action,queue_role,post_id,builders,shared_content,queued_at,available_at,token,attempts,last_error)
             VALUES (%s,%s,%s,%s,%s,1,'','refresh','dependent',0,'[]',0,UTC_TIMESTAMP(),UTC_TIMESTAMP(),%s,0,'')
             ON DUPLICATE KEY UPDATE route_path=VALUES(route_path),url=VALUES(url),html_hash=VALUES(html_hash),indexed=1",
            $release_key,
            $route_hash,
            $portable_path,
            $portable_path,
            $html_hash,
            wp_generate_uuid4()
        );
        $ok = $ok && false !== $this->wpdb->query($route_sql);

        if ($ok && !empty($parsed['dependencies'])) {
            foreach ($parsed['dependencies'] as $dependency_id => $markup_hash) {
                $dependency_hash = $this->dependency_hash('elementor', 'document', 'document', $dependency_id);
                $row_sql = $this->wpdb->prepare(
                    "INSERT IGNORE INTO {$this->edges_table}
                        (release_key,route_hash,dependency_hash,builder,dependency_type,source_id,dependency_id,markup_hash)
                     VALUES (%s,%s,%s,'elementor','document','document',%s,%s)",
                    $release_key,
                    $route_hash,
                    $dependency_hash,
                    $dependency_id,
                    $markup_hash
                );
                if (false === $this->wpdb->query($row_sql)) {
                    $ok = false;
                    break;
                }
            }
        }

        if ($ok && !in_array($existing_indexed, array('1', 1), true)) {
            $ok = false !== $this->wpdb->query($this->wpdb->prepare(
                "UPDATE {$this->state_table} SET indexed_routes=indexed_routes+1,updated_at=UTC_TIMESTAMP() WHERE release_id=%s AND status IN ('building','ready')",
                $release_id
            ));
        }
        if ($ok) {
            $ok = false !== $this->wpdb->query('COMMIT');
        } else {
            $this->wpdb->query('ROLLBACK');
        }
        if (!$ok) {
            $this->mark_failed($release_id);
        }
        return $ok;
    }

    public function finish_release($release_id, $expected_routes)
    {
        if (!$this->valid_release_id($release_id) || !is_numeric($expected_routes) || (int) $expected_routes < 1) {
            return false;
        }
        $state = $this->state($release_id);
        if (!is_array($state) || 'building' !== $state['status']) {
            return false;
        }
        $expected_routes = (int) $expected_routes;
        $actual_routes = (int) $this->wpdb->get_var($this->wpdb->prepare(
            "SELECT COUNT(*) FROM {$this->routes_table} WHERE release_key = %s AND indexed = 1",
            $this->release_key($release_id)
        ));
        if ($expected_routes !== $actual_routes || !empty($this->wpdb->last_error)) {
            $this->mark_failed($release_id);
            return false;
        }
        return false !== $this->wpdb->query($this->wpdb->prepare(
            "UPDATE {$this->state_table} SET status='ready',expected_routes=%d,indexed_routes=%d,updated_at=UTC_TIMESTAMP() WHERE release_id=%s AND status='building'",
            $expected_routes,
            $actual_routes,
            $release_id
        ));
    }

    public function remove_route($release_id, $route_path)
    {
        if (!$this->valid_release_id($release_id) || !$this->valid_route_path($route_path) || !$this->install()) {
            return false;
        }
        $route_path = $this->portable_route_path($route_path);
        $release_key = $this->release_key($release_id);
        $route_hash = hash('sha256', $route_path);
        $route_exists = $this->wpdb->get_var($this->wpdb->prepare(
            "SELECT indexed FROM {$this->routes_table} WHERE release_key=%s AND route_hash=%s",
            $release_key,
            $route_hash
        ));
        if (!empty($this->wpdb->last_error)) {
            return false;
        }
        if (false === $this->wpdb->query('START TRANSACTION')) {
            return false;
        }
        $ok = false !== $this->wpdb->query($this->wpdb->prepare(
            "DELETE FROM {$this->edges_table} WHERE release_key=%s AND route_hash=%s",
            $release_key,
            $route_hash
        ));
        if ($ok) {
            $ok = false !== $this->wpdb->query($this->wpdb->prepare(
                "DELETE FROM {$this->routes_table} WHERE release_key=%s AND route_hash=%s",
                $release_key,
                $route_hash
            ));
            if ($ok && in_array($route_exists, array('1', 1), true)) {
                $this->wpdb->query($this->wpdb->prepare(
                "UPDATE {$this->state_table} SET expected_routes=GREATEST(0,expected_routes-1),indexed_routes=GREATEST(0,indexed_routes-1),updated_at=UTC_TIMESTAMP() WHERE release_id=%s AND status='ready'",
                $release_id
                ));
                $ok = empty($this->wpdb->last_error);
            }
        }
        $this->wpdb->query($ok ? 'COMMIT' : 'ROLLBACK');
        if (!$ok) {
            $this->mark_failed($release_id);
        }
        return $ok;
    }

    public function enqueue_dependency($release_id, $dependency_id)
    {
        if (!$this->is_ready($release_id) || !$this->valid_dependency_id($dependency_id)) {
            return false;
        }
        $dependency_hash = $this->dependency_hash('elementor', 'document', 'document', $dependency_id);
        return $this->enqueue_select($release_id, $dependency_hash);
    }

    public function enqueue_all_routes($release_id)
    {
        if (!$this->is_ready($release_id)) {
            return false;
        }
        return $this->enqueue_select($release_id, '');
    }

    private function enqueue_select($release_id, $dependency_hash)
    {
        $release_key = $this->release_key($release_id);
        if ('' !== $dependency_hash) {
            $count_sql = $this->wpdb->prepare(
                "SELECT COUNT(DISTINCT route_hash) FROM {$this->edges_table} WHERE release_key=%s AND dependency_hash=%s",
                $release_key,
                $dependency_hash
            );
            $count = $this->wpdb->get_var($count_sql);
            $select_sql = $this->wpdb->prepare(
                "SELECT r.release_key,r.route_hash,r.route_path,r.url FROM {$this->routes_table} r
                 INNER JOIN {$this->edges_table} e ON e.release_key=r.release_key AND e.route_hash=r.route_hash
                 WHERE r.release_key=%s AND e.dependency_hash=%s",
                $release_key,
                $dependency_hash
            );
        } else {
            $count = $this->wpdb->get_var($this->wpdb->prepare(
                "SELECT COUNT(*) FROM {$this->routes_table} WHERE release_key=%s AND indexed=1",
                $release_key
            ));
            $select_sql = $this->wpdb->prepare(
                "SELECT release_key,route_hash,route_path,url FROM {$this->routes_table} WHERE release_key=%s AND indexed=1",
                $release_key
            );
        }
        if (!is_numeric($count) || !empty($this->wpdb->last_error)) {
            return false;
        }
        $count = (int) $count;
        if (0 === $count) {
            return 0;
        }
        $insert_sql = "INSERT INTO {$this->routes_table}
            (release_key,route_hash,route_path,url,html_hash,indexed,queue_status,queue_action,queue_role,post_id,builders,shared_content,queued_at,available_at,token,attempts,last_error)
            SELECT q.release_key,q.route_hash,q.route_path,q.url,'',1,'pending','refresh','dependent',0,'[]',1,UTC_TIMESTAMP(),UTC_TIMESTAMP(),UUID(),0,''
            FROM ({$select_sql}) AS q
            ON DUPLICATE KEY UPDATE queue_status='pending',queue_action='refresh',queue_role='dependent',post_id=0,builders='[]',shared_content=1,queued_at=UTC_TIMESTAMP(),available_at=UTC_TIMESTAMP(),token=UUID(),attempts=0,last_error=''";
        if (false === $this->wpdb->query($insert_sql)) {
            $this->mark_failed($release_id);
            return false;
        }
        return $count;
    }

    public function next_pending($release_id)
    {
        if (!$this->is_ready($release_id)) {
            return false;
        }
        $release_key = $this->release_key($release_id);
        $sql = $this->wpdb->prepare(
            "SELECT route_hash,route_path,url,queue_action,queue_role,post_id,builders,shared_content,token,attempts,last_error
             FROM {$this->routes_table}
             WHERE release_key=%s AND ((queue_status='pending' AND available_at <= UTC_TIMESTAMP()) OR (queue_status='running' AND queued_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL 10 MINUTE)))
             ORDER BY queued_at ASC,route_hash ASC LIMIT 1",
            $release_key
        );
        $row = $this->wpdb->get_row($sql, defined('ARRAY_A') ? ARRAY_A : 'ARRAY_A');
        if (null === $row) {
            return empty($this->wpdb->last_error) ? null : false;
        }
        if (!is_array($row) || !preg_match('/\A[a-f0-9]{64}\z/', (string) ($row['route_hash'] ?? ''))
            || empty($row['url']) || !$this->valid_route_path((string) ($row['route_path'] ?? ''))
            || !function_exists('home_url')) {
            return false;
        }
        $token = wp_generate_uuid4();
        $changed = $this->wpdb->query($this->wpdb->prepare(
            "UPDATE {$this->routes_table} SET queue_status='running',queued_at=UTC_TIMESTAMP(),token=%s
             WHERE release_key=%s AND route_hash=%s AND ((queue_status='pending' AND available_at <= UTC_TIMESTAMP()) OR (queue_status='running' AND queued_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL 10 MINUTE)))",
            $token,
            $release_key,
            (string) $row['route_hash']
        ));
        if (1 !== (int) $changed) {
            return null;
        }
        $builders = json_decode((string) $row['builders'], true);
        $portable_path = (string) $row['route_path'];
        $url = home_url($portable_path);
        $full_path = function_exists('wp_parse_url') ? wp_parse_url($url, PHP_URL_PATH) : parse_url($url, PHP_URL_PATH);
        if (!is_string($url) || !$this->valid_route_path($full_path)) {
            return false;
        }
        return array(
            'url' => $url,
            'path' => $full_path,
            'action' => 'delete' === $row['queue_action'] ? 'delete' : 'refresh',
            'role' => 'single' === $row['queue_role'] ? 'single' : 'dependent',
            'post_id' => absint($row['post_id']),
            'builders' => is_array($builders) ? $builders : array(),
            'shared_content' => (int) $row['shared_content'],
            'token' => $token,
            'attempts' => absint($row['attempts']),
            'last_error' => sanitize_text_field((string) $row['last_error']),
            '_queue_backend' => 'dependency-index',
            '_route_hash' => (string) $row['route_hash'],
            '_release_id' => (string) $release_id,
        );
    }

    public function complete_queue_item($release_id, $item)
    {
        if (!$this->valid_release_id($release_id) || !is_array($item) || empty($item['_route_hash']) || empty($item['token'])) {
            return false;
        }
        return 1 === (int) $this->wpdb->query($this->wpdb->prepare(
            "UPDATE {$this->routes_table} SET queue_status='',queue_action='refresh',queue_role='dependent',post_id=0,builders='[]',shared_content=0,attempts=0,last_error=''
             WHERE release_key=%s AND route_hash=%s AND queue_status='running' AND token=%s",
            $this->release_key($release_id),
            (string) $item['_route_hash'],
            (string) $item['token']
        ));
    }

    public function retry_queue_item($release_id, $item, $message, $delay = 15)
    {
        if (!$this->valid_release_id($release_id) || !is_array($item) || empty($item['_route_hash']) || empty($item['token'])) {
            return false;
        }
        return 1 === (int) $this->wpdb->query($this->wpdb->prepare(
            "UPDATE {$this->routes_table} SET queue_status='pending',queued_at=UTC_TIMESTAMP(),available_at=DATE_ADD(UTC_TIMESTAMP(),INTERVAL %d SECOND),attempts=attempts+1,last_error=%s
             WHERE release_key=%s AND route_hash=%s AND queue_status='running' AND token=%s",
            max(0, min(3600, (int) $delay)),
            sanitize_text_field((string) $message),
            $this->release_key($release_id),
            (string) $item['_route_hash'],
            (string) $item['token']
        ));
    }

    public function pending_count($release_id)
    {
        if (!$this->valid_release_id($release_id) || !$this->wpdb) {
            return false;
        }
        $state = $this->state($release_id);
        if (!is_array($state) || 'ready' !== $state['status']) {
            return 0;
        }
        $count = $this->wpdb->get_var($this->wpdb->prepare(
            "SELECT COUNT(*) FROM {$this->routes_table} WHERE release_key=%s AND queue_status IN ('pending','running')",
            $this->release_key($release_id)
        ));
        return is_numeric($count) ? (int) $count : false;
    }

    public function next_pending_delay($release_id)
    {
        if (!$this->valid_release_id($release_id) || !$this->wpdb || !$this->is_ready($release_id)) {
            return false;
        }
        $seconds = $this->wpdb->get_var($this->wpdb->prepare(
            "SELECT MIN(GREATEST(0,TIMESTAMPDIFF(SECOND,UTC_TIMESTAMP(),CASE WHEN queue_status='pending' THEN available_at ELSE DATE_ADD(queued_at,INTERVAL 10 MINUTE) END)))
             FROM {$this->routes_table} WHERE release_key=%s AND queue_status IN ('pending','running')",
            $this->release_key($release_id)
        ));
        return is_numeric($seconds) ? max(0, (int) $seconds) : false;
    }

    public function state($release_id)
    {
        if (!$this->valid_release_id($release_id) || !$this->wpdb) {
            return null;
        }
        $like = method_exists($this->wpdb, 'esc_like')
            ? $this->wpdb->esc_like($this->state_table)
            : addcslashes($this->state_table, '_%\\');
        if ($this->state_table !== $this->wpdb->get_var($this->wpdb->prepare('SHOW TABLES LIKE %s', $like))) {
            return null;
        }
        $row = $this->wpdb->get_row($this->wpdb->prepare(
            "SELECT status,expected_routes,indexed_routes,error_count,updated_at FROM {$this->state_table} WHERE release_id=%s",
            $release_id
        ), defined('ARRAY_A') ? ARRAY_A : 'ARRAY_A');
        return is_array($row) ? $row : null;
    }

    public function is_ready($release_id)
    {
        $state = $this->state($release_id);
        return is_array($state) && 'ready' === $state['status']
            && (int) $state['expected_routes'] > 0
            && (int) $state['expected_routes'] === (int) $state['indexed_routes'];
    }

    public function mark_failed($release_id)
    {
        if (!$this->valid_release_id($release_id) || !$this->install()) {
            return false;
        }
        return false !== $this->wpdb->query($this->wpdb->prepare(
            "UPDATE {$this->state_table} SET status='incomplete',error_count=error_count+1,updated_at=UTC_TIMESTAMP() WHERE release_id=%s AND status<>'incomplete'",
            $release_id
        ));
    }

    /**
     * Release keys that still have rows in any index table. A missing table
     * means "no references"; any query error or malformed key returns false
     * so callers that delete releases can fail closed.
     */
    public function referenced_release_keys()
    {
        if (!$this->wpdb || '' === $this->state_table || !method_exists($this->wpdb, 'get_col')
            || !method_exists($this->wpdb, 'get_var') || !method_exists($this->wpdb, 'prepare')) {
            return false;
        }
        $keys = array();
        foreach (array($this->state_table, $this->routes_table, $this->edges_table) as $table) {
            $like = method_exists($this->wpdb, 'esc_like') ? $this->wpdb->esc_like($table) : addcslashes($table, '_%\\');
            $exists = $this->wpdb->get_var($this->wpdb->prepare('SHOW TABLES LIKE %s', $like));
            if (!empty($this->wpdb->last_error)) {
                return false;
            }
            if ($table !== $exists) {
                continue;
            }
            $column = $this->wpdb->get_col("SELECT DISTINCT release_key FROM {$table}");
            if (!is_array($column) || !empty($this->wpdb->last_error)) {
                return false;
            }
            foreach ($column as $key) {
                if (!is_string($key) || !preg_match('/\A[a-f0-9]{64}\z/D', $key)) {
                    return false;
                }
                $keys[$key] = true;
            }
        }
        return array_keys($keys);
    }

    public function purge_except($release_id)
    {
        if (!$this->valid_release_id($release_id) || !$this->install()) {
            return false;
        }
        $rows = $this->wpdb->get_results($this->wpdb->prepare(
            "SELECT release_id FROM {$this->state_table} WHERE release_id<>%s",
            $release_id
        ), defined('ARRAY_A') ? ARRAY_A : 'ARRAY_A');
        if (!is_array($rows) || !empty($this->wpdb->last_error)) {
            return false;
        }
        foreach ($rows as $row) {
            if (!is_array($row) || empty($row['release_id']) || !$this->delete_release((string) $row['release_id'])) {
                return false;
            }
        }
        return true;
    }

    public function delete_release($release_id)
    {
        if (!$this->valid_release_id($release_id) || !$this->install()) {
            return false;
        }
        $release_key = $this->release_key($release_id);
        if (false === $this->wpdb->query('START TRANSACTION')) {
            return false;
        }
        $ok = false !== $this->wpdb->query($this->wpdb->prepare(
            "DELETE FROM {$this->edges_table} WHERE release_key=%s",
            $release_key
        ));
        $ok = $ok && false !== $this->wpdb->query($this->wpdb->prepare(
            "DELETE FROM {$this->routes_table} WHERE release_key=%s",
            $release_key
        ));
        $ok = $ok && false !== $this->wpdb->query($this->wpdb->prepare(
            "DELETE FROM {$this->state_table} WHERE release_id=%s",
            $release_id
        ));
        $this->wpdb->query($ok ? 'COMMIT' : 'ROLLBACK');
        return $ok;
    }

    private function extract_elementor_dependencies($html)
    {
        if (!class_exists('Static_Bridge_Header_Fragments', false)
            || !method_exists('Static_Bridge_Header_Fragments', 'extract_elementor_document_references')) {
            return array('complete' => false, 'dependencies' => array());
        }
        $parsed = Static_Bridge_Header_Fragments::extract_elementor_document_references($html);
        if (empty($parsed['complete'])) {
            return array('complete' => false, 'dependencies' => array());
        }
        foreach ((array) $parsed['dependencies'] as $dependency_id => $markup_hash) {
            if (!$this->valid_dependency_id((string) $dependency_id)
                || !is_string($markup_hash) || !preg_match('/\A[a-f0-9]{64}\z/D', $markup_hash)) {
                return array('complete' => false, 'dependencies' => array());
            }
        }
        return $parsed;
    }

    private function dependency_hash($builder, $type, $source, $id)
    {
        return hash('sha256', strtolower((string) $builder) . "\0" . strtolower((string) $type) . "\0" . (string) $source . "\0" . (string) $id);
    }

    private function release_key($release_id)
    {
        return hash('sha256', (string) $release_id);
    }

    private function portable_route_path($route_path)
    {
        if (!$this->valid_route_path($route_path) || !function_exists('home_url')) {
            return '';
        }
        $home_path = function_exists('wp_parse_url')
            ? wp_parse_url(home_url('/'), PHP_URL_PATH)
            : parse_url(home_url('/'), PHP_URL_PATH);
        $home_path = is_string($home_path) ? '/' . trim($home_path, '/') : '/';
        if ('/' !== $home_path) {
            if ($route_path === $home_path || $route_path === trailingslashit($home_path)) {
                $route_path = '/';
            } elseif (0 === strpos($route_path, trailingslashit($home_path))) {
                $route_path = '/' . substr($route_path, strlen(trailingslashit($home_path)));
            } else {
                return '';
            }
        }
        $route_path = '/' . ltrim($route_path, '/');
        return $this->valid_route_path($route_path) ? $route_path : '';
    }

    private function valid_release_id($release_id)
    {
        return is_string($release_id) && (bool) preg_match('/\A[A-Za-z0-9_-]{1,100}\z/D', $release_id);
    }

    private function valid_route_path($path)
    {
        return is_string($path) && strlen($path) <= 2048 && '/' === substr($path, 0, 1)
            && false === strpos($path, "\0") && false === strpos($path, '..');
    }

    private function valid_dependency_id($id)
    {
        return is_string($id) && (bool) preg_match('/\A[A-Za-z0-9_-]{1,64}\z/D', $id);
    }
}
