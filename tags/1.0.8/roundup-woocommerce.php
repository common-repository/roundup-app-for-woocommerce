<?php

/**
 * @link https://roundupapp.com/
 * @since 1.0.8
 * @package RoundUp App for WooCommerce
 * Plugin Name: RoundUp App for WooCommerce
 * Plugin URI: https://github.com/roundupapp/woocommerce/
 * Description: Link your RoundUp merchant account to WooCommerce to allow your customers to round up their change.
 * Name: RoundUp App
 * Version: 1.0.8
 * Author: RoundUp App
 * Author URI: https://roundupapp.com/
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

include_once(ABSPATH . 'wp-admin/includes/plugin.php');
include_once(ABSPATH . 'wp-includes/pluggable.php');
require_once(ABSPATH . 'wp-admin/includes/media.php');
require_once(ABSPATH . 'wp-admin/includes/file.php');
require_once(ABSPATH . 'wp-admin/includes/image.php');

// Check if WooCommerce is active
if (!is_plugin_active('woocommerce/woocommerce.php')) {
    return;
}

final class RoundUpPlugin {

    protected $sku = 'RoundUp-donation';

    public function __construct() {
        add_action('activated_plugin', [$this, 'activated']);
        add_action('deactivated_plugin', [$this, 'deactivated']);

        if (is_admin()) {
            add_filter('woocommerce_get_sections_advanced', [$this, 'add_roundup_section']);
            add_filter('woocommerce_get_settings_advanced', [$this, 'roundup_settings'], 10, 2);
            add_filter('plugin_action_links_'.plugin_basename(__FILE__), [$this, 'settings_link' ]);
        }

        $this->define_admin_hooks();
        $this->define_public_hooks();
    }

    public function activated() {
        $this->add_product();
        $this->add_webhook();
    }

    public function deactivated() {
        $this->remove_webhook();
        $this->remove_product();
    }

    public function define_admin_hooks() {
        add_action('woocommerce_webhook_payload', [$this, 'webhook_payload']);
        add_action('woocommerce_before_order_notes', [$this, 'checkout_shipping'], 20, 1);
    }

    public function define_public_hooks() {
        add_action('wp_enqueue_scripts', [$this, 'enqueue_styles_and_scripts']);
        add_action('rest_api_init', function () {
            register_rest_route('roundup/v1', '/total', array(
                'methods' => 'GET',
                'callback' => [$this, 'get_totals'],
                'permission_callback' => '__return_true'
            ));
            register_rest_route('roundup/v1', '/remove', array(
                'methods' => 'POST',
                'callback' => [$this, 'remove_roundup'],
                'permission_callback' => '__return_true'
            ));
            register_rest_route('roundup/v1', '/add', array(
                'methods' => 'POST',
                'callback' => [$this, 'add_roundup'],
                'permission_callback' => '__return_true'
            ));
            register_rest_route('roundup/v1', '/add_dollar_amount', array(
                'methods' => 'POST',
                'callback' => [$this, 'add_dollar_amount'],
                'permission_callback' => '__return_true'
            ));
            register_rest_route('roundup/v1', '/add_fixed_amount', array(
                'methods' => 'POST',
                'callback' => [$this, 'add_fixed_amount'],
                'permission_callback' => '__return_true'
            ));
        });
        add_filter('woocommerce_is_rest_api_request', [ $this, 'simulate_as_not_rest' ]);
    }

    public function settings_link($links) {
        $links[] = '<a href="' .
            admin_url('admin.php?page=wc-settings&tab=advanced&section=roundupapp') .
            '">' . __('Settings') . '</a>';
        return $links;
    }

    public function add_roundup_section($sections) {
        $sections['roundupapp'] = __('RoundUp App', 'roundupapp');
        return $sections;
    }

    public function roundup_settings($settings, $current_section) {
        if ('roundupapp' === $current_section) {
            if (get_option('roundup_advanced_settings') === 'yes') {
                $id = wc_get_product_id_by_sku($this->sku);
                $product = '';
                $children = '';
                $count = 0;
                $variants = [];
                if ($id) {
                    $product = wc_get_product($id);
                    $children = new WC_Product_Variable($id);
                    $count = count($children->get_available_variations());
                    foreach ($children->get_available_variations() as $child) {
                        $variants[] = strval($child['sku']);
                    }

                }
                return [
                    [
                        'title' => __('RoundUp App Settings', 'roundupapp'),
                        'type'  => 'title',
                        'id'    => 'roundup_settings_section',
                    ],
                    [
                        'id'    => 'roundup_api_key',
                        'type'  => 'text',
                        'title' => __('API Key', 'roundupapp')
                    ],
                    [
                        'id'    => 'roundup_public_key',
                        'type'  => 'text',
                        'title' => __('Public Key', 'roundupapp')
                    ],
                    [
                        'id'    => 'roundup_beta_product',
                        'type'  => 'checkbox',
                        'desc' => __('Enable beta options to include the latest features in the RoundUp App embed', 'roundupapp'),
                        'title' => __('Beta options', 'roundupapp')
                    ],
                    [
                        'id'    => 'roundup_advanced_settings',
                        'type'  => 'checkbox',
                        'desc' => __('', 'roundupapp'),
                        'title' => __('Debug Tools', 'roundupapp')
                    ],
                    [
                        'type'  => 'sectionend',
                        'id'    => 'roundup_settings_section',
                    ],
                    [
                        'title' => __('RoundUp App Debug Tools', 'roundupapp'),
                        'type'  => 'title',
                        'id'    => 'roundup_advance_settings_section',
                    ],
                    [
                        'id'    => 'roundup_advance_sku',
                        'type'  => 'text',
                        'title' => __('SKU name (read only)', 'roundupapp'),
                        'default' => $this->sku
                    ],
                    [
                        'id'    => 'roundup_advance_id',
                        'type'  => 'text',
                        'title' => __('ID (read only)', 'roundupapp'),
                        'default' => $id
                    ],
                    [
                        'id'    => 'roundup_advance_product',
                        'type'  => 'textarea',
                        'title' => __('Product (read only)', 'roundupapp'),
                        'default' => $product
                    ],
                    [
                        'id'    => 'roundup_advance_child_amount',
                        'type'  => 'text',
                        'title' => __('Child Amount (read only)', 'roundupapp'),
                        'default' => $count
                    ],
                    [
                        'id'    => 'roundup_advance_variants',
                        'type'  => 'select',
                        'title' => __('Variants (read only)', 'roundupapp'),
                        'options' => $variants
                    ],
                    [
                        'type'  => 'sectionend',
                        'id'    => 'roundup_advance_settings_section',
                    ],
                ];
            }

            return [
                [
                    'title' => __('RoundUp App Settings', 'roundupapp'),
                    'type'  => 'title',
                    'id'    => 'roundup_settings_section',
                ],
                [
                    'id'    => 'roundup_api_key',
                    'type'  => 'text',
                    'title' => __('API Key', 'roundupapp')
                ],
                [
                    'id'    => 'roundup_public_key',
                    'type'  => 'text',
                    'title' => __('Public Key', 'roundupapp')
                ],
                [
                    'id'    => 'roundup_beta_product',
                    'type'  => 'checkbox',
                    'desc' => __('Enable beta options to include the latest features in the RoundUp App embed', 'roundupapp'),
                    'title' => __('Beta options', 'roundupapp')
                ],
                [
                    'id'    => 'roundup_advanced_settings',
                    'type'  => 'checkbox',
                    'desc' => __('', 'roundupapp'),
                    'title' => __('Debug Tools', 'roundupapp')
                ],
                [
                    'type'  => 'sectionend',
                    'id'    => 'roundup_settings_section',
                ]
            ];
        }
        else {
            return $settings;
        }
    }

    private function add_product() {
        if (post_exists('RoundUp App Donation')) {
            $oldId = post_exists('RoundUp App Donation');
            $this->remove_product($oldId);
        }

        $image = media_sideload_image('https://d2gbgm7n6hyv3d.cloudfront.net/RoundUp_Icon.png', 0, 'RoundUp App Product Image', 'id');

        $title = 'RoundUp App Donation';
        $attr = 'Donation Amount';
        $attr_slug = sanitize_title($attr);
        $post_id = wp_insert_post([
            'post_title' => $title,
            'post_content' => 'RoundUp App Donation',
            'post_status' => 'publish',
            'post_type' => 'product',
        ]);

        $product = wc_get_product($post_id);
        $product->set_sku($this->sku);
        $product->save();

        wp_set_object_terms($post_id, 'variable', 'product_type');
        wp_set_post_terms($post_id, ['exclude-from-search', 'exclude-from-catalog'], 'product_visibility', false);
        update_post_meta($post_id, '_visibility', 'hidden');
        update_post_meta($post_id, '_price', '0.01');
        update_post_meta($post_id, '_regular_price', '0.01');
        update_post_meta($post_id, '_downloadable', 'no');
        update_post_meta($post_id, '_virtual', 'yes');
        update_post_meta($post_id, '_tax-status', 'none');
        update_post_meta($post_id, '_tax_class', 'zero-rate');
        update_post_meta($post_id, '_thumbnail_id', $image);

        $attributes_array[$attr_slug] = array(
            'name' => $attr,
            'value' => join(" | ", range(1, 99)),
            'position' => '0',
            'is_visible' => '1',
            'is_variation' => '1',
            'is_taxonomy' => '0'
        );
        update_post_meta($post_id, '_product_attributes', $attributes_array);

        $product = wc_get_product($post_id);
        for ($i = 1; $i < 101; $i++) {
            $price = number_format($i / 100, 2, '.', '');
            $variation_id = wp_insert_post([
                'post_title'  => $product->get_title(),
                'post_name'   => 'product-'.$post_id.'-variation',
                'post_status' => 'publish',
                'post_parent' => $post_id,
                'post_type'   => 'product_variation',
                'guid'        => $product->get_permalink()
            ]);
            update_post_meta($variation_id, '_price', $price);
            update_post_meta($variation_id, '_stock', 0);
            update_post_meta($variation_id, '_regular_price', $price);
            update_post_meta($variation_id, 'attribute_' . $attr_slug, $i);
            update_post_meta($variation_id, '_downloadable', 'no');
            update_post_meta($variation_id, '_virtual', 'yes');
            update_post_meta($variation_id, '_sku', $this->sku .'-'.$i);
        }
        WC_Product_Variable::sync($post_id);
    }

    private function remove_product($id = null) {
        if ($id === null) {
            $id = wc_get_product_id_by_sku($this->sku);
        }

        $product = wc_get_product($id);

        if (empty($product)) {
            if (post_exists('RoundUp App Donation')) {

                $oldId = post_exists('RoundUp App Donation');
                $this->remove_product($oldId);
            }
            return;
        }

        foreach ($product->get_children() as $child_id) {
            $child = wc_get_product($child_id);
            $child->delete(true);
        }

        $product->delete(true);
        $result = $product->get_id() > 0 ? false : true;



        if ($parent_id = wp_get_post_parent_id($id)) {
            wc_delete_product_transients($parent_id);
        }
    }

    private function require_woocommerce() {
        require_once __DIR__ . '/../woocommerce/includes/abstracts/abstract-wc-data.php';
        require_once __DIR__ . '/../woocommerce/includes/class-wc-data-store.php';
        require_once __DIR__ . '/../woocommerce/includes/interfaces/class-wc-webhooks-data-store-interface.php';
        require_once __DIR__ . '/../woocommerce/includes/data-stores/class-wc-webhook-data-store.php';
        require_once __DIR__ . '/../woocommerce/includes/class-wc-webhook.php';
    }

    private function add_webhook() {
        $this->require_woocommerce();

        $webhook = new WC_Webhook();
        $webhook->set_name('RoundUp Webhook');
        $webhook->set_user_id(get_current_user_id());
        $webhook->set_topic('order.created');
        $webhook->set_secret('roundupapp');
        $webhook->set_delivery_url('https://api.roundupapp.com/merchants/woocommerce/webhook');
        $webhook->set_status('active');
        $save = $webhook->save();
        return $save;
    }

    protected function remove_webhook() {
        $this->require_woocommerce();

        global $wpdb;
        $results = $wpdb->get_results( "SELECT webhook_id, delivery_url FROM {$wpdb->prefix}wc_webhooks" );
        foreach($results as $result) {
            if (strpos($result->delivery_url, 'roundupapp.com') !== false) {
                $wh = new WC_Webhook();
                $wh->set_id($result->webhook_id);
                $wh->delete();
            }
        }
    }

    public function webhook_payload($payload) {
        $payload['roundup_api_key'] = get_option('roundup_api_key');
        return $payload;
    }

    public function checkout_shipping() {
        $key = get_option('roundup_public_key');
        if ($key) {
            echo '<roundup-at-checkout merchant_key="'.$key.'"></roundup-at-checkout>';
        }
    }

    public function get_totals() {
        $total = floatval(WC()->cart->total);
        $roundup = ceil($total) - $total;
        $enabled = false;
        $id = wc_get_product_id_by_sku($this->sku);
        foreach (WC()->cart->get_cart() as $cart_item_key => $cart_item) {
            if ($cart_item['product_id'] === $id) {
                $enabled = true;
                $roundup = $cart_item['line_total'];
            }
        }
        $roundup_round_number = number_format(ceil($roundup) - $roundup, 2, '.', '');
        $cart_a_round_number = number_format(ceil(WC()->cart->total) - WC()->cart->total, 2, '.', '');
        if ($enabled && $cart_a_round_number != 0 && $roundup_round_number != 0) {
            $this->remove_roundup_action();
            $this->add_roundup_action($id, WC()->cart->total);
            foreach (WC()->cart->get_cart() as $cart_item_key => $cart_item) {
                if ($cart_item['product_id'] === $id) {
                    $enabled = true;
                    $roundup = $cart_item['line_total'];
                }
            }
        }

        return json_encode([
            'total' => WC()->cart->total,
            'roundup' => $roundup,
            'enabled' => $enabled,
            'cart_total' => WC()->cart->total,
            'roundup_product_id' => $id
        ]);
    }

    public function add_roundup() {
        try {
            $total = WC()->cart->total;
            $id = wc_get_product_id_by_sku($this->sku);
            if (!$id) {
                wp_send_json_error([ 'message' => 'Unable to find product matching provided SKU ('.$this->sku.'). ID: '.$id.' ' ]);
                return;
            }
            $money_amount =  number_format(ceil($total) - $total, 2, '.', '');
            $this->add_roundup_action($id, $total);

            wp_send_json_success([
                'total' => $money_amount,
                'id' => $id,
                'cart_total' => WC()->cart->total
            ]);
        }
        catch (Exception $e) {
            wp_send_json_error([ 'message' => $e->getMessage() ]);
        }
    }

    public function add_dollar_amount($req) {
        $params = $req->get_params();
        $amount = $params['amount'];
        try {
            $total = WC()->cart->total;
            $id = wc_get_product_id_by_sku($this->sku);
            if (!$id) {
                wp_send_json_error([ 'message' => 'Unable to find product matching provided SKU ('.$id.').' ]);
                return;
            }
            if (!$amount || $amount < 100 ){
                wp_send_json_error([ 'message' => 'Unable to add whole dollar amount. Must be above $.99  ('.$amount.')']);
                return;
            }
            $money_amount =  number_format(ceil($total) - $total, 2, '.', '');
            $this->add_dollar_roundup_action($id, $amount);

            wp_send_json_success([
                'total' => $money_amount,
                'id' => $id,
                'cart_total' => WC()->cart->total,
            ]);
        }
        catch (Exception $e) {
            wp_send_json_error([ 'message' => $e->getMessage() ]);
        }
    }

    public function add_fixed_amount($req) {
        $params = $req->get_params();
        $amount = $params['amount'];
        try {
            $total = WC()->cart->total;
            $id = wc_get_product_id_by_sku($this->sku);
            if (!$id) {
                wp_send_json_error([ 'message' => 'Unable to find product matching provided SKU ('.$id.').' ]);
                return;
            }
            if (!$amount || $amount < 100 ){
                wp_send_json_error([ 'message' => 'Unable to add fixed amount. Must be above $.99  ('.$amount.')']);
                return;
            }
            $money_amount =  number_format(ceil($total) - $total, 2, '.', '');
            $this->add_dollar_roundup_action($id, $amount);

            wp_send_json_success([
                'total' => $money_amount,
                'id' => $id,
                'cart_total' => WC()->cart->total,
            ]);
        }
        catch (Exception $e) {
            wp_send_json_error([ 'message' => $e->getMessage() ]);
        }
    }

    public function add_dollar_roundup_action($id, $amount) {
        $children = new WC_Product_Variable($id);
        $added = false;
        foreach ($children->get_available_variations() as $child) {
            if (strval($child['sku']) === $this->sku . "-" . $amount) {
                WC()->cart->add_to_cart($id, 1, $child['variation_id']);
                $added = true;
            }
        }
        // child variation not found. Create a new one and add it to the cart.
        if ($added === false) {
            $variation_id = $this->create_new_variation($amount);
            WC()->cart->add_to_cart($id, 1, $variation_id);
        }
    }

    public function create_new_variation($amount) {
        if ($amount < 99) {
            // Everything below 100 has already been added.
            return false;
        }

        $id = wc_get_product_id_by_sku($this->sku);
        $product = wc_get_product($id);
        $attr = 'Donation Amount';
        $attr_slug = sanitize_title($attr);

        $price = number_format($amount / 100, 2, '.', '');
        $variation_id = wp_insert_post([
            'post_title'  => $product->get_title(),
            'post_name'   => 'product-'.$id.'-variation',
            'post_status' => 'publish',
            'post_parent' => $id,
            'post_type'   => 'product_variation',
            'guid'        => $product->get_permalink()
        ]);
        update_post_meta($variation_id, '_price', $price);
        update_post_meta($variation_id, '_regular_price', $price);
        update_post_meta($variation_id, 'attribute_' . $attr_slug, $amount);
        update_post_meta($variation_id, '_downloadable', 'no');
        update_post_meta($variation_id, '_virtual', 'yes');
        update_post_meta($variation_id, '_sku', $this->sku .'-'.$amount);

        WC_Product_Variable::sync($id);
        return $variation_id;
    }

    public function add_roundup_action($id, $total) {

        $money_amount =  number_format(ceil($total) - $total, 2, '.', '');
        $integer_amount = intval($money_amount * 100);

        $children = new WC_Product_Variable($id);
        foreach ($children->get_available_variations() as $child) {
            if (strval($child['sku']) === $this->sku . "-" . $integer_amount) {
                WC()->cart->add_to_cart($id, 1, $child['variation_id']);
            }
            if ($money_amount == 0 && (strval($child['sku']) == $this->sku . "-100" )) {
                WC()->cart->add_to_cart($id, 1, $child['variation_id']);
            }
        }
    }

    public function remove_roundup() {
        try {
            $this->remove_roundup_action();
            wp_send_json_success();
        }
        catch (Exception $e) {
            wp_send_json_error([ 'message' => $e->getMessage() ]);
        }
    }

    private function remove_roundup_action() {
        $id = wc_get_product_id_by_sku($this->sku);
        foreach (WC()->cart->get_cart() as $cart_item_key => $cart_item) {
            if ($cart_item['product_id'] === $id) {
                WC()->cart->remove_cart_item($cart_item_key);
            }
        }
    }

    public function enqueue_styles_and_scripts() {
        if (get_option('roundup_beta_product') === true || get_option('roundup_beta_product') === 'yes') {
            wp_enqueue_style('style', 'https://s3.amazonaws.com/embed.roundupapp.com/woocommerce-beta/css/wc-roundup-embed.css');
            wp_enqueue_script('script', 'https://s3.amazonaws.com/embed.roundupapp.com/woocommerce-beta/js/wc-roundup-embed.js');

        } else {
            wp_enqueue_style('style', 'https://s3.amazonaws.com/embed.roundupapp.com/woocommerce/css/wc-roundup-embed.css');
            wp_enqueue_script('script', 'https://s3.amazonaws.com/embed.roundupapp.com/woocommerce/js/wc-roundup-embed.js');

        }

    }

    public function simulate_as_not_rest($is_rest_api_request) {
        if (empty($_SERVER['REQUEST_URI'])) {
            return $is_rest_api_request;
        }

        // Bail early if this is not our request.
        if (strpos($_SERVER['REQUEST_URI'], 'roundup') === false) {
            return $is_rest_api_request;
        }

        return false;
    }
}

if (!function_exists('RUA')) {
    function RUA() {
        $run = new RoundUpPlugin;
    }
}

RUA();
