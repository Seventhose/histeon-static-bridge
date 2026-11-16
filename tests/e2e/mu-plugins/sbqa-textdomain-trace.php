<?php
// QA-only (disposable stack): record the call stack when WordPress flags early static-bridge textdomain loading.
add_action('doing_it_wrong_run', static function ($function, $message) {
    if ('_load_textdomain_just_in_time' !== $function || false === strpos((string) $message, 'static-bridge')) { return; }
    $frames = array();
    foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS) as $f) {
        if (isset($f['file']) && (false !== strpos($f['file'], '/plugins/static-bridge/') || false !== strpos($f['file'], 'wp-settings.php'))) {
            $frames[] = basename($f['file']) . ':' . $f['line'] . ' ' . ($f['class'] ?? '') . ($f['type'] ?? '') . ($f['function'] ?? '');
        }
    }
    file_put_contents(dirname(ABSPATH) . '/run/textdomain-trace.log', strtok((string) ($_SERVER['REQUEST_URI'] ?? '-'), '?') . ' hook=' . (current_filter() ?: '-') . ' did_init=' . did_action('init') . "\n  " . implode("\n  ", $frames) . "\n", FILE_APPEND);
}, 10, 2);
