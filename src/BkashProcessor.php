<?php

namespace ShaplaPayBkash;

use ShaplaPayBkash\Api\BkashApi;
use FluentCart\App\Helpers\Status;
use FluentCart\App\Models\Cart;
use FluentCart\App\Services\Payments\PaymentInstance;
use FluentCart\Framework\Support\Arr;

class BkashProcessor
{
    /**
     * Create a bKash payment and hand the hosted checkout URL back to
     * FluentCart's generic "redirect" next-action flow.
     *
     * @return array|\WP_Error
     */
    public function handleSinglePayment(PaymentInstance $paymentInstance, array $paymentArgs = [])
    {
        $order = $paymentInstance->order;
        $transaction = $paymentInstance->transaction;

        if (!$transaction) {
            return new \WP_Error(
                'bkash_no_transaction',
                __('No charge transaction found for this order.', 'shaplapay-bkash-payment-gateway-for-fluentcart')
            );
        }

        $callbackURL = apply_filters(
            'fluent_cart/bkash/callback_url',
            site_url('/?fluent-cart=fct_bkash_callback&trx_hash=' . $transaction->uuid),
            $transaction,
            $order
        );

        $payload = [
            'mode'                  => '0011', // Checkout (URL based) - wallet number + OTP + PIN on the bKash hosted page
            'payerReference'        => $this->getPayerReference($order, $transaction),
            'callbackURL'           => $callbackURL,
            'amount'                => static::formatAmount($transaction->total),
            'currency'              => strtoupper($transaction->currency ?: 'BDT'),
            'intent'                => 'sale',
            'merchantInvoiceNumber' => $this->getMerchantInvoiceNumber($transaction),
        ];

        $payload = apply_filters('fluent_cart/bkash/create_payment_args', $payload, [
            'order'       => $order,
            'transaction' => $transaction,
        ]);

        $response = (new BkashApi())->createPayment($payload);

        if (is_wp_error($response)) {
            fluent_cart_error_log(
                'bKash Create Payment Failed',
                $response->get_error_message(),
                ['module_name' => 'Order', 'module_id' => $order ? $order->id : 0]
            );

            return $response;
        }

        $paymentID = Arr::get($response, 'paymentID', '');
        $bkashURL = Arr::get($response, 'bkashURL', '');

        if (!$paymentID || !$bkashURL) {
            return new \WP_Error(
                'bkash_checkout_error',
                __('Unable to start the bKash payment session. Please try again.', 'shaplapay-bkash-payment-gateway-for-fluentcart'),
                $response
            );
        }

        $transaction->update([
            'vendor_charge_id' => $paymentID,
            'meta'             => array_merge($transaction->meta ?: [], [
                'bkash_payment_id' => $paymentID,
                'bkash_cancel_url' => Arr::get($paymentArgs, 'cancel_url', ''),
                'bkash_mode'       => (new BkashSettings())->getMode(),
            ]),
        ]);

        return [
            'status'       => 'success',
            'nextAction'   => 'bkash',
            'actionName'   => 'redirect',
            'message'      => __('Redirecting to bKash...', 'shaplapay-bkash-payment-gateway-for-fluentcart'),
            'response'     => $response,
            'payment_args' => array_merge($paymentArgs, [
                'checkout_url' => $bkashURL,
                'payment_id'   => $paymentID,
            ]),
        ];
    }

    /**
     * Fallback for stores without bKash API credentials: the customer sends
     * the money from their own bKash app to the wallet number configured in
     * the settings, quoting the order reference. The order stays pending and
     * the merchant confirms the transfer by hand, exactly like FluentCart's
     * offline payment flow.
     *
     * @return array
     */
    public function handleManualPayment(PaymentInstance $paymentInstance, array $paymentArgs = [])
    {
        $order = $paymentInstance->order;
        $transaction = $paymentInstance->transaction;
        $settings = new BkashSettings();

        if (!$transaction) {
            return [
                'status'  => 'failed',
                'message' => __('No charge transaction found for this order.', 'shaplapay-bkash-payment-gateway-for-fluentcart'),
            ];
        }

        $wallet = $settings->getWalletNumber();
        $amount = static::formatAmount($transaction->total);
        $reference = $order ? $order->uuid : $transaction->uuid;

        $transaction->update([
            'meta' => array_merge($transaction->meta ?: [], [
                'bkash_manual'           => 1,
                'bkash_manual_wallet'    => $wallet,
                'bkash_manual_amount'    => $amount,
                'bkash_manual_reference' => $reference,
            ]),
        ]);

        if ($order) {
            $order->payment_method_title = __('bKash (manual transfer)', 'shaplapay-bkash-payment-gateway-for-fluentcart');
            $order->save();

            // Same offline order pipeline FluentCart uses for cash on
            // delivery: emails, activity log and cart completion.
            if ($order->type !== Status::ORDER_TYPE_RENEWAL) {
                do_action('fluent_cart/order_placed_offline', [
                    'order'       => $order,
                    'customer'    => $order->customer,
                    'transaction' => $transaction,
                ]);
            }
        }

        $relatedCart = Cart::query()->where('order_id', $order ? $order->id : 0)
            ->where('stage', '!=', 'completed')
            ->first();

        if ($relatedCart) {
            $relatedCart->stage = 'completed';
            $relatedCart->completed_at = gmdate('Y-m-d H:i:s');
            $relatedCart->save();
        }

        // FluentCart only acts on the redirect, so this message is not what the
        // customer reads - ManualPaymentNotice renders the same wording on the
        // confirmation page, where the order reference actually exists.
        return [
            'status'      => 'success',
            'message'     => ManualPaymentNotice::buildInstructions($settings, $wallet, $amount, $reference),
            'redirect_to' => $transaction->getSuccessUrl(),
        ];
    }

    /**
     * A Bangladeshi mobile number passed as payerReference is pre-populated on
     * the bKash wallet entry screen; anything else falls back to the
     * transaction hash. "<", ">" and "&" are not allowed by bKash.
     */
    protected function getPayerReference($order, $transaction): string
    {
        $phone = '';

        if ($order) {
            // billing_address is a lazily loaded OrderAddress model (or null)
            $billingAddress = $order->billing_address;

            if ($billingAddress && !empty($billingAddress->phone)) {
                $phone = (string) $billingAddress->phone;
            }
        }

        $phone = preg_replace('/[^0-9+]/', '', $phone);

        if ($phone && preg_match('/^(?:\+?88)?01[3-9]\d{8}$/', $phone)) {
            return $phone;
        }

        return str_replace(['<', '>', '&'], '', (string) $transaction->uuid);
    }

    /**
     * Unique per charge attempt: transaction hash plus a retry suffix when the
     * customer re-submits a previously failed transaction.
     */
    protected function getMerchantInvoiceNumber($transaction): string
    {
        $attempt = (int) Arr::get($transaction->meta ?: [], 'payment_attempt', 0);

        $invoice = $transaction->uuid . ($attempt > 0 ? '-r' . $attempt : '');

        return substr(str_replace(['<', '>', '&'], '', $invoice), 0, 255);
    }

    /**
     * FluentCart stores amounts in minor units (paisa); bKash expects a
     * decimal string in BDT.
     */
    public static function formatAmount($amountInCents): string
    {
        return number_format(((int) $amountInCents) / 100, 2, '.', '');
    }
}
