<?php



if ( ! defined( 'ABSPATH' ) ) exit;

defined('WSEXTRA_BASE_FILE') || define('WSEXTRA_BASE_FILE', __FILE__);

add_action('init', 'wsextra_init');
function wsextra_init() {
    // initialize common lib
    include_once( 'common/MHCommon.php' );
    MHCommon::initializeV2(
        'woo-subscription-extras',
        'wse',
        WSEXTRA_BASE_FILE,
        __('WooCommerce Subscription Extras', 'woo-subscription-extras')
    );

    // plugin checks
    if ( !empty($_GET['wsextra_update']) ) {
        wsextra_redirect_subscriptions_update();
    }

    // dynamic events
    if ( wsextra_option('auto_ask_shipping_change') == 'yes' && class_exists('WooCommerce') ) {
        $methods = WC()->shipping->get_shipping_methods();

        foreach ( $methods as $method ) {
            add_filter('woocommerce_shipping_' . $method->id . '_instance_settings_values', 'wsextra_update_shipping_method', 10, 2);
        }
    }

    // fire plugin callbacks
    do_action('wsextra_after_init');
}

add_filter('mh_wse_settings', 'wsextra_settings');
function wsextra_settings($arr) {
    $arr['auto_ask_price_change'] = array(
        'label' => esc_attr__('Ask to change subscriptions prices when changed (on product save)', 'woo-subscription-extras'),
        'tab' => esc_attr__('General', 'woo-subscription-extras'),
        'type' => 'checkbox',
        'default' => 'yes',
    );
    $arr['auto_ask_shipping_change'] = array(
        'label' => esc_attr__('Ask to recalculate subscriptions when change shipping methods (on shipping settings tab)', 'woo-subscription-extras'),
        'tab' => esc_attr__('General', 'woo-subscription-extras'),
        'type' => 'checkbox',
        'default' => 'yes',
    );
    $arr['ignore_taxes'] = array(
        'label' => esc_attr__('Ignore customer taxes in subscription prices', 'woo-subscription-extras'),
        'tab' => esc_attr__('General', 'woo-subscription-extras'),
        'type' => 'checkbox',
        'default' => 'no',
    );

    return $arr;
}

add_filter('mh_wse_premium_url', 'wsextra_premium_url');
function wsextra_premium_url() {
    return 'http://gum.co/wsubextras';
}

add_action('save_post_product', 'wsextra_save_post_product', 10, 3);
function wsextra_save_post_product($post_id, $post, $update) {
    if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || !$update ) {
        return;
    }

    if ( wsextra_option('auto_ask_price_change') != 'yes' ) {
        return;
    }

    $product = wc_get_product($post_id);
    $changes = array();
    $isTypeSub = $product->is_type(array('downloadable_subscription', 'subscription', 'variable-subscription', 'virtual_subscription'));

    if ( $isTypeSub || $product->is_type('simple') ) {
        $regularPrice = wc_format_decimal( sanitize_text_field( filter_input(INPUT_POST, '_regular_price')));
        $salePrice = wc_format_decimal( sanitize_text_field( filter_input(INPUT_POST, '_sale_price')));
        $oldPrice = $product->get_price();
        
        $changes[] = wsextra_check_price_change($product, $oldPrice, $regularPrice, $salePrice);
    }

    $changes = apply_filters('wsextra_product_save_check', $changes, $product);
    $changes = array_filter($changes);

    if ( !empty($changes) ) {
        set_transient('wsextra_price_change', $changes, 60);
    }
    else {
        delete_transient('wsextra_price_change');
    }
}


add_action('admin_notices', 'wsextra_check_notices', 10);   
function wsextra_check_notices() {
    global $pagenow;

    if ( ( $pagenow == 'edit.php' ) && !empty($_GET['wsextra_updateds']) ) {
        $updateds = (int) sanitize_text_field( filter_input(INPUT_GET, 'wsextra_updateds') );
        $message = sprintf(__('A total of %d subscriptions have been updated successfully!', 'woo-subscription-extras'), $updateds);

        ?>
        <div class="notice is-dismissible notice-success">
            <p><?php echo esc_attr__($message); ?></p>
        </div>
        <?php
    }

    if ( ( $pagenow == 'post.php' ) && get_transient('wsextra_price_change') ) {
        wsextra_show_price_changed_notice( get_transient('wsextra_price_change') );
        delete_transient('wsextra_price_change');
    }
}

add_filter('post_row_actions', 'wsextra_sync_action', 10, 2);
function wsextra_sync_action($actions, $post) {
    global $the_product;

    if ( 'product' !== $post->post_type ) {
        return $actions;
    }

    if ( empty( $the_product ) || $the_product->get_id() !== $post->ID ) {
        $the_product = wc_get_product( $post );
    }

    if ( !in_array( $the_product->get_type(), array('variable-subscription', 'subscription') )) {
        return $actions;
    }
    
    $url = wsextra_build_url_update($post->ID);
    $hint = esc_attr__('Synchronize subscriptions prices with this product', 'woo-subscription-extras');
    $label = esc_attr__('Synchronize prices', 'woo-subscription-extras');

    $actions['wsextra_sync'] = '<a href="' . $url . '" aria-label="' . $hint . '" rel="permalink">' . $label . '</a>';

    return $actions;
}

add_action( 'woocommerce_product_options_general_product_data', 'wsextra_subscription_pricing_fields', 20 );
function wsextra_subscription_pricing_fields() {
    global $post;

    $product = wc_get_product( $post->ID );

    if ( !$product->is_type('subscription') ) {
        return;
    }

    echo '<div class="options_group">';

    woocommerce_wp_text_input( array(
        'id'          => '_subscription_start_date',
        'class'       => 'wc_input_subscription_start_date short',
        'label'       => sprintf( __( 'Subscription start date', 'woo-subscription-extras' ) ),
        'placeholder' => _x( '', 'woo-subscription-extras' ),
        'description' => __( 'When filled, this field will change the subscription date to future, instead of the default (current date)', 'woo-subscription-extras' ),
        'desc_tip'    => true,
        'type'        => 'text',
        'data_type'   => 'date',
    ) );

    woocommerce_wp_text_input( array(
        'id'          => '_subscription_next_payment',
        'class'       => 'wc_input_subscription_next_payment short',
        'label'       => sprintf( __( 'Subscription next payment', 'woo-subscription-extras' ) ),
        'placeholder' => _x( '', 'woo-subscription-extras' ),
        'description' => __( 'When filled, this field will change the subscription Next Payment date', 'woo-subscription-extras' ),
        'desc_tip'    => true,
        'type'        => 'text',
        'data_type'   => 'date',
    ) );

    echo '</div>';

    ?>
    <script type="text/javascript">
        jQuery(document).ready(function($) {
            $('.wc_input_subscription_start_date,.wc_input_subscription_next_payment').datepicker({
                dateFormat : 'yy-mm-dd'
            });
        });
    </script>
    <?php

    wp_nonce_field( 'wsextra_subscription_meta', '_wsenonce' );
}

add_action( 'save_post', 'wsextra_save_subscription_meta', 11 );
function wsextra_save_subscription_meta( $post_id ) {
    if ( empty( $_POST['_wsenonce'] ) || ! wp_verify_nonce( $_POST['_wsenonce'], 'wsextra_subscription_meta' ) ) {
        return;
    }

    // save start date
    $start_date = isset( $_REQUEST['_subscription_start_date'] ) ? $_REQUEST['_subscription_start_date'] : '';
    
    if ( !empty($start_date) ) {
        update_post_meta( $post_id, '_subscription_start_date', $start_date );
    }
    else {
        $db_start_date = get_post_meta( $post_id, '_subscription_start_date', true );

        if ( !empty($db_start_date) ) {
            delete_post_meta( $post_id, '_subscription_start_date');
        }
    }

    // save next payment date
    $next_payment = isset( $_REQUEST['_subscription_next_payment'] ) ? $_REQUEST['_subscription_next_payment'] : '';

    if ( !empty($next_payment) ) {
        update_post_meta( $post_id, '_subscription_next_payment', $next_payment );
    }
    else {
        $db_next_payment = get_post_meta( $post_id, '_subscription_next_payment', true );

        if ( !empty($db_next_payment) ) {
            delete_post_meta( $post_id, '_subscription_next_payment');
        }
    }
}

add_action( 'wcs_create_subscription', 'wsextra_create_subscription' );
function wsextra_create_subscription($subscription) {

    foreach ( $subscription->get_parent()->get_items() as $item ) {

        $post_id = $item->get_product_id();

        // check configured start date
        $start_date = get_post_meta( $post_id, '_subscription_start_date', true );

        if ( !empty($start_date) ) {
            $subscription_id = $subscription->get_id();
            $start_date .= ' 00:00:00';
            update_post_meta( $subscription_id, '_schedule_start', $start_date );
        }

        // check configured next payment
        $next_payment = get_post_meta( $post_id, '_subscription_next_payment', true );

        if ( !empty($next_payment) ) {
            $subscription_id = $subscription->get_id();
            $next_payment .= ' 00:00:00';
            update_post_meta( $subscription_id, '_schedule_next_payment', $next_payment );
        }
    }
}

function wsextra_option($name) {
    return apply_filters('mh_wse_setting_value', $name);
}

function wsextra_build_url_update($product_id) {
    $urlArgs = array();
    $urlArgs['wsextra_update'] = 1;
    $urlArgs['product_id'] = $product_id;

    return add_query_arg($urlArgs, admin_url('edit.php?post_type=shop_subscription'));
}

function wsextra_show_price_changed_notice($messages) {
    foreach ( (array)$messages as $data ) {
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

function wsextra_get_orders_by_product_id( $product_id, $limit = null, $offset = null ){
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

    if ( !empty($limit) ) {
        $sql .= " LIMIT %d";
        $args[] = $limit;
    }

    if ( !empty($offset) ) {
        $sql .= " OFFSET %d";
        $args[] = $offset;
    }

    return $wpdb->get_col( $wpdb->prepare($sql, $args) );
}

function wsextra_get_orders_of_shipping_rate($shipping_rate_id) {
    global $wpdb;

    $sql = "
        SELECT item.order_id
        FROM wp_woocommerce_order_items item
        INNER JOIN wp_woocommerce_order_itemmeta meta ON (item.order_item_id = meta.order_item_id)
        AND meta_key = 'method_id'
        AND meta_value = '%s'";

    $args = array();
    $args[] = $shipping_rate_id;

    return $wpdb->get_col( $wpdb->prepare($sql, $args) );
}

function wsextra_redirect_subscriptions_update() {
    if  ( defined('WC_PLUGIN_FILE') ) {
        require_once dirname(WC_PLUGIN_FILE) . '/includes/wc-cart-functions.php';
    }

    $product_id = (int) sanitize_text_field( filter_input(INPUT_GET, 'product_id') );
    $shipping_method_rate_id = sanitize_text_field( filter_input(INPUT_GET, 'shipping_method_rate_id') );

    if ( !empty($product_id) ) {
        $args = array(
            'product_id' => $product_id,
        );
    }
    else if (!empty($shipping_method_rate_id)) {
        $args = array(
            'shipping_method_rate_id' => $shipping_method_rate_id,
        );
    }

    if ( empty($args) ) {
        exit("invalid subscription update data");
    }

    // disable php 7.1+ annoying errors :)
    error_reporting( E_CORE_ERROR | E_COMPILE_ERROR | E_ERROR | E_PARSE | E_USER_ERROR | E_RECOVERABLE_ERROR );

    // let the PREMIUM plugin do the job
    if ( apply_filters('wsextra_custom_update_subs', false) ) {
        do_action('wsextra_update_subscriptions', $args);
        return;
    }

    $updateds = 0;
    $orders = wsextra_get_orders_to_update($args);

    foreach ( $orders as $order_id ) {
        $updateds += wsextra_update_subscription_order($order_id, $args);
    }

    wp_safe_redirect(add_query_arg(
        array('wsextra_updateds' => $updateds),
        admin_url('edit.php?post_type=shop_subscription')
    ));

    exit;
}

function wsextra_get_orders_to_update($args) {
    $orders = array();

    if (!empty($args['product_id'])) {
        $product_id = $args['product_id'];
        $orders = wsextra_get_orders_by_product_id($product_id);
    }
    else if (!empty($args['shipping_method_rate_id'])) {
        $method_rate_id = $args['shipping_method_rate_id'];
        $orders = wsextra_get_orders_of_shipping_rate($method_rate_id);
    }

    return $orders;
}

function wsextra_update_subscription_order($order_id, $args = null) {
    $order = wc_get_order( $order_id );
    $order->set_prices_include_tax( 'yes' === get_option( 'woocommerce_prices_include_tax' ) );

	// emulate order customer to calculate correct shipping and taxes
	$customer_id = $order->get_customer_id();
	$customer = new WC_Customer($customer_id, false);
	WC()->customer = $customer;
	
	if ( empty(WC()->session) ) {
		WC()->initialize_session();
	}

    foreach ( $order->get_items('tax') as $line_item ) {
        wsextra_update_order_item_tax($order, $line_item);
    }

    foreach ( $order->get_items() as $line_item ) {
        wsextra_update_order_item_product($order, $line_item);
    }

    foreach ( $order->get_items('shipping') as $line_item ) {
        wsextra_update_order_item_shipping($order, $line_item);
    }

    $coupons = $order->get_coupons();

    if ( !empty($coupons) ) {
        $order->recalculate_coupons();
    }
    
    $order->calculate_shipping();
    $order->calculate_taxes();
    $order->calculate_totals();    

    return 1;
}

function wsextra_update_order_item_tax($order, $line_item) {
    $line_item->set_rate( $line_item->get_rate_id() );
    $line_item->save();
}

function wsextra_update_order_item_product($order, $line_item) {
    $prodOrVariationId = $line_item->get_variation_id() > 0 ? $line_item->get_variation_id() : $line_item->get_product_id();
    $product = wc_get_product( $prodOrVariationId );
    $qty = $line_item->get_quantity();

    // handle variable subscriptions
    if ( $product instanceof WC_Product_Variable_Subscription ) {
        $variations = $product->get_available_variations();

        foreach ( $variations as $variation ) {
            $variation_id = $variation['variation_id'];

            if ( $line_item->get_variation_id() == $variation_id ) {
                $price = ( $qty * $variation['display_price'] );
            }
        }
    }
    else if ( wsextra_option('ignore_taxes') == 'yes' ) {
        $price = wc_get_price_excluding_tax($product, array('qty' => $qty));
    }
    else {
        // handle normal subscriptions
        $price = $order->get_prices_include_tax() ?
            wc_get_price_excluding_tax($product, array('qty' => $qty)) :
            wc_get_price_including_tax($product, array('qty' => $qty));
    }

    $price = (float) $price;

    $line_item->set_total( $price );
    $line_item->set_subtotal( $price );
    $line_item->save();

    return $product;
}

function wsextra_update_order_item_shipping($order, $line_item) {
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

function wsextra_update_shipping_method($settings, $method_obj) {
    $previousSettings = get_option( $method_obj->get_instance_option_key() );

    if ( $settings != $previousSettings ) {
        
        // find orders/subscriptions that need to be updated
        $rateId = $method_obj->get_rate_id();
        $orders = wsextra_get_orders_of_shipping_rate($rateId);
        $countSubs = count($orders);
        
        if ( $countSubs > 0 ) {
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

function wsextra_check_price_change($product, $oldPrice, $regularPrice, $salePrice = null) {
    $post_id = $product->get_id();
    $message = null;
    $newPrice = !empty($salePrice) ? $salePrice : $regularPrice;

    if ( !( $oldPrice > 0 ) || !( $newPrice > 0 ) ) {
        return;
    }

    if ( $oldPrice == $newPrice ) {
        return;
    }

    $countSubs = count(wsextra_get_orders_by_product_id($post_id));
    
    if ( $countSubs > 0 ) {
        if ( $product->is_type('variation') ) {
            $message = sprintf(
                esc_attr__('Looks like you have updated the price of the variation "%s" from %s to %s.',  'woo-subscription-extras'),
                $product->get_formatted_name(),
                wc_price($oldPrice),
                wc_price($newPrice)
            );
        }
        else {
            $message = sprintf(
                esc_attr__('Looks like you have updated the price from %s to %s.',  'woo-subscription-extras'),
                wc_price($oldPrice),
                wc_price($newPrice)
            );
        }

        $message .= ' ' . sprintf(
            '<a href="%s" onclick="return confirm(\'%s\');">%s</a>',
            wsextra_build_url_update($post_id),
            esc_attr__('This operation cannot be undone. Proceed anyway?',  'woo-subscription-extras'),
            sprintf(__('Click here to update current %s subscriptions prices now.', 'woo-subscription-extras'), $countSubs)
        );

        $message = wp_kses($message, array(
            'a' => array('href' => array(), 'onclick' => array())
        ));

        $data = array();
        $data['message'] = $message;
        $data['product_id'] = $post_id;

        return $data;
    }

    return null;
}
