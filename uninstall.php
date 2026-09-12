<?php
/**
 * Uninstall cleanup.
 *
 * Runs only when the plugin is deleted from the Plugins screen. It removes the
 * gateway settings (including the encrypted bKash credentials), the cached
 * bKash API tokens and any confirmation locks left behind by an interrupted
 * payment.
 *
 * @package ShaplaPayBkash
 */

defined('WP_UNINSTALL_PLUGIN') || exit;

delete_option('fluent_cart_payment_settings_bkash');
delete_option('shaplapay_bkash_token_test');
delete_option('shaplapay_bkash_token_live');

global $wpdb;

// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- One-off cleanup of this plugin's own lock options on uninstall; the table name is a WordPress core identifier and the pattern is bound through $wpdb->prepare().
$lockNames = $wpdb->get_col(
    $wpdb->prepare(
        "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s",
        $wpdb->esc_like('shaplapay_bkash_confirm_lock_') . '%'
    )
);

foreach ((array) $lockNames as $lockName) {
    delete_option($lockName);
}
