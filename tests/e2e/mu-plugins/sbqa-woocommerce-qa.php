<?php
/**
 * Plugin Name: Static Bridge QA — WooCommerce guard (disposable stack only)
 * Description: Installed only in the wc-nginx/wc-apache QA stacks. Control files live in
 * ../run (outside the document root); without them only the mail sink is active.
 *
 * - Mail sink (always): wp_mail() is logged to run/mail-sink.log and never sent (order e-mails).
 * - run/wc-nonce-life (integer seconds): lifetime of the Store API nonce (action wc_store_api) only.
 *   WordPress accepts a nonce for 1/2 to 1 lifetime, so with 60 a nonce printed in a static page is
 *   rejected about 60 s later: real expiry, not a fake value. Other nonces keep 24 h.
 * - run/wc-foreign-nonce: every single product page prints a nonce of the action woocommerce-sbqa-probe in
 *   a data attribute, like a payment or wishlist plugin whose nonce Static Bridge cannot renew: those
 *   pages must stay with WordPress.
 * - run/wc-ff-footer (2.4.1): every page prints, in the footer, the fluentFormVars that Fluent Forms 6.2.x localizes
 *   for a form in the footer, with its "nonce" = wp_create_nonce() with no action (-1), and a link to
 *   /sbqa-vecchio-indirizzo/, which answers 301 to /shop/ like a snippet that moves an old address. With the content
 *   "elsewhere" the same -1 nonce is also printed in a data attribute on single product pages: that one is not
 *   Fluent Forms' and must keep those pages with WordPress.
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
    if (is_file($run . '/wc-nonce-life')) {
        $life = (int) trim((string) file_get_contents($run . '/wc-nonce-life'));
        if ($life >= 4) {
            add_filter('nonce_life', static function ($default, $action = -1) use ($life) {
                return 'wc_store_api' === $action ? $life : $default;
            }, PHP_INT_MAX, 2);
        }
    }
    if (is_file($run . '/wc-ff-footer')) {
        $elsewhere = 'elsewhere' === trim((string) file_get_contents($run . '/wc-ff-footer'));
        add_action('template_redirect', static function () {
            if ('/sbqa-vecchio-indirizzo/' === (string) wp_parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH)) {
                wp_safe_redirect(home_url('/shop/'), 301);
                exit;
            }
        }, 0);
        add_action('wp_footer', static function () use ($elsewhere) {
            echo '<p class="sbqa-ff-footer"><a href="' . esc_url(home_url('/sbqa-vecchio-indirizzo/')) . '">Autori</a></p>';
            if ($elsewhere && function_exists('is_product') && is_product()) {
                echo '<div class="sbqa-other-nonce" data-nonce="' . esc_attr(wp_create_nonce()) . '"></div>';
            }
            $vars = array('ajaxUrl' => admin_url('admin-ajax.php'), 'forms' => array(), 'step_text' => 'Passo %activeStep% di %totalStep% - %stepTitle%', 'nonce' => wp_create_nonce(), 'file_delete_nonce' => wp_create_nonce('fluentform_file_delete'), 'form_id' => '7', 'step_change_focus' => true);
            echo "<script id=\"fluent-form-submission-js-extra\">\nvar fluentFormVars = " . wp_json_encode($vars) . ";\nvar fluentform_submission_messages_7 = {\"file_upload_in_progress\":\"Attendi\"};\n</script>\n";
        }, 5);
    }
    if (is_file($run . '/wc-foreign-nonce')) {
        add_action('wp_footer', static function () {
            if (function_exists('is_product') && is_product()) {
                echo '<div class="sbqa-foreign-nonce" data-nonce="' . esc_attr(wp_create_nonce('woocommerce-sbqa-probe')) . '"></div>';
            }
        }, 5);
    }
})();
