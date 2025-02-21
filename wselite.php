<?php



if (! defined('ABSPATH')) exit;

defined('WSEXTRA_BASE_FILE') || define('WSEXTRA_BASE_FILE', __FILE__);

add_action('init', 'wsextra_init');
function wsextra_init()
{
    // initialize common lib
    include_once('common/MHCommon.php');
    MHCommon::initializeV2(
        'woo-subscription-extras',
        'wse',
        WSEXTRA_BASE_FILE,
        __('WooCommerce Subscription Extras', 'woo-subscription-extras')
    );

    // plugin checks
    if (!empty($_GET['wsextra_update'])) {
        wsextra_redirect_subscriptions_update();
    }

    // dynamic events
    if (wsextra_option('auto_ask_shipping_change') == 'yes' && class_exists('WooCommerce')) {
        $methods = WC()->shipping->get_shipping_methods();

        foreach ($methods as $method) {
            add_filter('woocommerce_shipping_' . $method->id . '_instance_settings_values', 'wsextra_update_shipping_method', 10, 2);
        }
    }

    // fire plugin callbacks
    do_action('wsextra_after_init');
}

add_filter('mh_wse_settings', 'wsextra_settings');
function wsextra_settings(array $arr): array
{
    return [
        ...$arr,
        'auto_ask_price_change' => [
            'label' => esc_attr__('Ask to change subscriptions prices when changed (on product save)', 'woo-subscription-extras'),
            'tab' => esc_attr__('General', 'woo-subscription-extras'),
            'type' => 'checkbox',
            'default' => 'yes',
        ],
        'auto_ask_shipping_change' => [
            'label' => esc_attr__('Ask to recalculate subscriptions when change shipping methods (on shipping settings tab)', 'woo-subscription-extras'),
            'tab' => esc_attr__('General', 'woo-subscription-extras'),
            'type' => 'checkbox',
            'default' => 'yes',
        ],
        'ignore_taxes' => [
            'label' => esc_attr__('Ignore customer taxes in subscription prices', 'woo-subscription-extras'),
            'tab' => esc_attr__('General', 'woo-subscription-extras'),
            'type' => 'checkbox',
            'default' => 'no',
        ]
    ];
}

add_filter('mh_wse_premium_url', 'wsextra_premium_url');
function wsextra_premium_url()
{
    return 'http://gum.co/wsubextras';
}

add_action('save_post_product', 'wsextra_save_post_product', 10, 3);
function wsextra_save_post_product(int $post_id, WP_Post $post, bool $update): void
{
    if ((defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) || !$update) {
        return;
    }

    if (wsextra_option('auto_ask_price_change') !== 'yes') {
        return;
    }

    $product = wc_get_product($post_id);
    $changes = [];

    $isTypeSub = $product?->is_type(['downloadable_subscription', 'subscription', 'variable-subscription', 'virtual_subscription']);

    if ($isTypeSub || $product?->is_type('simple')) {
        $regularPrice = wc_format_decimal(sanitize_text_field($_POST['_regular_price'] ?? ''));
        $salePrice = wc_format_decimal(sanitize_text_field($_POST['_sale_price'] ?? ''));
        $oldPrice = $product->get_price();

        $changes[] = wsextra_check_price_change($product, $oldPrice, $regularPrice, $salePrice);
    }

    $changes = array_filter(
        apply_filters('wsextra_product_save_check', $changes, $product)
    );

    match (!empty($changes)) {
        true => set_transient('wsextra_price_change', $changes, 60),
        false => delete_transient('wsextra_price_change')
    };
}


add_action('admin_notices', 'wsextra_check_notices', 10);
function wsextra_check_notices()
{
    global $pagenow;

    if (($pagenow == 'edit.php') && !empty($_GET['wsextra_updateds'])) {
        $updateds = (int) sanitize_text_field(filter_input(INPUT_GET, 'wsextra_updateds'));
        $message = sprintf(__('A total of %d subscriptions have been updated successfully!', 'woo-subscription-extras'), $updateds);

?>
        <div class="notice is-dismissible notice-success">
            <p><?php echo esc_attr__($message); ?></p>
        </div>
    <?php
    }

    if (($pagenow == 'post.php') && get_transient('wsextra_price_change')) {
        wsextra_show_price_changed_notice(get_transient('wsextra_price_change'));
        delete_transient('wsextra_price_change');
    }
}

add_filter('post_row_actions', 'wsextra_sync_action', 10, 2);
function wsextra_sync_action($actions, $post)
{
    global $the_product;

    if ('product' !== $post->post_type) {
        return $actions;
    }

    if (empty($the_product) || $the_product->get_id() !== $post->ID) {
        $the_product = wc_get_product($post);
    }

    if (!in_array($the_product->get_type(), array('variable-subscription', 'subscription'))) {
        return $actions;
    }

    $url = wsextra_build_url_update($post->ID);
    $hint = esc_attr__('Synchronize subscriptions prices with this product', 'woo-subscription-extras');
    $label = esc_attr__('Synchronize prices', 'woo-subscription-extras');

    $actions['wsextra_sync'] = '<a href="' . $url . '" aria-label="' . $hint . '" rel="permalink">' . $label . '</a>';

    return $actions;
}

add_action('woocommerce_product_options_general_product_data', 'wsextra_subscription_pricing_fields', 20);
function wsextra_subscription_pricing_fields()
{
    global $post;

    $product = wc_get_product($post->ID);

    if (!$product->is_type('subscription')) {
        return;
    }

    echo '<div class="options_group">';

    woocommerce_wp_text_input(array(
        'id'          => '_subscription_start_date',
        'class'       => 'wc_input_subscription_start_date short',
        'label'       => sprintf(__('Subscription start date', 'woo-subscription-extras')),
        'placeholder' => _x('', 'woo-subscription-extras'),
        'description' => __('When filled, this field will change the subscription date to future, instead of the default (current date)', 'woo-subscription-extras'),
        'desc_tip'    => true,
        'type'        => 'text',
        'data_type'   => 'date',
    ));

    woocommerce_wp_text_input(array(
        'id'          => '_subscription_next_payment',
        'class'       => 'wc_input_subscription_next_payment short',
        'label'       => sprintf(__('Subscription next payment', 'woo-subscription-extras')),
        'placeholder' => _x('', 'woo-subscription-extras'),
        'description' => __('When filled, this field will change the subscription Next Payment date', 'woo-subscription-extras'),
        'desc_tip'    => true,
        'type'        => 'text',
        'data_type'   => 'date',
    ));

    echo '</div>';

    ?>
    <script type="text/javascript">
        jQuery(document).ready(function($) {
            $('.wc_input_subscription_start_date,.wc_input_subscription_next_payment').datepicker({
                dateFormat: 'yy-mm-dd'
            });
        });
    </script>
    <?php

    wp_nonce_field('wsextra_subscription_meta', '_wsenonce');
}

add_action('save_post', 'wsextra_save_subscription_meta', 11);
function wsextra_save_subscription_meta($post_id)
{
    if (empty($_POST['_wsenonce']) || ! wp_verify_nonce($_POST['_wsenonce'], 'wsextra_subscription_meta')) {
        return;
    }

    // save start date
    $start_date = isset($_REQUEST['_subscription_start_date']) ? $_REQUEST['_subscription_start_date'] : '';

    if (!empty($start_date)) {
        update_post_meta($post_id, '_subscription_start_date', $start_date);
    } else {
        $db_start_date = get_post_meta($post_id, '_subscription_start_date', true);

        if (!empty($db_start_date)) {
            delete_post_meta($post_id, '_subscription_start_date');
        }
    }

    // save next payment date
    $next_payment = isset($_REQUEST['_subscription_next_payment']) ? $_REQUEST['_subscription_next_payment'] : '';

    if (!empty($next_payment)) {
        update_post_meta($post_id, '_subscription_next_payment', $next_payment);
    } else {
        $db_next_payment = get_post_meta($post_id, '_subscription_next_payment', true);

        if (!empty($db_next_payment)) {
            delete_post_meta($post_id, '_subscription_next_payment');
        }
    }
}

add_action('wcs_create_subscription', 'wsextra_create_subscription');
function wsextra_create_subscription($subscription)
{

    foreach ($subscription->get_parent()->get_items() as $item) {

        $post_id = $item->get_product_id();

        // check configured start date
        $start_date = get_post_meta($post_id, '_subscription_start_date', true);

        if (!empty($start_date)) {
            $subscription_id = $subscription->get_id();
            $start_date .= ' 00:00:00';
            update_post_meta($subscription_id, '_schedule_start', $start_date);
        }

        // check configured next payment
        $next_payment = get_post_meta($post_id, '_subscription_next_payment', true);

        if (!empty($next_payment)) {
            $subscription_id = $subscription->get_id();
            $next_payment .= ' 00:00:00';
            update_post_meta($subscription_id, '_schedule_next_payment', $next_payment);
        }
    }
}

function wsextra_option($name)
{
    return apply_filters('mh_wse_setting_value', $name);
}

function wsextra_build_url_update($product_id)
{
    $urlArgs = array();
    $urlArgs['wsextra_update'] = 1;
    $urlArgs['product_id'] = $product_id;

    return add_query_arg($urlArgs, admin_url('edit.php?post_type=shop_subscription'));
}

function wsextra_show_price_changed_notice($messages)
{
    foreach ((array)$messages as $data) {
        $message = $data['message'];

    ?>
        <div class="notice is-dismissible notice-warning">
            <p><?php echo $message; ?></p>
        </div>
    <?php
    }

    // workaround to display messages hided by WooCommerce Admin
    ?>
    <script>
        jQuery(document).ready(function($) {
            $('#wp__notice-list').show();
        });
    </script>
<?php
}

function wsextra_get_orders_by_product_id($product_id, $limit = null, $offset = null)
{
    global $wpdb;

    $args = array();

    $sql = "
        SELECT order_items.order_id
        FROM {$wpdb->prefix}woocommerce_order_items as order_items
        LEFT JOIN {$wpdb->prefix}woocommerce_order_itemmeta as order_item_meta ON order_items.order_item_id = order_item_meta.order_item_id
        LEFT JOIN {$wpdb->posts} AS posts ON order_items.order_id = posts.ID
        WHERE posts.post_type = 'shop_subscription'
        AND posts.post_status NOT IN ('trash')
        AND order_item_meta.meta_key IN ('_product_id', '_variation_id')
        AND order_item_meta.meta_value = '%s'";

    $args[] = $product_id;

    if (!empty($limit)) {
        $sql .= " LIMIT %d";
        $args[] = $limit;
    }

    if (!empty($offset)) {
        $sql .= " OFFSET %d";
        $args[] = $offset;
    }

    return $wpdb->get_col($wpdb->prepare($sql, $args));
}

function wsextra_get_orders_of_shipping_rate($shipping_rate_id)
{
    global $wpdb;

    $sql = "
        SELECT item.order_id
        FROM wp_woocommerce_order_items item
        INNER JOIN wp_woocommerce_order_itemmeta meta ON (item.order_item_id = meta.order_item_id)
        AND meta_key = 'method_id'
        AND meta_value = '%s'";

    $args = array();
    $args[] = $shipping_rate_id;

    return $wpdb->get_col($wpdb->prepare($sql, $args));
}

function wsextra_redirect_subscriptions_update()
{
    if (defined('WC_PLUGIN_FILE')) {
        require_once dirname(WC_PLUGIN_FILE) . '/includes/wc-cart-functions.php';
    }

    $product_id = (int) sanitize_text_field(filter_input(INPUT_GET, 'product_id'));
    $shipping_method_rate_id = sanitize_text_field(filter_input(INPUT_GET, 'shipping_method_rate_id'));

    if (!empty($product_id)) {
        $args = array(
            'product_id' => $product_id,
        );
    } else if (!empty($shipping_method_rate_id)) {
        $args = array(
            'shipping_method_rate_id' => $shipping_method_rate_id,
        );
    }

    if (empty($args)) {
        exit("invalid subscription update data");
    }

    // disable php 7.1+ annoying errors :)
    error_reporting(E_CORE_ERROR | E_COMPILE_ERROR | E_ERROR | E_PARSE | E_USER_ERROR | E_RECOVERABLE_ERROR);

    // let the PREMIUM plugin do the job
    if (apply_filters('wsextra_custom_update_subs', false)) {
        do_action('wsextra_update_subscriptions', $args);
        return;
    }

    $updateds = 0;
    $orders = wsextra_get_orders_to_update($args);

    foreach ($orders as $order_id) {
        $updateds += wsextra_update_subscription_order($order_id, $args);
    }

    wp_safe_redirect(add_query_arg(
        array('wsextra_updateds' => $updateds),
        admin_url('edit.php?post_type=shop_subscription')
    ));

    exit;
}

function wsextra_get_orders_to_update($args)
{
    $orders = array();

    if (!empty($args['product_id'])) {
        $product_id = $args['product_id'];
        $orders = wsextra_get_orders_by_product_id($product_id);
    } else if (!empty($args['shipping_method_rate_id'])) {
        $method_rate_id = $args['shipping_method_rate_id'];
        $orders = wsextra_get_orders_of_shipping_rate($method_rate_id);
    }

    return $orders;
}

function wsextra_update_subscription_order(int $order_id, ?array $args = null, ?bool $fix_variations = false): int
{
    try {
        $order = wc_get_order($order_id) ?? throw new Exception("Order not found");

        error_log(sprintf('[WSE Debug] Processing order %d', $order_id));

        $order->set_prices_include_tax(get_option('woocommerce_prices_include_tax') === 'yes');

        // Emulate order customer
        WC()->customer = new WC_Customer(
            data: $order->get_customer_id(),
            is_session: false
        );

        WC()->session?->init();

        // Force clear cached shipping methods 
        if (method_exists($order, 'remove_order_items')) {
            $order->remove_order_items('shipping');
        }

        // Debug shipping methods before calculation
        $shipping_methods = $order->get_shipping_methods();

        // Reset shipping totals
        $order->set_shipping_total(0);
        $order->set_shipping_tax(0);

        $updated = false;

        // remove all fees
        foreach ($order->get_fees() as $fee) {
            $order->remove_item($fee->get_id());
        }

        // Process tax and product updates first
        foreach ($order->get_items('tax') as $line_item) {
            wsextra_update_order_item_tax($order, $line_item);
        }

        foreach ($order->get_items() as $line_item) {
            $updated = wsextra_update_order_item_product($order, $line_item, $fix_variations) !== false || $updated;
        }

        // Get shipping zone and available methods
        $shipping_zone = WC_Shipping_Zones::get_zone_matching_package([
            'destination' => [
                'country' => $order->get_shipping_country(),
                'state' => $order->get_shipping_state(),
                'postcode' => $order->get_shipping_postcode(),
            ]
        ]);

        $shipping_methods = $shipping_zone->get_shipping_methods(true);
        $subtotal = $order->get_subtotal();

        // Check free shipping first
        foreach ($shipping_methods as $method) {
            if ($method instanceof WC_Shipping_Free_Shipping) {
                $min_amount = (float)$method->get_option('min_amount', 0);
                if ($min_amount === 0 || $subtotal >= $min_amount) {
                    $item = new WC_Order_Item_Shipping();
                    $item->set_props([
                        'method_title' => $method->get_title(),
                        'method_id' => $method->id,
                        'instance_id' => $method->get_instance_id(),
                        'total' => 0,
                        'taxes' => []
                    ]);
                    $order->add_item($item);
                    break;
                }
            }
        }

        // If no free shipping was added, use flat rate
        if (empty($order->get_shipping_methods())) {
            foreach ($shipping_methods as $method) {
                if ($method instanceof WC_Shipping_Flat_Rate) {
                    $cost = (float)$method->get_option('cost', 0);
                    $item = new WC_Order_Item_Shipping();
                    $item->set_props([
                        'method_title' => $method->get_title(),
                        'method_id' => $method->id,
                        'instance_id' => $method->get_instance_id(),
                        'total' => $cost,
                        'taxes' => []
                    ]);
                    $order->add_item($item);
                    break;
                }
            }
        }

        // Calculate final totals
        $order->calculate_totals(false);
        $order->save();

        return (int)$updated;
    } catch (Exception $e) {
        error_log(sprintf(
            '[Subscription Extras] Error processing order %d: %s',
            $order_id,
            $e->getMessage()
        ));
        return 0;
    }
}

function wsextra_update_order_item_tax($order, $line_item)
{
    $line_item->set_rate($line_item->get_rate_id());
    $line_item->save();
}

function wsextra_match_variation_by_attributes($product, $line_item)
{
    error_log(sprintf(
        '[WSE Debug] Matching variation for product %d line item %d',
        $product->get_id(),
        $line_item->get_id()
    ));

    // Get variation attributes from line item meta
    $variation_data = [];
    foreach ($line_item->get_meta_data() as $meta) {
        if (strpos($meta->key, 'pa_') === 0 || strpos($meta->key, 'attribute_') === 0) {
            $key = strpos($meta->key, 'pa_') === 0 ? 'attribute_' . $meta->key : $meta->key;
            $patterns = [
                '/80pk/i' => '80-pack',
                '/coffee|whole/i' => '',
                '/\s+/i' => '-',
                '/-+/' => '-'
            ];

            $value = strtolower($meta->value);
            foreach ($patterns as $pattern => $replacement) {
                $value = trim(preg_replace($pattern, $replacement, $value));
            }

            $variation_data[$key] = $value;
            error_log(sprintf(
                '[WSE Debug] Found variation attribute %s = %s',
                $key,
                $meta->value
            ));
        }
    }

    if (empty($variation_data)) {
        error_log('[WSE Debug] No variation attributes found in line item');
        return null;
    }

    error_log(sprintf(
        '[WSE Debug] Looking for variation with attributes: %s',
        print_r($variation_data, true)
    ));

    // Get all variations
    $data_store = WC_Data_Store::load('product');
    $variation_id = $data_store->find_matching_product_variation($product, $variation_data);

    if ($variation_id) {
        error_log(sprintf('[WSE Debug] Found matching variation ID: %d', $variation_id));
    } else {
        error_log('[WSE Debug] No matching variation found');
    }

    return $variation_id ?: null;
}

function wsextra_update_order_item_product($order, $line_item, $fix_variations)
{
    try {
        $variation_id = $line_item->get_variation_id();
        error_log(sprintf('[WSE Debug] Variation ID: %d', $variation_id));
        $product_id = $line_item->get_product_id();
        if (!$product_id) {
            error_log(sprintf('[WSE Debug] Invalid product ID %d for order item %d', $product_id, $line_item->get_id()));
            return false;
        }

        if (!$variation_id) {
            $product = wc_get_product($product_id);
        }

        if ($variation_id && $fix_variations) {
            $product = wc_get_product($product_id);
            if ($product->is_type('variable')) {
                // Check if variation_id exists and is valid, otherwise try to match
                $matched_variation_id = null;
                if (!$variation_id || !in_array($variation_id, get_all_variations($product_id))) {
                    error_log(sprintf('[WSE Debug] Variation ID %d is not valid', $variation_id));
                    $matched_variation_id = wsextra_match_variation_by_attributes($product, $line_item);
                }
                if ($matched_variation_id) {
                    $variation_id = $matched_variation_id;
                    $line_item->set_variation_id($variation_id);
                    $line_item->save();
                }
            }
        }

        if ($variation_id) {
            $product = wc_get_product($variation_id);
        }

        // if it's not a valid product, log and exit
        if (!$product) {
            error_log(sprintf('[WSE Debug] Invalid product ID %d for order item %d', $product_id, $line_item->get_id()));
            return false;
        }

        $qty = $line_item->get_quantity();

        $original_price = $product->get_price();

        // Since we're in a subscription, we know we need a scheme
        if (class_exists('WCS_ATT_Product_Schemes')) {
            // First try to get scheme from order item
            $scheme_key = null;
            if (class_exists('WCS_ATT_Order')) {
                $scheme_key = WCS_ATT_Order::get_subscription_scheme($line_item);
            }

            // Get all available schemes for debugging
            $all_schemes = WCS_ATT_Product_Schemes::get_subscription_schemes($product);

            // If no scheme found on order item, try to find a matching scheme based on subscription billing schedule
            if (!$scheme_key) {

                if (!empty($all_schemes)) {
                    foreach ($all_schemes as $scheme) {
                        if ($scheme->matches_subscription($order)) {
                            $scheme_key = $scheme->get_key();
                            break;
                        }
                    }
                }
            }

            // Still no scheme? Fall back to default
            if (!$scheme_key) {
                $scheme_key = WCS_ATT_Product_Schemes::get_default_subscription_scheme($product, 'key');
            }

            // Apply scheme and get price
            if ($scheme_key) {
                WCS_ATT_Product_Schemes::set_subscription_scheme($product, $scheme_key);

                // Get price with the discount applied
                $base_price = $product->get_price();

                $original_price = WCS_ATT_Product_Prices::get_price($product, $scheme_key, 'edit');
            } else {
                $base_price = $product->get_price();
            }
        } else {
            $base_price = $product->get_price();
        }

        // Calculate final price with taxes
        if (wsextra_option('ignore_taxes') == 'yes') {
            $price = wc_get_price_excluding_tax($product, array(
                'qty' => $qty,
                'price' => $base_price
            ));
        } else {
            $price = $order->get_prices_include_tax() ?
                wc_get_price_including_tax($product, array(
                    'qty' => $qty,
                    'price' => $base_price
                )) :
                wc_get_price_excluding_tax($product, array(
                    'qty' => $qty,
                    'price' => $base_price
                ));
        }

        $price = (float) $price;
        $line_item->set_subtotal($original_price);
        $line_item->set_total($price);
        $line_item->save();

        return true;
    } catch (Exception $e) {
        error_log(sprintf(
            '[Subscription Extras] Error updating order item %d: %s',
            $line_item->get_id(),
            $e->getMessage()
        ));
        return false;
    }
}

function get_all_variations($product_id)
{
    global $wpdb;

    $query = "
        SELECT ID
        FROM {$wpdb->posts}
        WHERE post_parent = %d
        AND post_type = 'product_variation'
        AND post_status = 'publish'
    ";

    $variation_ids = $wpdb->get_col($wpdb->prepare($query, $product_id));

    return $variation_ids;
}

function wsextra_update_order_item_shipping($order, $line_item)
{
    // Get shipping method settings
    $option_name = 'woocommerce_' . $line_item->get_method_id() . '_' . $line_item->get_instance_id() . '_settings';
    $rate_settings = get_option($option_name);

    // Default to 0 if no cost found
    $cost = 0;

    // Safely get cost from settings
    if (is_array($rate_settings) && isset($rate_settings['cost'])) {
        $cost = wc_format_decimal($rate_settings['cost']);
    }

    // Get title safely
    $title = is_array($rate_settings) && isset($rate_settings['title']) ?
        $rate_settings['title'] :
        $line_item->get_method_title();

    // Create shipping rate with validated data
    $shipping_rate = new WC_Shipping_Rate(
        $line_item->get_method_id(),  // Method ID
        $title,                       // Title
        $cost,                        // Cost
        array(),                      // Taxes (empty array)
        $line_item->get_method_id(),  // Method ID again
        $line_item->get_instance_id() // Instance ID
    );

    // Set the shipping rate
    $line_item->set_shipping_rate($shipping_rate);
    $line_item->save();
}

function wsextra_update_shipping_method($settings, $method_obj)
{
    $previousSettings = get_option($method_obj->get_instance_option_key());

    if ($settings != $previousSettings) {

        // find orders/subscriptions that need to be updated
        $rateId = $method_obj->get_rate_id();
        $orders = wsextra_get_orders_of_shipping_rate($rateId);
        $countSubs = count($orders);

        if ($countSubs > 0) {
            $urlArgs = array();
            $urlArgs['wsextra_update'] = 1;
            $urlArgs['shipping_method_rate_id'] = $rateId;

            $message = esc_attr__('Looks like you have changed the shipping method settings.', 'woo-subscription-extras');

            $message .= ' ' . sprintf(
                '<a href="%s" onclick="return confirm(\'%s\');">%s</a>',
                add_query_arg($urlArgs, admin_url('edit.php?post_type=shop_subscription')),
                esc_attr__('This operation cannot be undone. Proceed anyway?', 'woo-subscription-extras'),
                sprintf(__('Click here to recalculate %s subscriptions with this shipping now.', 'woo-subscription-extras'), $countSubs)
            );

            $message = wp_kses($message, array(
                'a' => array('href' => array(), 'onclick' => array())
            ));

            $method_obj->add_error($message);
        }
    }

    return $settings;
}

function wsextra_check_price_change(
    WC_Product $product,
    float $oldPrice,
    float $regularPrice,
    ?float $salePrice = null
): ?array {
    $post_id = $product->get_id();
    $newPrice = $salePrice ?? $regularPrice;

    if (!($oldPrice > 0 && $newPrice > 0) || $oldPrice === $newPrice) {
        return null;
    }

    $countSubs = count(wsextra_get_orders_by_product_id($post_id));

    if ($countSubs === 0) {
        return null;
    }

    $message = match (true) {
        $product->is_type('variation') => sprintf(
            esc_attr__('Looks like you have updated the price of the variation "%s" from %s to %s.',  'woo-subscription-extras'),
            $product->get_formatted_name(),
            wc_price($oldPrice),
            wc_price($newPrice)
        ),
        default => sprintf(
            esc_attr__('Looks like you have updated the price from %s to %s.',  'woo-subscription-extras'),
            wc_price($oldPrice),
            wc_price($newPrice)
        )
    };

    $message .= sprintf(
        ' <a href="%s" onclick="return confirm(\'%s\');">%s</a>',
        wsextra_build_url_update($post_id),
        esc_attr__('This operation cannot be undone. Proceed anyway?',  'woo-subscription-extras'),
        sprintf(__('Click here to update current %s subscriptions prices now.', 'woo-subscription-extras'), $countSubs)
    );

    return [
        'message' => wp_kses($message, ['a' => ['href' => [], 'onclick' => []]]),
        'product_id' => $post_id
    ];
}
