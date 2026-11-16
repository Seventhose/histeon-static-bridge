<?php
/**
 * Plugin Name: Static Bridge QA — Fluent Forms guard (disposable stack only)
 * Description: Installed only in the ff-nginx/ff-apache QA stacks. Control files live in
 * ../run (outside the document root); without them only the mail sink is active.
 *
 * - Mail sink (always): wp_mail() is logged to run/mail-sink.log and never sent.
 * - run/ff-nonce-life (integer seconds): lifetime of the three Fluent Forms nonce actions only
 *   (token, submit, file delete). WordPress accepts a nonce for 1/2 to 1 lifetime, so with 60 a
 *   nonce printed in a static page is rejected about 60 s later: real expiry, not a fake value.
 *   Admin and other nonces keep the normal 24 h lifetime.
 * - run/ff-nonce-verify: fluentform/nonce_verify returns true (submit nonce is checked).
 * - run/ff-footer-form (form id): that form is printed in wp_footer on every front-end page,
 *   like a form in a global theme footer.
 * - run/ff-drift: token_nonce is removed from fluentFormVars, like a Fluent Forms update that
 *   renames it; Static Bridge must keep such pages dynamic.
 */
if (!defined('ABSPATH')) {
    exit;
}
(static function () {
    $run = dirname(ABSPATH) . '/run';
    add_filter('pre_wp_mail', static function ($short_circuit, $atts) use ($run) {
        $to = isset($atts['to']) ? (is_array($atts['to']) ? implode(',', $atts['to']) : (string) $atts['to']) : '';
        file_put_contents($run . '/mail-sink.log', gmdate('c') . ' to=' . $to . ' subject=' . (isset($atts['subject']) ? (string) $atts['subject'] : '') . "\n", FILE_APPEND);
        return true;
    }, PHP_INT_MAX, 2);
    if (is_file($run . '/ff-nonce-life')) {
        $life = (int) trim((string) file_get_contents($run . '/ff-nonce-life'));
        if ($life >= 4) {
            add_filter('nonce_life', static function ($default, $action = -1) use ($life) {
                return in_array($action, array('fluentform_generate_token_nonce', 'fluentform-submit-form', 'fluentform_file_delete'), true) ? $life : $default;
            }, PHP_INT_MAX, 2);
        }
    }
    if (is_file($run . '/ff-nonce-verify')) {
        add_filter('fluentform/nonce_verify', '__return_true', PHP_INT_MAX);
    }
    if (is_file($run . '/ff-footer-form')) {
        $form_id = (int) trim((string) file_get_contents($run . '/ff-footer-form'));
        add_action('wp_footer', static function () use ($form_id) {
            if ($form_id > 0 && !is_admin()) {
                echo '<div class="sbqa-global-footer-form">' . do_shortcode('[fluentform id="' . $form_id . '"]') . '</div>';
            }
        }, 5);
    }
    if (is_file($run . '/ff-drift')) {
        add_filter('fluentform/global_form_vars', static function ($vars) {
            unset($vars['token_nonce']);
            return $vars;
        }, PHP_INT_MAX);
    }
})();
