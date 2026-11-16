<?php
/**
 * WooCommerce QA helper for the disposable stacks $SBQA_HOME/{wpwc,wpwca} (SBQA_STACK=wc-nginx|wc-apache).
 * Refuses anything but the selected stack's root. PHP 8.4 CLI. No mail leaves the stack (WP_HTTP_BLOCK_EXTERNAL,
 * mail sink of the QA mu-plugin) and no payment provider is involved: only "Cash on delivery" and "Direct bank
 * transfer", which create the order without any request outside the stack.
 * Modes:
 *   activate          activate WooCommerce (copied read-only into the stack by setup-stack.sh); tables, pages, roles.
 *   setup             a small Italian shop: EUR, shipping off, guest checkout, COD and BACS on, store live (no
 *                     "coming soon"); category "Caffè" with 18 simple products (shop pagination), a simple product
 *                     with managed stock, a variable product with two variations, a grouped product; a page
 *                     /vetrina/ with the block mini-cart and a classic product list (AJAX add to cart); a page
 *                     /catalogo-blocchi/ with the All Products block, which prints the Store API and REST nonces.
 *   price <sku> <€>   set the regular price of a product or variation with $product->save(), as the REST API,
 *                     the CSV importer and the admin screen do.
 *   sale <sku> <€>    set a sale price on a product or variation.
 *   stock <sku> <n>   reduce the stock by n with wc_update_product_stock(), as an order does.
 *   option <name> <v> set a store option shown on every page (only woocommerce_currency_pos), as the settings screen does.
 *   termmeta          set the display type of the category "Caffè" (term meta), as the category screen and REST do.
 *   worker            run the Static Bridge incremental worker until its queue is empty.
 *   inspect           versions, page routes, product ids, queue, WooCommerce routes recorded as dynamic, last orders.
 *   cleanup           delete the QA orders and customer sessions.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli' || PHP_VERSION_ID < 80400 || PHP_VERSION_ID >= 80500) {
    fwrite(STDERR, "Run with the PHP 8.4 CLI.\n");
    exit(2);
}
require __DIR__ . '/profile.php';
$envRoot = SBQA_ENV ?: false;
$root = $envRoot ? realpath($envRoot . '/wordpress') : false;
$mode = (string) ($argv[1] ?? '');
$modes = array('activate', 'setup', 'price', 'sale', 'stock', 'option', 'termmeta', 'worker', 'inspect', 'queue', 'cleanup');
if (!$root || !is_file($root . '/wp-load.php') || !in_array($mode, $modes, true) || empty(SBQA_PROFILE['wc'])) {
    fwrite(STDERR, "REFUSED: unknown mode or QA root missing.\n");
    exit(2);
}
$_SERVER['SERVER_SOFTWARE'] = SBQA_PROFILE['server_software'];
$_SERVER['HTTP_HOST'] = '127.0.0.1:' . SBQA_PROFILE['port'];
$_SERVER['SERVER_NAME'] = '127.0.0.1';
$_SERVER['SERVER_PORT'] = (string) SBQA_PROFILE['port'];
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REQUEST_URI'] = '/wp-admin/';
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
if (in_array($mode, array('activate', 'setup'), true)) {
    define('WP_ADMIN', true);
}
define('WP_DISABLE_FATAL_ERROR_HANDLER', true);
register_shutdown_function(static function () {
    $error = error_get_last();
    if ($error && in_array($error['type'], array(E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR), true)) {
        fwrite(STDERR, $error['message'] . ' at ' . $error['file'] . ':' . $error['line'] . "\n");
    }
});
require $root . '/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';
require_once ABSPATH . 'wp-admin/includes/upgrade.php';
$admins = get_users(array('role' => 'administrator', 'number' => 1, 'fields' => 'ID'));
wp_set_current_user((int) $admins[0]);
$out = static function (array $data): void {
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), "\n";
};
$queue = static fn() => (array) get_option(Static_Bridge_Plugin::INCREMENTAL_QUEUE_OPTION, array());
$by_sku = static function (string $sku) {
    $id = function_exists('wc_get_product_id_by_sku') ? (int) wc_get_product_id_by_sku($sku) : 0;
    $product = $id ? wc_get_product($id) : null;
    if (!$product) {
        fwrite(STDERR, "No product with SKU {$sku}.\n");
        exit(2);
    }
    return $product;
};

switch ($mode) {
    case 'activate':
        $activated = activate_plugin('woocommerce/woocommerce.php', '', false, false);
        if (!is_wp_error($activated) && class_exists('WC_Install')) {
            WC_Install::install();
        }
        // For 30 seconds after activation the first admin page goes to the WooCommerce setup wizard: the settings
        // form of Static Bridge would not be found (seen in the release gate, where the steps follow quickly).
        delete_transient('_wc_activation_redirect');
        $out(array('result' => is_wp_error($activated) || !class_exists('WooCommerce') ? 'FAIL' : 'PASS',
            'version' => defined('WC_VERSION') ? WC_VERSION : null, 'error' => is_wp_error($activated) ? $activated->get_error_message() : ''));
        break;

    case 'setup':
        foreach (array(
            'woocommerce_coming_soon' => 'no', 'woocommerce_store_pages_only' => 'no', 'woocommerce_default_country' => 'IT:MI',
            'woocommerce_currency' => 'EUR', 'woocommerce_currency_pos' => 'right_space', 'woocommerce_price_thousand_sep' => '.',
            'woocommerce_price_decimal_sep' => ',', 'woocommerce_price_num_decimals' => '2', 'woocommerce_ship_to_countries' => 'disabled',
            'woocommerce_calc_taxes' => 'no', 'woocommerce_enable_guest_checkout' => 'yes', 'woocommerce_enable_checkout_login_reminder' => 'no',
            'woocommerce_enable_signup_and_login_from_checkout' => 'no', 'woocommerce_manage_stock' => 'yes', 'woocommerce_stock_format' => '',
            'woocommerce_onboarding_profile' => array('skipped' => true), 'woocommerce_task_list_hidden' => 'yes', 'woocommerce_show_marketplace_suggestions' => 'no',
            'woocommerce_allow_tracking' => 'no', 'woocommerce_analytics_enabled' => 'no', 'woocommerce_feature_order_attribution_enabled' => 'no',
            'woocommerce_cod_settings' => array('enabled' => 'yes', 'title' => 'Contrassegno', 'description' => 'Paghi alla consegna.', 'instructions' => '', 'enable_for_methods' => array(), 'enable_for_virtual' => 'yes'),
            'woocommerce_bacs_settings' => array('enabled' => 'yes', 'title' => 'Bonifico bancario', 'description' => 'Bonifico anticipato.', 'instructions' => '', 'account_details' => ''),
        ) as $option => $value) {
            update_option($option, $value);
        }
        $category = term_exists('caffe', 'product_cat') ?: wp_insert_term('Caffè', 'product_cat', array('slug' => 'caffe'));
        $category_id = (int) (is_array($category) ? $category['term_id'] : $category);
        $tag = term_exists('novita', 'product_tag') ?: wp_insert_term('Novità', 'product_tag', array('slug' => 'novita'));
        $tag_id = (int) (is_array($tag) ? $tag['term_id'] : $tag);
        $ids = array();
        $simple = static function (string $sku, string $name, string $price, ?int $stock, array $cats, array $tags = array()) use (&$ids) {
            $existing = (int) wc_get_product_id_by_sku($sku);
            $product = $existing ? wc_get_product($existing) : new WC_Product_Simple();
            $product->set_name($name);
            $product->set_slug(sanitize_title($name));
            $product->set_sku($sku);
            $product->set_regular_price($price);
            $product->set_status('publish');
            $product->set_catalog_visibility('visible');
            $product->set_category_ids($cats);
            $product->set_tag_ids($tags);
            $product->set_manage_stock(null !== $stock);
            if (null !== $stock) {
                $product->set_stock_quantity($stock);
            }
            $product->set_stock_status('instock');
            $product->set_short_description('Prodotto di prova di Static Bridge.');
            $ids[$sku] = $product->save();
            return $ids[$sku];
        };
        for ($i = 1; $i <= 18; $i++) {
            $simple(sprintf('SBQA-FILL-%02d', $i), sprintf('Capsule QA %02d', $i), (string) (2 + $i), null, array($category_id));
        }
        $simple('SBQA-SIMPLE', 'Miscela Arabica QA', '12.50', 20, array($category_id), array($tag_id));
        $variable_id = (int) wc_get_product_id_by_sku('SBQA-VAR');
        $variable = $variable_id ? wc_get_product($variable_id) : new WC_Product_Variable();
        $attribute = new WC_Product_Attribute();
        $attribute->set_name('Colore');
        $attribute->set_options(array('Rosso', 'Blu'));
        $attribute->set_visible(true);
        $attribute->set_variation(true);
        $variable->set_name('Tazza QA');
        $variable->set_slug('tazza-qa');
        $variable->set_sku('SBQA-VAR');
        $variable->set_status('publish');
        $variable->set_category_ids(array($category_id));
        $variable->set_tag_ids(array($tag_id));
        $variable->set_attributes(array($attribute));
        $ids['SBQA-VAR'] = $variable_id = $variable->save();
        foreach (array('SBQA-VAR-ROSSO' => array('Rosso', '8.00', 5), 'SBQA-VAR-BLU' => array('Blu', '9.00', 3)) as $sku => $data) {
            $existing = (int) wc_get_product_id_by_sku($sku);
            $variation = $existing ? wc_get_product($existing) : new WC_Product_Variation();
            $variation->set_parent_id($variable_id);
            $variation->set_sku($sku);
            $variation->set_attributes(array('colore' => $data[0]));
            $variation->set_regular_price($data[1]);
            $variation->set_manage_stock(true);
            $variation->set_stock_quantity($data[2]);
            $variation->set_status('publish');
            $ids[$sku] = $variation->save();
        }
        WC_Product_Variable::sync($variable_id);
        $grouped_id = (int) wc_get_product_id_by_sku('SBQA-GROUP');
        $grouped = $grouped_id ? wc_get_product($grouped_id) : new WC_Product_Grouped();
        $grouped->set_name('Kit QA');
        $grouped->set_slug('kit-qa');
        $grouped->set_sku('SBQA-GROUP');
        $grouped->set_status('publish');
        $grouped->set_category_ids(array($category_id));
        $grouped->set_children(array($ids['SBQA-SIMPLE'], $ids['SBQA-FILL-01']));
        $ids['SBQA-GROUP'] = $grouped->save();
        // A storefront page as many shops have: the block mini-cart (Store API, its nonce in the page) and a
        // classic product list with AJAX "add to cart" buttons (shortcode).
        $vetrina = get_page_by_path('vetrina', OBJECT, 'page');
        $vetrina_content = "<!-- wp:woocommerce/mini-cart /-->\n<!-- wp:shortcode -->\n[products skus=\"SBQA-SIMPLE,SBQA-FILL-01\" columns=\"2\"]\n<!-- /wp:shortcode -->";
        $ids['vetrina'] = $vetrina ? (int) $vetrina->ID : (int) wp_insert_post(array('post_type' => 'page', 'post_status' => 'publish', 'post_name' => 'vetrina', 'post_title' => 'Vetrina', 'post_content' => $vetrina_content, 'post_author' => 1));
        // The All Products block (React, Store API through wp.apiFetch) prints the Store API and REST nonces.
        $blocks = get_page_by_path('catalogo-blocchi', OBJECT, 'page');
        $attributes = '{"columns":3,"rows":3,"alignButtons":false,"contentVisibility":{"orderBy":true},"orderby":"date","layoutConfig":[["woocommerce/product-image"],["woocommerce/product-title"],["woocommerce/product-price"],["woocommerce/product-button"]]}';
        $blocks_content = '<!-- wp:woocommerce/all-products ' . $attributes . ' --><div class="wp-block-woocommerce-all-products wc-block-all-products" data-attributes="'
            . esc_attr(wp_json_encode(array('alignButtons' => false, 'columns' => 3, 'contentVisibility' => array('orderBy' => true), 'isPreview' => false,
                'layoutConfig' => array(array('woocommerce/product-image'), array('woocommerce/product-title'), array('woocommerce/product-price'), array('woocommerce/product-button')), 'orderby' => 'date', 'rows' => 3)))
            . '"></div><!-- /wp:woocommerce/all-products -->';
        $ids['catalogo-blocchi'] = $blocks ? (int) $blocks->ID : (int) wp_insert_post(array('post_type' => 'page', 'post_status' => 'publish', 'post_name' => 'catalogo-blocchi', 'post_title' => 'Catalogo a blocchi', 'post_content' => $blocks_content, 'post_author' => 1));
        update_option('woocommerce_enable_ajax_add_to_cart', 'yes');
        update_option('permalink_structure', '/%postname%/');
        flush_rewrite_rules(false);
        $pages = array();
        foreach (array('shop', 'cart', 'checkout', 'myaccount') as $page) {
            $pages[$page] = wp_make_link_relative((string) get_permalink(wc_get_page_id($page)));
        }
        $out(array('result' => count($ids) === 25 && !in_array(0, $ids, true) ? 'PASS' : 'FAIL', 'version' => WC_VERSION, 'ids' => $ids, 'pages' => $pages,
            'product' => wp_make_link_relative((string) get_permalink($ids['SBQA-SIMPLE'])), 'variable' => wp_make_link_relative((string) get_permalink($ids['SBQA-VAR'])),
            'grouped' => wp_make_link_relative((string) get_permalink($ids['SBQA-GROUP'])), 'vetrina' => wp_make_link_relative((string) get_permalink($ids['vetrina'])), 'category' => wp_make_link_relative((string) get_term_link($category_id, 'product_cat')),
            'tag' => wp_make_link_relative((string) get_term_link($tag_id, 'product_tag'))));
        break;

    case 'price':
    case 'sale':
        $product = $by_sku((string) ($argv[2] ?? ''));
        $price = (string) ($argv[3] ?? '');
        if (!preg_match('/^\d{1,4}(?:\.\d{1,2})?$/', $price)) {
            fwrite(STDERR, "Price must look like 12.50.\n");
            exit(2);
        }
        'price' === $mode ? $product->set_regular_price($price) : $product->set_sale_price($price);
        $product->save();
        $out(array('result' => 'PASS', 'id' => $product->get_id(), 'parent' => $product->get_parent_id(), 'price' => $product->get_price(), 'queue' => count($queue())));
        break;

    case 'stock':
        $product = $by_sku((string) ($argv[2] ?? ''));
        $amount = (int) ($argv[3] ?? 0);
        $left = wc_update_product_stock($product, max(1, $amount), 'decrease');
        $out(array('result' => 'PASS', 'id' => $product->get_id(), 'stock' => $left, 'status' => wc_get_product($product->get_id())->get_stock_status(), 'queue' => count($queue())));
        break;

    case 'option':
        $name = (string) ($argv[2] ?? '');
        $value = (string) ($argv[3] ?? '');
        if ('woocommerce_currency_pos' !== $name || !in_array($value, array('left', 'right', 'left_space', 'right_space'), true)) {
            fwrite(STDERR, "Only woocommerce_currency_pos left|right|left_space|right_space.\n");
            exit(2);
        }
        update_option($name, $value);
        $out(array('result' => 'PASS', 'option' => $name, 'value' => get_option($name)));
        break;

    case 'termmeta':
        $term = get_term_by('slug', 'caffe', 'product_cat');
        $done = $term ? update_term_meta((int) $term->term_id, 'display_type', 'both') : false;
        $out(array('result' => false !== $done ? 'PASS' : 'FAIL', 'term' => $term ? (int) $term->term_id : 0));
        break;

    case 'worker':
        for ($round = 0; $round < 60 && !empty($queue()); $round++) {
            delete_option('static_bridge_incremental_worker_lock');
            do_action('static_bridge_process_incremental_queue');
        }
        $out(array('rounds' => $round, 'left' => count($queue())));
        break;

    case 'queue':
        $out(array('paths' => array_values(array_map(static fn($item) => (string) ($item['path'] ?? ''), $queue()))));
        break;

    case 'inspect':
        $orders = function_exists('wc_get_orders') ? wc_get_orders(array('limit' => 5, 'orderby' => 'date', 'order' => 'DESC')) : array();
        $out(array('result' => 'PASS', 'version' => defined('WC_VERSION') ? WC_VERSION : null, 'queue' => count($queue()),
            'woocommerce_dynamic' => get_option('static_bridge_woocommerce_dynamic', array()),
            'orders' => array_map(static fn($o) => array('id' => $o->get_id(), 'status' => $o->get_status(), 'total' => $o->get_total(), 'method' => $o->get_payment_method(),
                'email' => $o->get_billing_email(), 'items' => array_map(static fn($i) => $i->get_name() . ' x' . $i->get_quantity(), array_values($o->get_items()))), $orders),
            'stock' => array_map(static fn($sku) => ($id = (int) wc_get_product_id_by_sku($sku)) ? wc_get_product($id)->get_stock_quantity() : null,
                array('simple' => 'SBQA-SIMPLE', 'rosso' => 'SBQA-VAR-ROSSO', 'blu' => 'SBQA-VAR-BLU'))));
        break;

    case 'cleanup':
        $deleted = 0;
        foreach (function_exists('wc_get_orders') ? wc_get_orders(array('limit' => -1, 'return' => 'ids')) : array() as $order_id) {
            $order = wc_get_order($order_id);
            if ($order && $order->delete(true)) {
                $deleted++;
            }
        }
        global $wpdb;
        $wpdb->query("DELETE FROM {$wpdb->prefix}woocommerce_sessions");
        $out(array('result' => 'PASS', 'orders_deleted' => $deleted));
        break;
}
