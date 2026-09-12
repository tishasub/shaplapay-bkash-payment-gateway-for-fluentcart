<?php
/**
 * Plugin Name: ShaplaPay – bKash Payment Gateway for FluentCart
 * Description: Third-party plugin by ShaplaPay. Accepts bKash payments on the FluentCart checkout through the bKash Payment Gateway tokenized checkout API. Redirect based checkout with server side payment execution, order status sync and refunds. Not affiliated with bKash Limited or the FluentCart team.
 * Version: 1.0.3
 * Requires at least: 6.7
 * Requires PHP: 7.4
 * Requires Plugins: fluent-cart
 * Author: Tisha
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: shaplapay-bkash-payment-gateway-for-fluentcart
 *
 * @package ShaplaPayBkash
 */

defined('ABSPATH') or die('No direct script access allowed.');

define('SHAPLAPAY_BKASH_VERSION', '1.0.3');
define('SHAPLAPAY_BKASH_PATH', plugin_dir_path(__FILE__));
define('SHAPLAPAY_BKASH_URL', plugin_dir_url(__FILE__));
define('SHAPLAPAY_BKASH_FILE', __FILE__);

spl_autoload_register(function ($class) {
    $prefix = 'ShaplaPayBkash\\';

    if (strpos($class, $prefix) !== 0) {
        return;
    }

    $relative = substr($class, strlen($prefix));
    $file = SHAPLAPAY_BKASH_PATH . 'src/' . str_replace('\\', '/', $relative) . '.php';

    if (file_exists($file)) {
        require_once $file;
    }
});

add_action('fluent_cart/register_payment_methods', function () {
    if (!class_exists('\FluentCart\App\Modules\PaymentMethods\Core\GatewayManager')) {
        return;
    }

    $manager = \FluentCart\App\Modules\PaymentMethods\Core\GatewayManager::getInstance();

    if ($manager->get('bkash')) {
        return;
    }

    try {
        $manager->register('bkash', new \ShaplaPayBkash\Bkash());
    } catch (\Throwable $e) {
        // Surface the failure in FluentCart's own error log so the site
        // owner can see why the payment method did not appear.
        fluent_cart_error_log('ShaplaPay bKash: gateway registration failed', $e->getMessage());
    }
});

if (is_admin()) {
    (new \ShaplaPayBkash\Admin\BkashReport())->register();
}

add_action('admin_notices', function () {
    if (defined('FLUENTCART_VERSION') || !current_user_can('activate_plugins')) {
        return;
    }

    printf(
        '<div class="notice notice-error"><p>%s</p></div>',
        esc_html__('ShaplaPay bKash Payment Gateway needs the FluentCart plugin to be installed and active. Activate FluentCart and this notice will go away.', 'shaplapay-bkash-payment-gateway-for-fluentcart')
    );
});
