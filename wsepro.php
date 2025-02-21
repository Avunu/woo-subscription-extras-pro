<?php

// declare(strict_types=1);

/*
Plugin Name: WooCommerce Subscription Extras PRO
Plugin URI: https://wordpress.org/plugins/woo-subscription-extras/
Description: Extra features for WooCommerce Subscriptions Extension
Version: 1.0.23
Author: Moises Heberle
Author URI: http://codecanyon.net/user/moiseh
Text Domain: woo-subscription-extras
Domain Path: /i18n/languages/
*/

defined('WSEXTRA_BASE_FILE') || define('WSEXTRA_BASE_FILE', __FILE__);

if (!function_exists('wsextra_init')) {
    include_once 'wselite.php';
}

register_activation_hook(__FILE__, 'wsepro_activate');
function wsepro_activate(): void
{
    if (is_plugin_active('woo-subscription-extras/wselite.php')) {
        deactivate_plugins('woo-subscription-extras/wselite.php');
    }
}

add_filter('mh_wse_is_premium', 'wsepro_is_premium');
function wsepro_is_premium(): bool
{
    return true;
}

add_filter('mh_wse_settings', 'wsepro_settings');
function wsepro_settings(array $arr): array
{
    $arr['enable_background_update'] = [
        'label' => esc_html__('Enable background subscription prices update (recommended for large amounts of subscriptions)', 'woo-subscription-extras'),
        'tab' => esc_html__('General', 'woo-subscription-extras'),
        'type' => 'checkbox',
        'default' => 'yes',
    ];
    $arr['background_process_per_run'] = [
        'label' => esc_html__('Number of subscriptions to process per execution', 'woo-subscription-extras'),
        'tab' => esc_html__('General', 'woo-subscription-extras'),
        'type' => 'number',
        'default' => 100,
        'min' => 1,
        'max' => 1000,
        'depends_on' => 'enable_background_update',
    ];

    return $arr;
}

add_action('wsextra_after_init', 'wsepro_after_init');
function wsepro_after_init(): void
{
    if (!empty($_GET['wsextra_abort_background'])) {
        delete_option('wsextra_batch_price_update');
    }

    if (get_option('wsextra_batch_price_update') && defined('DOING_AJAX')) {
        wsepro_run_batch_price_update();
    }
}

add_action('mh_wse_admin_buttons', 'wsepro_admin_buttons');
function wsepro_admin_buttons()
{
?>
    <input name="save" class="button-primary"
        style="background: green;"
        onclick="return confirm('<?php echo esc_html__('Confirm this operation?'); ?>');"
        value="<?php echo esc_html__('Update all subscription prices'); ?>"
        type="submit" />
    <?php
}

add_action('mh_wse_trigger_save', 'wsepro_trigger_save', 10, 2);
function wsepro_trigger_save($btnClick)
{
    if ($btnClick) {
        WC()->frontend_includes();
        WC()->initialize_session();

        $posts = array(
            'posts_per_page'   => -1,
            'orderby'          => 'title',
            'order'            => 'asc',
            'post_type'        => 'shop_subscription',
            'post_status'      => 'active',
        );

        $orders = get_posts($posts);
        $count = count($orders);

        echo "<br/>";

        foreach ($orders as $order) {
            $order_id = $order->ID;
            $url = admin_url("post.php?post={$order_id}&action=edit");

            wsextra_update_subscription_order($order_id);
            echo "<br/>- The order <a href=\"{$url}\">{$order_id}</a> was updated<br/>\n";
            flush();
        }

        echo "<br/><br/>";
        echo "<strong>Total updated orders: {$count}</strong>";
        echo "<br/><br/>";

    ?>
        <input name="back" class="button-primary"
            value="<?php echo esc_html__('Back'); ?>"
            onclick="return window.history.back();"
            type="button" />
        <?php
        exit;
    }
}


add_action('admin_notices', 'wsepro_check_notices', 10);
function wsepro_check_notices()
{
    global $pagenow;

    if (($pagenow == 'edit.php')) {
        if (get_option('wsextra_batch_price_update')) {
            $batch = get_option('wsextra_batch_price_update');

            if ($batch['finished']) {
                delete_option('wsextra_batch_price_update');

                $message = sprintf(
                    __('A total of %s subscriptions have been updated successfully.', 'woo-subscription-extras'),
                    $batch['processed']
                );

        ?>
                <div class="notice is-dismissible notice-success">
                    <p><?php echo esc_html__($message); ?></p>
                </div>
            <?php
            } else {
                $percentage = wsepro_batch_progress() . '%';

                $urlReload = admin_url('edit.php?post_type=shop_subscription');

                $urlAbort = add_query_arg(
                    array('wsextra_abort_background' => 1),
                    admin_url('edit.php?post_type=shop_subscription')
                );

                $message = sprintf(
                    __('Subscriptions update is running in background. Current progress: <span class="wse-progress">%s</span>. <a href="%s">Cancel background process</a>.', 'woo-subscription-extras'),
                    $percentage,
                    $urlAbort
                );

                $message = wp_kses($message, array(
                    'a' => array('href' => array()),
                    'span' => array('class' => array())
                ));

            ?>
                <div class="notice is-dismissible notice-warning wse-updating">
                    <p><?php echo $message; ?></p>
                </div>
                <?php wsepro_print_progress_script(); ?>
    <?php
            }
        }
    }
}

add_filter('wsextra_custom_update_subs', 'wsepro_custom_update_subs');
function wsepro_custom_update_subs()
{
    return (wsextra_option('enable_background_update') == 'yes');
}

add_action('wsextra_update_subscriptions', 'wsepro_update_subscriptions');
function wsepro_update_subscriptions(array $args): never
{
    $orders = wsextra_get_orders_to_update($args);

    $args['processed'] = 0;
    $args['total'] = count($orders);
    $args['finished'] = false;
    $args['orders'] = $orders;
    update_option('wsextra_batch_price_update', $args);

    $url = admin_url('edit.php?post_type=shop_subscription');
    wp_safe_redirect($url);
    exit;
}

add_action('wp_ajax_woocommerce_save_variations', 'wsextra_save_variations');
function wsextra_save_variations(): void
{
    $max_loop = max(array_keys(wp_unslash($_POST['variable_post_id'] ?? [])));
    $product_id = absint($_POST['product_id'] ?? 0);
    $data = [];

    for ($i = 0; $i <= $max_loop; $i++) {
        if (!isset($_POST['variable_post_id'][$i])) {
            continue;
        }

        $variation_id = absint($_POST['variable_post_id'][$i]);
        $variation = new WC_Product_Variation($variation_id);

        $old_price = $variation->get_price();
        $regular_price = $_POST['variable_regular_price'][$i] ?? null;
        $sale_price = $_POST['variable_sale_price'][$i] ?? null;
        $new_price = $sale_price ?: $regular_price;

        if ($old_price !== $new_price) {
            $data[] = [
                'variation_id' => $variation_id,
                'old_price' => $old_price,
                'new_price' => $new_price,
                'regular_price' => $regular_price,
                'sale_price' => $sale_price,
            ];
        }
    }

    match (empty($data)) {
        true => delete_transient('wsextra_variations_change_' . $product_id),
        false => set_transient('wsextra_variations_change_' . $product_id, $data, 600),
    };
}

add_filter('wsextra_product_save_check', 'wsepro_product_save_check', 10, 2);
function wsepro_product_save_check($changes, $product)
{
    $post_id = $product->get_id();

    if ($product->is_type('variable')) {
        $variations = $product->get_available_variations();
        $admChanges = get_transient('wsextra_variations_change_' . $post_id, array());

        foreach ($admChanges as $changed) {
            $vProduct = wc_get_product($changed['variation_id']);
            $changes[] = wsextra_check_price_change($vProduct, $changed['old_price'], $changed['regular_price'], $changed['sale_price']);
        }
    }

    return $changes;
}

add_action("wp_ajax_nopriv_wsepro_batch_status", "wsepro_batch_status");
add_action("wp_ajax_wsepro_batch_status", "wsepro_batch_status");
function wsepro_batch_status()
{
    $percent = wsepro_batch_progress();

    echo json_encode(['progress' => $percent]);
    exit;
}

function wsepro_run_batch_price_update()
{
    $batch = get_option('wsextra_batch_price_update');
    $runPerBatch = defined('WP_CLI') && WP_CLI ? 500 : wsextra_option('background_process_per_run');

    if ($batch['finished'] || get_transient('wsextra_running_batch')) {
        return;
    }

    $lastTime = null;

    for ($i = 0; ($i < $runPerBatch && !empty($batch['orders'])); $i++) {

        // every 3 seconds set the running flag
        if (empty($time) || ((time() - $lastTime) >= 3)) {
            $lastTime = time();
            set_transient('wsextra_running_batch', 1, 7);
        }

        $order_id = array_shift($batch['orders']);
        $updated = wsextra_update_subscription_order($order_id, $batch);

        $batch['processed'] += $updated;
    }

    if (empty($batch['orders'])) {
        $batch['finished'] = true;
    }

    update_option('wsextra_batch_price_update', $batch);
}

function wsepro_batch_progress(): float
{
    $batch = get_option('wsextra_batch_price_update');

    return round(($batch['processed'] * 100) / $batch['total'], 0);
}

function wsepro_print_progress_script()
{
    $ajaxEndpoint = get_admin_url() . 'admin-ajax.php';
    $urlReload = admin_url('edit.php?post_type=shop_subscription');

    ?>
    <script>
        (function($) {
            "use strict";

            var ajaxRequestStatus = function() {
                var opts = {
                    action: 'wsepro_batch_status'
                };

                $.ajax({
                    data: opts,
                    type: 'post',
                    dataType: 'json',
                    url: '<?php echo esc_attr__($ajaxEndpoint); ?>',
                    success: function(resp) {
                        $('.wse-progress').html(resp.progress + '%');

                        if (resp.progress >= 100) {
                            setTimeout(function() {
                                location.href = '<?php echo esc_attr__($urlReload); ?>';
                            }, 1000);
                        }
                    }
                }).always(function() {
                    doNextAjax();
                });
            };

            var doNextAjax = function() {
                setTimeout(ajaxRequestStatus, 3000);
            };

            jQuery(document).ready(function($) {
                doNextAjax();
            });
        })(jQuery);
    </script>
<?php
}

// Add WP-CLI command support
if (defined('WP_CLI') && WP_CLI) {
    require_once dirname(__FILE__) . '/includes/class-wsepro-cli.php';
    WP_CLI::add_command('wsepro', 'WSEPRO_CLI');
}

// Modify the batch size for CLI operations
add_filter(
    'wsepro_batch_size',
    fn(int $batch_size): int =>
    defined('WP_CLI') && WP_CLI ? 500 : $batch_size
);
