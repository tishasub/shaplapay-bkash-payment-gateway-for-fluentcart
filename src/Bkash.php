<?php

namespace ShaplaPayBkash;

use ShaplaPayBkash\Api\BkashApi;
use FluentCart\Api\CurrencySettings;
use FluentCart\Api\StoreSettings;
use FluentCart\App\Helpers\Helper;
use FluentCart\App\Modules\PaymentMethods\Core\AbstractPaymentGateway;
use FluentCart\App\Services\Payments\PaymentInstance;
use FluentCart\Framework\Support\Arr;

class Bkash extends AbstractPaymentGateway
{
    private $methodSlug = 'bkash';

    public array $supportedFeatures = [
        'payment',
        'refund',
    ];

    public function __construct()
    {
        parent::__construct(new BkashSettings());
    }

    public function meta(): array
    {
        $logo = SHAPLAPAY_BKASH_URL . 'assets/images/bkash-logo.svg';

        return [
            'title'              => __('bKash', 'shaplapay-bkash-payment-gateway-for-fluentcart'),
            'route'              => $this->methodSlug,
            'slug'               => $this->methodSlug,
            'label'              => 'bKash',
            'admin_title'        => 'bKash',
            'description'        => __('Pay securely with bKash - Bangladesh\'s leading mobile financial service', 'shaplapay-bkash-payment-gateway-for-fluentcart'),
            'logo'               => $logo,
            'logo_light'         => $logo,
            'icon'               => $logo,
            'brand_color'        => '#E2136E',
            'upcoming'           => false,
            'status'             => $this->settings->get('is_active') === 'yes',
            'supported_features' => $this->supportedFeatures,
        ];
    }

    public function boot()
    {
        (new CallbackHandler())->init();

        // Manual transfers are settled outside FluentCart, so refunds against
        // them must be recorded locally instead of calling the bKash API.
        add_filter('fluent_cart/order_refund_manually', function ($manualRefund, $context) {
            $transaction = Arr::get($context, 'transaction');

            if ($transaction && !empty(Arr::get($transaction->meta ?: [], 'bkash_manual'))) {
                return ['status' => 'yes', 'source' => 'bkash_manual'];
            }

            return $manualRefund;
        }, 10, 2);
    }

    public function isCurrencySupported(): bool
    {
        $currency = strtoupper((string) CurrencySettings::get('currency'));

        $supported = apply_filters('fluent_cart/bkash/supported_currencies', ['BDT']);

        return in_array($currency, array_map('strtoupper', (array) $supported), true);
    }

    public function makePaymentFromPaymentInstance(PaymentInstance $paymentInstance)
    {
        $paymentArgs = [
            'success_url' => $this->getSuccessUrl($paymentInstance->transaction),
            'cancel_url'  => static::getCancelUrl(),
        ];

        // bKash does not run vendor-managed recurring billing here (that needs a
        // bKash agreement/tokenization contract). Subscription carts behave like
        // Cash: an automatic subscription is converted to manual billing so every
        // renewal is paid as a one-time bKash checkout by the customer.
        if ($paymentInstance->subscription && !$this->shouldChargeSubscriptionAsOneTime($paymentInstance)) {
            $this->maybeConvertToManualSubscription($paymentInstance);
        }

        if ((new BkashSettings())->getIntegrationMode() === 'manual') {
            return (new BkashProcessor())->handleManualPayment($paymentInstance, $paymentArgs);
        }

        return (new BkashProcessor())->handleSinglePayment($paymentInstance, $paymentArgs);
    }

    public function getOrderInfo(array $data)
    {
        if (!$this->isCurrencySupported()) {
            wp_send_json([
                'status'  => 'failed',
                'message' => __('bKash only supports BDT (Bangladeshi Taka). Please change your store currency to use bKash.', 'shaplapay-bkash-payment-gateway-for-fluentcart')
            ], 422);
        }

        wp_send_json([
            'status'  => 'success',
            'message' => __('Order info retrieved!', 'shaplapay-bkash-payment-gateway-for-fluentcart'),
            'data'    => []
        ], 200);
    }

    public function handleIPN(): void
    {
        (new CallbackHandler())->handleIpnRequest();
    }

    public function webHookPaymentMethodName(): string
    {
        return $this->getMeta('route');
    }

    public function processRefund($transaction, $amount, $args)
    {
        if (!$amount) {
            return new \WP_Error(
                'bkash_refund_error',
                __('Refund amount is required.', 'shaplapay-bkash-payment-gateway-for-fluentcart')
            );
        }

        $paymentID = Arr::get($transaction->meta ?: [], 'bkash_payment_id', '');
        $trxID = Arr::get($transaction->meta ?: [], 'bkash_trx_id', '') ?: $transaction->vendor_charge_id;

        if (!$paymentID || !$trxID) {
            return new \WP_Error(
                'bkash_refund_error',
                __('This transaction has no bKash payment/trx ID to refund against.', 'shaplapay-bkash-payment-gateway-for-fluentcart')
            );
        }

        $payload = [
            'paymentID' => $paymentID,
            'trxID'     => $trxID,
            'amount'    => BkashProcessor::formatAmount($amount),
            'reason'    => Arr::get($args, 'reason', '') ?: __('Refund from FluentCart', 'shaplapay-bkash-payment-gateway-for-fluentcart'),
            'sku'       => $transaction->order ? $transaction->order->uuid : $transaction->uuid,
        ];

        $payload = apply_filters('fluent_cart/bkash/refund_args', $payload, $transaction, $amount, $args);

        $response = (new BkashApi())->refund($payload);

        if (is_wp_error($response)) {
            return $response;
        }

        $refundTrxID = Arr::get($response, 'refundTrxID', '');

        if (!$refundTrxID) {
            return new \WP_Error(
                'bkash_refund_error',
                __('bKash accepted the refund request but did not return a refund transaction ID.', 'shaplapay-bkash-payment-gateway-for-fluentcart'),
                $response
            );
        }

        $transaction->meta = array_merge($transaction->meta ?: [], [
            'bkash_refund_trx_id' => $refundTrxID,
            'bkash_refund_response' => $response,
        ]);
        $transaction->save();

        return $refundTrxID;
    }

    public function fields(): array
    {
        $credentialFields = function ($mode) {
            return [
                $mode . '_app_key'    => [
                    'value'       => '',
                    'label'       => __('App Key', 'shaplapay-bkash-payment-gateway-for-fluentcart'),
                    'type'        => 'password',
                    'placeholder' => $mode === 'test' ? 'Sandbox App Key' : 'Live App Key',
                    'dependency'  => [
                        'depends_on' => 'payment_mode',
                        'operator'   => '=',
                        'value'      => $mode
                    ]
                ],
                $mode . '_app_secret' => [
                    'value'       => '',
                    'label'       => __('App Secret', 'shaplapay-bkash-payment-gateway-for-fluentcart'),
                    'type'        => 'password',
                    'placeholder' => $mode === 'test' ? 'Sandbox App Secret' : 'Live App Secret',
                    'dependency'  => [
                        'depends_on' => 'payment_mode',
                        'operator'   => '=',
                        'value'      => $mode
                    ]
                ],
                $mode . '_username'   => [
                    'value'       => '',
                    'label'       => __('Username', 'shaplapay-bkash-payment-gateway-for-fluentcart'),
                    'type'        => 'password',
                    'placeholder' => $mode === 'test' ? 'Sandbox Username' : 'Live Username',
                    'dependency'  => [
                        'depends_on' => 'payment_mode',
                        'operator'   => '=',
                        'value'      => $mode
                    ]
                ],
                $mode . '_password'   => [
                    'value'       => '',
                    'label'       => __('Password', 'shaplapay-bkash-payment-gateway-for-fluentcart'),
                    'type'        => 'password',
                    'placeholder' => $mode === 'test' ? 'Sandbox Password' : 'Live Password',
                    'dependency'  => [
                        'depends_on' => 'payment_mode',
                        'operator'   => '=',
                        'value'      => $mode
                    ]
                ],
            ];
        };

        return [
            'notice'       => [
                'value' => $this->renderStoreModeNotice(),
                'label' => __('Store Mode notice', 'shaplapay-bkash-payment-gateway-for-fluentcart'),
                'type'  => 'notice'
            ],
            'integration_mode' => [
                'type'    => 'select',
                'label'   => __('Connection mode', 'shaplapay-bkash-payment-gateway-for-fluentcart'),
                'value'   => 'api',
                'options' => [
                    ['label' => __('bKash Payment Gateway API (automatic checkout)', 'shaplapay-bkash-payment-gateway-for-fluentcart'), 'value' => 'api'],
                    ['label' => __('Manual wallet transfer (no bKash API credentials needed)', 'shaplapay-bkash-payment-gateway-for-fluentcart'), 'value' => 'manual'],
                ],
                'tooltip' => __('Use the API mode when bKash has issued Payment Gateway credentials for your merchant account. Manual mode only needs your bKash wallet number: customers send the money from their own bKash app and you confirm each transfer by hand.', 'shaplapay-bkash-payment-gateway-for-fluentcart'),
            ],
            'manual_wallet_number' => [
                'value'       => '',
                'label'       => __('Your bKash wallet number', 'shaplapay-bkash-payment-gateway-for-fluentcart'),
                'type'        => 'text',
                'placeholder' => '01712345678',
                'dependency'  => [
                    'depends_on' => 'integration_mode',
                    'operator'   => '=',
                    'value'      => 'manual'
                ]
            ],
            'manual_instructions' => [
                'value'       => '',
                'label'       => __('Instructions shown to the customer', 'shaplapay-bkash-payment-gateway-for-fluentcart'),
                'type'        => 'textarea',
                'placeholder' => __('Send {amount} BDT to bKash wallet {wallet} with reference {reference}.', 'shaplapay-bkash-payment-gateway-for-fluentcart'),
                'tooltip'     => __('Placeholders {wallet}, {amount} and {reference} are replaced per order. Leave empty for the default wording.', 'shaplapay-bkash-payment-gateway-for-fluentcart'),
                'dependency'  => [
                    'depends_on' => 'integration_mode',
                    'operator'   => '=',
                    'value'      => 'manual'
                ]
            ],
            'payment_mode' => [
                'type'   => 'tabs',
                'schema' => [
                    [
                        'type'   => 'tab',
                        'label'  => __('Live credentials', 'shaplapay-bkash-payment-gateway-for-fluentcart'),
                        'value'  => 'live',
                        'schema' => $credentialFields('live'),
                    ],
                    [
                        'type'   => 'tab',
                        'label'  => __('Sandbox credentials', 'shaplapay-bkash-payment-gateway-for-fluentcart'),
                        'value'  => 'test',
                        'schema' => $credentialFields('test'),
                    ],
                ]
            ],
            'credentials_help' => [
                'value' => sprintf(
                    '<div><p>%s</p><p>%s <a href="https://developer.bka.sh/" target="_blank" rel="noopener">developer.bka.sh</a>. %s</p><p>%s</p></div>',
                    esc_html__('Enter the App Key, App Secret, Username and Password issued by bKash for your merchant account.', 'shaplapay-bkash-payment-gateway-for-fluentcart'),
                    esc_html__('Sandbox credentials for testing are published on', 'shaplapay-bkash-payment-gateway-for-fluentcart'),
                    esc_html__('The credentials used at checkout follow the store\'s Order Mode (test or live).', 'shaplapay-bkash-payment-gateway-for-fluentcart'),
                    esc_html__('bKash only supports BDT currency and requires a bKash merchant (PGW) account for live payments.', 'shaplapay-bkash-payment-gateway-for-fluentcart')
                ),
                'label' => __('Credentials Help', 'shaplapay-bkash-payment-gateway-for-fluentcart'),
                'type'  => 'html_attr'
            ],
        ];
    }

    /**
     * Verify the credentials of the current store mode by requesting a grant
     * token from bKash before the settings are saved/activated.
     */
    public static function validateSettings($data): array
    {
        // Manual mode needs no bKash credentials at all, only a wallet
        // number the money should be sent to.
        if (Arr::get($data, 'integration_mode', 'api') === 'manual') {
            $wallet = preg_replace('/[^0-9+]/', '', (string) Arr::get($data, 'manual_wallet_number', ''));

            if (!preg_match('/^(?:\+?88)?01[3-9]\d{8}$/', $wallet)) {
                return [
                    'status'  => 'failed',
                    'message' => __('Please enter a valid bKash wallet number (e.g. 01712345678) for manual transfers.', 'shaplapay-bkash-payment-gateway-for-fluentcart')
                ];
            }

            return [
                'status'  => 'success',
                'message' => __('Manual bKash transfer settings saved.', 'shaplapay-bkash-payment-gateway-for-fluentcart')
            ];
        }

        $mode = (new StoreSettings())->get('order_mode') === 'live' ? 'live' : 'test';

        $resolve = static function ($key) use ($data) {
            $value = (string) Arr::get($data, $key, '');

            if ($value !== '' && Helper::isValueEncrypted($value)) {
                $value = (string) Helper::decryptKey($value);
            }

            return $value;
        };

        $config = [
            'app_key'    => $resolve($mode . '_app_key'),
            'app_secret' => $resolve($mode . '_app_secret'),
            'username'   => $resolve($mode . '_username'),
            'password'   => $resolve($mode . '_password'),
        ];

        foreach ($config as $value) {
            if ($value === '') {
                return [
                    'status'  => 'failed',
                    'message' => sprintf(
                        /* translators: %s: credential mode (test or live) */
                        __('Please provide all bKash %s credentials (App Key, App Secret, Username, Password).', 'shaplapay-bkash-payment-gateway-for-fluentcart'),
                        $mode
                    )
                ];
            }
        }

        $token = (new BkashApi($config))->grantToken(true);

        if (is_wp_error($token)) {
            return [
                'status'  => 'failed',
                'message' => $token->get_error_message()
            ];
        }

        return [
            'status'  => 'success',
            'message' => __('bKash credentials verified successfully.', 'shaplapay-bkash-payment-gateway-for-fluentcart')
        ];
    }

    public static function beforeSettingsUpdate($data, $oldSettings): array
    {
        $secretKeys = [
            'test_app_key', 'test_app_secret', 'test_username', 'test_password',
            'live_app_key', 'live_app_secret', 'live_username', 'live_password',
        ];

        foreach ($secretKeys as $key) {
            if (!empty($data[$key])) {
                $data[$key] = Helper::encryptKey($data[$key]);
            }
        }

        return $data;
    }

    public static function register(): void
    {
        fluent_cart_api()->registerCustomPaymentMethod('bkash', new self());
    }
}
