<?php

namespace ShaplaPayBkash;

use FluentCart\Api\StoreSettings;
use FluentCart\App\Helpers\Helper;
use FluentCart\App\Modules\PaymentMethods\Core\BaseGatewaySettings;

class BkashSettings extends BaseGatewaySettings
{
    public $methodHandler = 'fluent_cart_payment_settings_bkash';

    public function __construct()
    {
        parent::__construct();

        $settings = $this->getCachedSettings();
        $defaults = static::getDefaults();

        if (!$settings || !is_array($settings) || empty($settings)) {
            $settings = $defaults;
        } else {
            $settings = wp_parse_args($settings, $defaults);
        }

        $this->settings = apply_filters('fluent_cart/bkash/settings', $settings);
    }

    public static function getDefaults(): array
    {
        return [
            'is_active'              => 'no',
            'integration_mode'       => 'api',
            'manual_wallet_number'   => '',
            'manual_instructions'    => '',
            'manual_capture'         => 'reference_only',
            'manual_notice_position' => 'thank_you',
            'manual_checkout_notice' => '',
            'test_app_key'    => '',
            'test_app_secret' => '',
            'test_username'   => '',
            'test_password'   => '',
            'live_app_key'    => '',
            'live_app_secret' => '',
            'live_username'   => '',
            'live_password'   => '',
        ];
    }

    public function isActive(): bool
    {
        return ($this->settings['is_active'] ?? 'no') === 'yes';
    }

    /**
     * "api" talks to the bKash Payment Gateway. "manual" is the no-credentials
     * fallback: the store shows its wallet number and the merchant confirms
     * incoming transfers by hand.
     */
    public function getIntegrationMode(): string
    {
        return $this->get('integration_mode') === 'manual' ? 'manual' : 'api';
    }

    public function getWalletNumber(): string
    {
        return (string) $this->get('manual_wallet_number');
    }

    public function getManualInstructions(): string
    {
        return (string) $this->get('manual_instructions');
    }

    /**
     * How a manual transfer is confirmed: "reference_only" leaves the merchant
     * to verify each transfer by hand, "customer_trx_id" also asks the customer
     * to submit the bKash Transaction ID they received.
     */
    public function getManualCapture(): string
    {
        return $this->get('manual_capture') === 'customer_trx_id' ? 'customer_trx_id' : 'reference_only';
    }

    /**
     * "thank_you" shows the payment details on the confirmation page only.
     * "both" also shows a notice under the bKash option at checkout.
     */
    public function getManualNoticePosition(): string
    {
        return $this->get('manual_notice_position') === 'both' ? 'both' : 'thank_you';
    }

    public function getManualCheckoutNotice(): string
    {
        return (string) $this->get('manual_checkout_notice');
    }

    public function get($key = '')
    {
        if ($key && isset($this->settings[$key])) {
            return $this->settings[$key];
        }

        return $this->settings;
    }

    /**
     * Mirrors the core gateways (Mollie/Stripe): the credential set is chosen
     * by the store-wide Order Mode (Settings > Store), test or live.
     */
    public function getMode(): string
    {
        $mode = (new StoreSettings())->get('order_mode');

        return $mode === 'live' ? 'live' : 'test';
    }

    private function getDecrypted(string $name): string
    {
        $key = $this->getMode() . '_' . $name;

        return (string) Helper::decryptKey($this->settings[$key] ?? '');
    }

    public function getAppKey(): string
    {
        return $this->getDecrypted('app_key');
    }

    public function getAppSecret(): string
    {
        return $this->getDecrypted('app_secret');
    }

    public function getUsername(): string
    {
        return $this->getDecrypted('username');
    }

    public function getPassword(): string
    {
        return $this->getDecrypted('password');
    }

    public function getBaseUrl(): string
    {
        $env = $this->getMode() === 'live' ? 'pay' : 'sandbox';

        $url = "https://tokenized.{$env}.bka.sh/v1.2.0-beta/tokenized/checkout";

        return apply_filters('fluent_cart/bkash/base_url', $url, $this->getMode());
    }
}
