<?php
/**
 * WP-CLI commands of Static Bridge (2.5.0): `wp static-bridge status|export|push`.
 *
 * Loaded by static-bridge.php only in a WP-CLI process. The commands call the public cli_* methods of
 * Static_Bridge_Plugin, which run the same code as the Generate tab and the push of the editor.
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Status, full export and push of one content from the command line.
 */
final class Static_Bridge_CLI
{
    /** Longest pause between two steps of a synchronous export: the lease of the background requests lasts 90 s. */
    const MAX_STEP_PAUSE = 30;
    /** Seconds between two reads of the state of a push. */
    const PUSH_POLL_INTERVAL = 2;
    /** Default --timeout of `push`, in seconds. */
    const PUSH_DEFAULT_TIMEOUT = 600;

    private $plugin;
    /** callable(int $seconds): the pause between steps; tests replace sleep(). */
    private $sleeper;
    /** Set by SIGINT or SIGTERM during a synchronous export. */
    private $interrupted = false;
    /** True while a synchronous export runs in this process: on shutdown it is handed to the background. */
    private $driving = false;

    public function __construct($plugin, $sleeper = null)
    {
        $this->plugin = $plugin;
        $this->sleeper = is_callable($sleeper) ? $sleeper : function ($seconds) {
            sleep(max(0, (int) $seconds));
        };
    }

    /** Registers `wp static-bridge`. */
    public static function register($plugin)
    {
        if (class_exists('WP_CLI') && method_exists('WP_CLI', 'add_command')) {
            WP_CLI::add_command('static-bridge', new self($plugin));
        }
    }

    /**
     * Shows the release in use, the last full export, the incremental queue, the last link check and the next
     * scheduled export.
     *
     * ## OPTIONS
     *
     * [--format=<format>]
     * : Output format.
     * ---
     * default: table
     * options:
     *   - table
     *   - json
     * ---
     *
     * ## EXAMPLES
     *
     *     wp static-bridge status
     *     wp static-bridge status --format=json
     */
    public function status($args, $assoc_args)
    {
        $format = isset($assoc_args['format']) ? (string) $assoc_args['format'] : 'table';
        if (!in_array($format, array('table', 'json'), true)) {
            WP_CLI::error(sprintf(/* translators: %s: output format */ __('Formato non valido: %s. Usa table o json.', 'static-bridge'), $format));
            return;
        }
        $status = $this->plugin->cli_status();
        if ('json' === $format) {
            WP_CLI::line((string) wp_json_encode($status, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            return;
        }
        $rows = $this->status_rows($status);
        if (function_exists('WP_CLI\\Utils\\format_items')) {
            \WP_CLI\Utils\format_items('table', $rows, array('field', 'value'));
            return;
        }
        foreach ($rows as $row) {
            WP_CLI::line($row['field'] . ': ' . $row['value']);
        }
    }

    /**
     * Starts a full export and, by default, runs it in this process until it is published or fails.
     *
     * Exits with 0 when the new release is published, 1 otherwise. Ctrl-C hands the export to the background
     * requests and WP-Cron, as closing the wp-admin tab does.
     *
     * ## OPTIONS
     *
     * [--background]
     * : Only start the export; the background requests and WP-Cron finish it.
     *
     * ## EXAMPLES
     *
     *     wp static-bridge export
     *     wp static-bridge export --background
     */
    public function export($args, $assoc_args)
    {
        $background = !empty($assoc_args['background']);
        $started = $this->plugin->cli_start_export($background);
        if (is_wp_error($started)) {
            WP_CLI::error($started->get_error_message());
            return;
        }
        if ($background) {
            WP_CLI::success(__('Export completo avviato: lo finiscono le richieste di fondo e WP-Cron. Seguilo con «wp static-bridge status».', 'static-bridge'));
            return;
        }
        WP_CLI::log(__('Export completo avviato; questo processo lo porta avanti. Ctrl-C lo lascia alle richieste di fondo.', 'static-bridge'));
        $this->drive_export();
    }

    /**
     * Updates the static copy of one published content, as the push of the editor does.
     *
     * Exits with 1 when the push fails, or is not done within --timeout.
     *
     * ## OPTIONS
     *
     * <id>
     * : ID of the content.
     *
     * [--[no-]wait]
     * : Wait for the result (default). With --no-wait the push is queued and the command ends.
     *
     * [--timeout=<seconds>]
     * : Longest wait for the result, in seconds.
     * ---
     * default: 600
     * ---
     *
     * ## EXAMPLES
     *
     *     wp static-bridge push 42
     *     wp static-bridge push 42 --no-wait
     */
    public function push($args, $assoc_args)
    {
        $post_id = isset($args[0]) && is_scalar($args[0]) && ctype_digit((string) $args[0]) ? (int) $args[0] : 0;
        if ($post_id < 1) {
            WP_CLI::error(__('Indica un ID contenuto valido.', 'static-bridge'));
            return;
        }
        $wait = !array_key_exists('wait', $assoc_args) || !empty($assoc_args['wait']);
        $timeout = isset($assoc_args['timeout']) && is_scalar($assoc_args['timeout']) && ctype_digit((string) $assoc_args['timeout'])
            ? (int) $assoc_args['timeout'] : self::PUSH_DEFAULT_TIMEOUT;
        $push = $this->plugin->cli_push_post($post_id);
        if (is_wp_error($push)) {
            WP_CLI::error($push->get_error_message());
            return;
        }
        $deadline = time() + $timeout;
        while ($wait && in_array($push['status'], array('queued', 'retrying'), true) && time() < $deadline) {
            // No visitor starts WP-Cron for this process: a retry that is due runs here.
            $this->run_due_incremental_worker();
            $push = array_merge($push, $this->plugin->cli_push_status($push['token']));
            if (in_array($push['status'], array('queued', 'retrying'), true)) {
                call_user_func($this->sleeper, self::PUSH_POLL_INTERVAL);
                $push = array_merge($push, $this->plugin->cli_push_status($push['token']));
            }
        }
        if ('completed' === $push['status']) {
            WP_CLI::success(sprintf(/* translators: %s: page path */ __('Copia statica di %s aggiornata e pubblicata.', 'static-bridge'), $push['path']));
            return;
        }
        if ('failed' === $push['status']) {
            WP_CLI::error(sprintf(/* translators: %s: error message */ __('Push non completato: %s', 'static-bridge'), '' !== $push['last_error'] ? $push['last_error'] : __('verifica la route e i log di Static Bridge.', 'static-bridge')));
            return;
        }
        if (!$wait) {
            WP_CLI::success(sprintf(/* translators: %s: page path */ __('Push di %s in coda: la copia esistente resta online finché la nuova non è scritta.', 'static-bridge'), $push['path']));
            return;
        }
        WP_CLI::error(sprintf(
            /* translators: 1: page path, 2: number of seconds */
            __('Il push di %1$s non è concluso dopo %2$s secondi: resta in coda e la copia esistente resta online. Controlla con «wp static-bridge status».', 'static-bridge'),
            $push['path'],
            number_format_i18n($timeout)
        ));
    }

    /** The steps of a synchronous export until a final state; exits 0 only when the release is published. */
    private function drive_export()
    {
        $this->driving = true;
        $this->listen_for_interrupt();
        register_shutdown_function(array($this, 'hand_off_on_shutdown'));
        $last_line = '';
        while (true) {
            if ($this->interrupted) {
                $this->driving = false;
                $this->plugin->cli_hand_export_to_background();
                WP_CLI::error(__('Interrotto: l’export continua con le richieste di fondo e WP-Cron. Seguilo con «wp static-bridge status».', 'static-bridge'));
                return;
            }
            $state = $this->plugin->cli_export_step();
            $line = $this->progress_line($state);
            if ($line !== $last_line) {
                WP_CLI::log($line);
                $last_line = $line;
            }
            $status = isset($state['status']) ? (string) $state['status'] : 'idle';
            if ('running' !== $status) {
                $this->driving = false;
                break;
            }
            $pause = min(self::MAX_STEP_PAUSE, max(0, (int) ($state['pause'] ?? 0)));
            if ($pause > 0) {
                call_user_func($this->sleeper, $pause);
            }
        }
        if ('complete' === $status) {
            WP_CLI::success('' !== (string) ($state['message'] ?? '') ? (string) $state['message'] : __('Versione pubblicata.', 'static-bridge'));
            return;
        }
        $issues = isset($state['issues']) && is_array($state['issues']) ? array_values($state['issues']) : array();
        $last_issue = empty($issues) ? null : $issues[count($issues) - 1];
        $reason = is_array($last_issue) && isset($last_issue['message']) ? (string) $last_issue['message'] : '';
        WP_CLI::error(sprintf(
            /* translators: 1: final status of the export, 2: reason */
            __('Export non pubblicato (%1$s). %2$s', 'static-bridge'),
            $status,
            $reason
        ));
    }

    /** Shutdown of the WP-CLI process while a synchronous export runs (a fatal error, an exit): hand it off. */
    public function hand_off_on_shutdown()
    {
        if ($this->driving) {
            $this->driving = false;
            $this->plugin->cli_hand_export_to_background();
        }
    }

    /** With pcntl, Ctrl-C and SIGTERM end the export cleanly between two steps; without, the lease expires. */
    private function listen_for_interrupt()
    {
        if (!function_exists('pcntl_signal') || !function_exists('pcntl_async_signals') || !defined('SIGINT')) {
            return;
        }
        pcntl_async_signals(true);
        $handler = function () {
            $this->interrupted = true;
        };
        pcntl_signal(SIGINT, $handler);
        if (defined('SIGTERM')) {
            pcntl_signal(SIGTERM, $handler);
        }
    }

    /** One line of progress: processed / total, saved pages, errors and the page in progress. */
    private function progress_line($state)
    {
        $line = sprintf(
            /* translators: 1: processed URLs, 2: total URLs, 3: saved pages, 4: URLs with errors */
            __('%1$s / %2$s URL · salvate %3$s · errori %4$s', 'static-bridge'),
            number_format_i18n((int) ($state['processed'] ?? 0)),
            number_format_i18n((int) ($state['total'] ?? 0)),
            number_format_i18n((int) ($state['saved'] ?? 0)),
            number_format_i18n((int) ($state['routeErrors'] ?? 0))
        );
        if (!empty($state['waiting'])) {
            $line .= ' · ' . sprintf(/* translators: %s: number of seconds */ __('attesa chiesta dal server: %s s', 'static-bridge'), number_format_i18n((int) $state['waiting']));
        } elseif (!empty($state['workerBusy'])) {
            $line .= ' · ' . __('un altro worker sta lavorando: aspetto il suo turno', 'static-bridge');
        } elseif (!empty($state['currentUrl'])) {
            $line .= ' · ' . (string) $state['currentUrl'];
        }
        return $line;
    }

    /** The incremental worker, when WP-Cron has it due: a push in retry would otherwise wait for a visit. */
    private function run_due_incremental_worker()
    {
        $hook = 'static_bridge_process_incremental_queue';
        $next = wp_next_scheduled($hook);
        if (false === $next || (int) $next > time()) {
            return;
        }
        if (function_exists('wp_unschedule_event')) {
            wp_unschedule_event((int) $next, $hook);
        }
        do_action($hook);
    }

    /** The rows of `status` as a table: field and value, dates in the site's time zone. */
    private function status_rows($status)
    {
        $date = function ($timestamp) {
            return (int) $timestamp > 0 ? wp_date('Y-m-d H:i', (int) $timestamp) : '—';
        };
        $release = $status['release'];
        $export = $status['export'];
        $incremental = $status['incremental'];
        $schedule = $status['schedule'];
        $frequencies = array(
            'off' => __('spento', 'static-bridge'),
            'daily' => __('ogni giorno', 'static-bridge'),
            'weekly' => __('ogni settimana', 'static-bridge'),
        );
        $rows = array(
            array('field' => __('Versione del plugin', 'static-bridge'), 'value' => (string) $status['version']),
            array('field' => __('Release in uso', 'static-bridge'), 'value' => '' !== $release['id']
                ? sprintf(/* translators: 1: release id, 2: number of pages, 3: date */ __('%1$s (%2$s pagine, pubblicata %3$s)', 'static-bridge'), $release['id'], number_format_i18n($release['pages']), $date($release['published_at']))
                : __('nessuna', 'static-bridge')),
            array('field' => __('Ultimo export', 'static-bridge'), 'value' => $export['status'] . ('running' === $export['status'] && !$export['alive'] ? ' ' . __('(fermo: verrà chiuso)', 'static-bridge') : '')
                . ('' !== $export['started_by'] ? ' · ' . sprintf(/* translators: %s: who started the export */ __('avviato da %s', 'static-bridge'), $export['started_by']) : '')),
            array('field' => __('Pagine salvate / errori', 'static-bridge'), 'value' => number_format_i18n($export['saved']) . ' / ' . number_format_i18n($export['errors'])),
            array('field' => __('URL elaborate', 'static-bridge'), 'value' => number_format_i18n($export['processed']) . ' / ' . number_format_i18n($export['total'])),
            array('field' => __('Inizio / fine', 'static-bridge'), 'value' => $date($export['started_at']) . ' / ' . $date($export['finished_at'])),
        );
        if ('' !== $export['message']) {
            $rows[] = array('field' => __('Esito', 'static-bridge'), 'value' => $export['message']);
        }
        $rows[] = array('field' => __('Coda incrementale', 'static-bridge'), 'value' => !$incremental['enabled']
            ? __('aggiornamenti incrementali spenti', 'static-bridge')
            : sprintf(/* translators: 1: queued routes, 2: routes in retry */ __('%1$s voci, %2$s in retry', 'static-bridge'), number_format_i18n($incremental['items']), number_format_i18n($incremental['retrying']))
                . ($incremental['wait_until'] > 0 ? ' · ' . sprintf(/* translators: %s: date and time */ __('in attesa fino alle %s', 'static-bridge'), $date($incremental['wait_until'])) : ''));
        $link_check = $status['link_check'] ?? array('status' => 'none');
        $rows[] = array('field' => __('Controllo dei link', 'static-bridge'), 'value' => 'none' === $link_check['status']
            ? __('mai eseguito', 'static-bridge')
            : sprintf(
                /* translators: 1: state of the check, 2: number of routes, 3: release id */
                __('%1$s · %2$s rotte linkate senza copia statica · release %3$s', 'static-bridge'),
                $link_check['status'],
                number_format_i18n($link_check['broken']) . (!empty($link_check['capped']) ? '+' : ''),
                $link_check['release']
            ));
        $basic_auth = array(
            'on' => __('attiva', 'static-bridge'),
            'off' => __('spenta', 'static-bridge'),
            'invalid' => __('costanti non valide', 'static-bridge'),
        );
        $rows[] = array('field' => __('Basic Auth', 'static-bridge'), 'value' => $basic_auth[$status['basic_auth'] ?? 'off'] ?? $basic_auth['off']);
        $rows[] = array('field' => __('Export pianificato', 'static-bridge'), 'value' => ($frequencies[$schedule['frequency']] ?? $schedule['frequency'])
            . ($schedule['next'] > 0 ? ' · ' . sprintf(/* translators: %s: date and time */ __('prossimo %s', 'static-bridge'), $date($schedule['next'])) : ''));
        return $rows;
    }
}
