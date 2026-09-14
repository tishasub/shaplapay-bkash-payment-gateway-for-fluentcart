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

// Leftover confirmation locks are listed in this index option, so they can
// be removed through the Options API with no direct SQL.
foreach ((array) get_option('shaplapay_bkash_confirm_locks', []) as $shaplaPayBkashLockName) {
    // phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Short alphabetic option name from this plugin's own index, never output, a URL or a translatable string.
    if (is_string($shaplaPayBkashLockName) && strpos($shaplaPayBkashLockName, 'shaplapay_bkash_confirm_lock_') === 0) {
        delete_option($shaplaPayBkashLockName);
    }
    // phpcs:enable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
}

delete_option('shaplapay_bkash_confirm_locks');
