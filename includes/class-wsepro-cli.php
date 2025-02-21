<?php
/**
 * WP-CLI commands for WooCommerce Subscription Extras PRO
 */

class WSEPRO_CLI extends WP_CLI_Command {

    /**
     * Updates subscription prices for all active subscriptions.
     * 
     * ## OPTIONS
     * 
     * [--dry-run]
     * : Preview changes without updating
     * 
     * [--product=<product_id>]
     * : Only update subscriptions for specific product ID
     * 
     * [--fix-variations]
     * : Attempt to fix missing or invalid variation IDs
     * 
     * ## EXAMPLES
     *
     *     wp wsepro update_prices
     *     wp wsepro update_prices --dry-run
     *     wp wsepro update_prices --product=123
     *     wp wsepro update_prices --fix-variations
     *
     * @when after_wp_load
     */
    public function update_prices($args, $assoc_args) {
        $dry_run = isset($assoc_args['dry-run']);
        $product_id = isset($assoc_args['product']) ? absint($assoc_args['product']) : null;
        $fix_variations = isset($assoc_args['fix-variations']);

        WP_CLI::log('Starting subscription price update...');

        // Initialize WC session for proper price calculations
        if (defined('WC_PLUGIN_FILE')) {
            require_once dirname(WC_PLUGIN_FILE) . '/includes/wc-cart-functions.php';
        }
        WC()->frontend_includes();
        WC()->initialize_session();

        // Get active subscriptions
        if ($product_id) {
            $orders = wsextra_get_orders_by_product_id($product_id);
            if (empty($orders)) {
                WP_CLI::error(sprintf('No active subscriptions found for product ID %d', $product_id));
                return;
            }
        } else {
            // Get all active subscriptions
            $subscriptions = wcs_get_subscriptions(array(
                'subscriptions_per_page' => -1,
                // 'subscription_status' => 'active'
            ));
            
            $orders = array_map(function($sub) {
                return $sub->get_id();
            }, $subscriptions);
        }

        $total = count($orders);
        
        if ($total === 0) {
            WP_CLI::error('No active subscriptions found');
            return;
        }

        $processed = 0;
        
        WP_CLI::log(sprintf('Found %d subscriptions to process', $total));

        $progress = \WP_CLI\Utils\make_progress_bar('Updating subscription prices', $total);

        foreach ($orders as $order_id) {
            if ($dry_run) {
                WP_CLI::log(sprintf('Would update subscription #%d (dry run)', $order_id));
            } else {
                $updated = wsextra_update_subscription_order($order_id, null, $fix_variations);
                if ($updated) {
                    $processed++;
                }
            }
            $progress->tick();
        }

        $progress->finish();

        if ($dry_run) {
            WP_CLI::success(sprintf('Dry run complete. Would update %d subscription(s)', $total));
        } else {
            WP_CLI::success(sprintf('Updated %d of %d subscription(s)', $processed, $total));
        }
    }
}
