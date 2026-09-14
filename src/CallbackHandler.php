<?php

namespace ShaplaPayBkash;

use ShaplaPayBkash\Api\BkashApi;
use FluentCart\Api\StoreSettings;
use FluentCart\App\Helpers\Status;
use FluentCart\App\Helpers\StatusHelper;
use FluentCart\App\Models\OrderTransaction;
use FluentCart\Framework\Support\Arr;

/**
 * Confirms bKash payments server side.
 *
 * Primary path: the customer returns from the bKash hosted page to
 * ?fluent-cart=fct_bkash_callback&trx_hash=... (bKash appends paymentID and
 * status). A successful callback still requires Execute Payment (or a Query
 * fallback) against bKash before the order is marked paid - the browser
 * callback alone is never trusted.
 *
 * Safety nets:
 *  - before_render_redirect_page re-verifies pending transactions (same
 *    pattern Mollie/Stripe use for their hosted returns).
 *  - handleIPN() exposes the same verification on the core IPN listener URL.
 */
class CallbackHandler
{
    public function init(): void
    {
        add_action('fluent_cart_action_fct_bkash_callback', [$this, 'handleCallback']);
        add_action('fluent_cart/before_render_redirect_page', [$this, 'maybeConfirmPayment'], 10, 1);
    }

    public function handleCallback(array $requestData): void
    {
        $trxHash = sanitize_text_field(Arr::get($requestData, 'trx_hash', ''));

        $transaction = OrderTransaction::query()
            ->where('uuid', $trxHash)
            ->where('payment_method', 'bkash')
            ->first();

        if (!$transaction) {
            wp_safe_redirect(home_url());
            exit;
        }

        if ($transaction->status === Status::TRANSACTION_SUCCEEDED) {
            $this->redirectToSuccess($transaction);
        }

        $status = sanitize_text_field(Arr::get($requestData, 'status', ''));
        $paymentID = sanitize_text_field(Arr::get($requestData, 'paymentID', ''));

        if ($status === 'success' || $status === '') {
            // "success" from bKash, or a direct hit without status - verify
            // against the API either way.
            $result = $this->verifyAndConfirm($transaction, $paymentID);

            if (true === $result) {
                $this->redirectToSuccess($transaction);
            }

            $this->redirectToFailure($transaction, $result);
        }

        // Customer cancelled or bKash reported a failure at the hosted page.
        $reason = $status === 'cancel'
            ? __('Payment cancelled at bKash.', 'shaplapay-payment-gateway-bkash-fluentcart')
            : __('Payment failed at bKash.', 'shaplapay-payment-gateway-bkash-fluentcart');

        $this->markTransactionFailed($transaction, $reason);
        $this->redirectToCheckout($transaction, $reason);
    }

    /**
     * Receipt/redirect page safety net - re-verify a still pending bKash
     * transaction when the customer lands back on the site.
     */
    public function maybeConfirmPayment($data): void
    {
        $isReceipt = Arr::get($data, 'is_receipt', false);
        $method = Arr::get($data, 'method', '');

        if ($isReceipt || $method !== 'bkash') {
            return;
        }

        $trxHash = sanitize_text_field(Arr::get($data, 'trx_hash', ''));

        if (!$trxHash) {
            return;
        }

        $transaction = OrderTransaction::query()
            ->where('uuid', $trxHash)
            ->where('payment_method', 'bkash')
            ->first();

        if (!$transaction || $transaction->status !== Status::TRANSACTION_PENDING) {
            if ($transaction && $transaction->status === Status::TRANSACTION_SUCCEEDED) {
                (new StatusHelper($transaction->order))->syncOrderStatuses($transaction);
            }

            return;
        }

        $this->verifyAndConfirm($transaction);
    }

    /**
     * Core IPN listener (?fluent-cart=fct_payment_listener_ipn&method=bkash).
     * bKash does not push webhooks for the tokenized checkout product, so this
     * endpoint is a manual/automated re-verification hook.
     */
    public function handleIpnRequest(): void
    {
        $request = \FluentCart\App\App::request();

        $trxHash = sanitize_text_field($request->get('trx_hash', ''));
        $paymentID = sanitize_text_field($request->get('paymentID', ''));

        $transaction = null;

        if ($trxHash) {
            $transaction = OrderTransaction::query()
                ->where('uuid', $trxHash)
                ->where('payment_method', 'bkash')
                ->first();
        }

        if (!$transaction && $paymentID) {
            $transaction = OrderTransaction::query()
                ->where('payment_method', 'bkash')
                ->where('vendor_charge_id', $paymentID)
                ->first();
        }

        if (!$transaction) {
            wp_send_json([
                'status'  => 'failed',
                'message' => __('bKash transaction not found.', 'shaplapay-payment-gateway-bkash-fluentcart')
            ], 404);
        }

        if ($transaction->status === Status::TRANSACTION_SUCCEEDED) {
            wp_send_json([
                'status'  => 'success',
                'message' => __('Transaction already confirmed.', 'shaplapay-payment-gateway-bkash-fluentcart')
            ], 200);
        }

        if ($transaction->status !== Status::TRANSACTION_PENDING) {
            wp_send_json([
                'status'  => 'failed',
                'message' => sprintf(
                    /* translators: %s: transaction status */
                    __('Transaction is in "%s" state and cannot be confirmed.', 'shaplapay-payment-gateway-bkash-fluentcart'),
                    $transaction->status
                )
            ], 422);
        }

        $result = $this->verifyAndConfirm($transaction, $paymentID);

        if (true === $result) {
            wp_send_json([
                'status'  => 'success',
                'message' => __('bKash payment confirmed.', 'shaplapay-payment-gateway-bkash-fluentcart')
            ], 200);
        }

        wp_send_json([
            'status'  => 'failed',
            'message' => $result->get_error_message()
        ], 422);
    }

    /**
     * Verify a pending payment against bKash and, when it is Completed,
     * mark the transaction succeeded and sync the order.
     *
     * @return true|\WP_Error
     */
    public function verifyAndConfirm(OrderTransaction $transaction, string $paymentID = '')
    {
        $order = $transaction->order;

        if (!$order) {
            return new \WP_Error('bkash_order_missing', __('Order not found for this transaction.', 'shaplapay-payment-gateway-bkash-fluentcart'));
        }

        $storedPaymentID = $this->getStoredPaymentID($transaction);

        if (!$storedPaymentID) {
            return new \WP_Error('bkash_payment_id_missing', __('No bKash payment session found for this transaction.', 'shaplapay-payment-gateway-bkash-fluentcart'));
        }

        if ($paymentID !== '' && $paymentID !== $storedPaymentID) {
            return new \WP_Error('bkash_payment_id_mismatch', __('bKash payment ID does not match this transaction.', 'shaplapay-payment-gateway-bkash-fluentcart'));
        }

        $api = new BkashApi();

        $response = $api->executePayment($storedPaymentID);

        if (is_wp_error($response)) {
            // Execute can fail after the money already moved (network blip,
            // or the payment was executed by an earlier attempt). The Query
            // API is the documented fallback.
            $query = $api->queryPayment($storedPaymentID);

            if (is_wp_error($query)) {
                return $query;
            }

            if (Arr::get($query, 'transactionStatus') !== 'Completed') {
                return new \WP_Error(
                    'bkash_not_completed',
                    sprintf(
                        /* translators: %s: bKash transaction status */
                        __('bKash payment is not completed (status: %s).', 'shaplapay-payment-gateway-bkash-fluentcart'),
                        Arr::get($query, 'transactionStatus', __('unknown', 'shaplapay-payment-gateway-bkash-fluentcart'))
                    ),
                    $query
                );
            }

            $response = $query;
        }

        if (Arr::get($response, 'transactionStatus') !== 'Completed') {
            return new \WP_Error(
                'bkash_not_completed',
                __('bKash payment was not completed.', 'shaplapay-payment-gateway-bkash-fluentcart'),
                $response
            );
        }

        return $this->confirmPayment($transaction, $response);
    }

    /**
     * Mark the transaction succeeded and let FluentCart sync order status,
     * payment status, fulfillment and notifications.
     *
     * @return true|\WP_Error
     */
    protected function confirmPayment(OrderTransaction $transaction, array $charge)
    {
        $expectedAmount = BkashProcessor::formatAmount($transaction->total);
        $paidAmount = Arr::get($charge, 'amount');

        if ($paidAmount !== null && number_format((float) $paidAmount, 2, '.', '') !== $expectedAmount) {
            fluent_cart_error_log(
                'bKash Payment Amount Mismatch',
                sprintf('Expected %s BDT, bKash reported %s. Transaction: %s', $expectedAmount, $paidAmount, $transaction->uuid),
                ['module_name' => 'Order', 'module_id' => $transaction->order_id]
            );

            return new \WP_Error(
                'bkash_amount_mismatch',
                __('bKash payment amount does not match the order total.', 'shaplapay-payment-gateway-bkash-fluentcart')
            );
        }

        // Serialize the browser callback, receipt re-verification and IPN so a
        // payment is only confirmed once. add_option() inserts are atomic, so
        // two requests racing for the same transaction cannot both hold it.
        $lockKey = 'shaplapay_bkash_confirm_lock_' . $transaction->id;

        if (!$this->acquireLock($lockKey)) {
            return new \WP_Error('bkash_confirm_locked', __('Another confirmation of this payment is already running. Please reload the page.', 'shaplapay-payment-gateway-bkash-fluentcart'));
        }

        try {
            $transaction = OrderTransaction::query()->find($transaction->id);

            if (!$transaction) {
                return new \WP_Error('bkash_transaction_missing', __('Transaction no longer exists.', 'shaplapay-payment-gateway-bkash-fluentcart'));
            }

            $order = $transaction->order;

            if ($transaction->status === Status::TRANSACTION_SUCCEEDED) {
                if ($order) {
                    (new StatusHelper($order))->syncOrderStatuses($transaction);
                }

                return true;
            }

            if ($transaction->status !== Status::TRANSACTION_PENDING) {
                return new \WP_Error(
                    'bkash_invalid_transaction_status',
                    sprintf(
                        /* translators: %s: transaction status */
                        __('Transaction is "%s" and cannot be confirmed.', 'shaplapay-payment-gateway-bkash-fluentcart'),
                        $transaction->status
                    )
                );
            }

            $trxID = (string) Arr::get($charge, 'trxID', '');
            $executedAt = Arr::get($charge, 'paymentExecuteTime');

            // Only the fields the store actually needs are kept. The full bKash
            // response is deliberately not stored: everything meaningful in it
            // already lives in a dedicated field or column, and keeping the raw
            // payload would mean silently storing whatever bKash adds later.
            $meta = array_merge($transaction->meta ?: [], [
                'bkash_trx_id'          => $trxID,
                'bkash_customer_msisdn' => BkashProcessor::maskWallet((string) Arr::get($charge, 'customerMsisdn', '')),
            ]);

            if ($executedAt && empty($meta['settled_at'])) {
                $meta['settled_at'] = $this->parseBkashTime($executedAt);
            }

            $transaction->fill([
                'status'              => Status::TRANSACTION_SUCCEEDED,
                'vendor_charge_id'    => $trxID ?: $transaction->vendor_charge_id,
                'payment_method_type' => 'bkash',
                'payment_mode'        => $order ? $order->mode : $transaction->payment_mode,
                'currency'            => strtoupper((string) (Arr::get($charge, 'currency') ?: $transaction->currency)),
                'meta'                => $meta,
            ]);

            $transaction->save();

            fluent_cart_add_log(
                __('bKash Payment Confirmation', 'shaplapay-payment-gateway-bkash-fluentcart'),
                sprintf(
                    /* translators: 1: bKash trxID, 2: bKash paymentID */
                    __('bKash payment completed. TrxID: %1$s, PaymentID: %2$s', 'shaplapay-payment-gateway-bkash-fluentcart'),
                    $trxID,
                    $this->getStoredPaymentID($transaction)
                ),
                'info',
                ['module_name' => 'Order', 'module_id' => $transaction->order_id]
            );

            if ($order) {
                (new StatusHelper($order))->syncOrderStatuses($transaction);
            }

            return true;
        } finally {
            $this->releaseLock($lockKey);
        }
    }

    /**
     * Tiny mutex on top of the options table. add_option() fails when the row
     * already exists, which makes the insert atomic across requests. A lock
     * older than two minutes is treated as leftovers from a killed request.
     * Every acquired key is also listed in an index option so uninstall can
     * enumerate the leftovers through the Options API instead of SQL.
     */
    protected function acquireLock(string $key): bool
    {
        if (add_option($key, time(), '', 'no')) {
            $this->trackLock($key);

            return true;
        }

        $heldSince = (int) get_option($key);

        if ($heldSince > 0 && (time() - $heldSince) > 120) {
            delete_option($key);
            delete_option(self::LOCK_INDEX_OPTION);

            if (add_option($key, time(), '', 'no')) {
                $this->trackLock($key);

                return true;
            }
        }

        return false;
    }

    protected function releaseLock(string $key): void
    {
        delete_option($key);
        $this->untrackLock($key);
    }

    /**
     * Index of lock option names this plugin has ever created. Read at
     * uninstall time to clean up leftover rows without any direct SQL.
     */
    const LOCK_INDEX_OPTION = 'shaplapay_bkash_confirm_locks';

    protected function trackLock(string $key): void
    {
        $index = get_option(self::LOCK_INDEX_OPTION, []);

        if (!is_array($index)) {
            $index = [];
        }

        if (!in_array($key, $index, true)) {
            $index[] = $key;
            update_option(self::LOCK_INDEX_OPTION, array_slice($index, -200), 'no');
        }
    }

    protected function untrackLock(string $key): void
    {
        $index = get_option(self::LOCK_INDEX_OPTION, []);

        if (!is_array($index) || !in_array($key, $index, true)) {
            return;
        }

        $index = array_values(array_diff($index, [$key]));

        if (!$index) {
            delete_option(self::LOCK_INDEX_OPTION);

            return;
        }

        update_option(self::LOCK_INDEX_OPTION, $index, 'no');
    }

    protected function getStoredPaymentID(OrderTransaction $transaction): string
    {
        $paymentID = Arr::get($transaction->meta ?: [], 'bkash_payment_id', '');

        if (!$paymentID && $transaction->vendor_charge_id && strpos($transaction->vendor_charge_id, 'TR') === 0) {
            // Before execution, vendor_charge_id holds the bKash paymentID
            // (TR...); after execution it is replaced by the trxID.
            $paymentID = $transaction->vendor_charge_id;
        }

        return (string) $paymentID;
    }

    protected function markTransactionFailed(OrderTransaction $transaction, string $reason): void
    {
        if ($transaction->status !== Status::TRANSACTION_PENDING) {
            return;
        }

        $transaction->update(['status' => Status::TRANSACTION_FAILED]);

        fluent_cart_error_log(
            __('Payment Failed', 'shaplapay-payment-gateway-bkash-fluentcart'),
            'Payment Failed Reason: ' . $reason,
            ['module_name' => 'Order', 'module_id' => $transaction->order_id]
        );
    }

    protected function redirectToSuccess(OrderTransaction $transaction): void
    {
        $transaction = OrderTransaction::query()->find($transaction->id);

        $url = $transaction && method_exists($transaction, 'getSuccessUrl')
            ? $transaction->getSuccessUrl()
            : home_url();

        wp_safe_redirect($url);
        exit;
    }

    protected function redirectToFailure(OrderTransaction $transaction, \WP_Error $error): void
    {
        $reason = $error->get_error_message();

        // An unverified "success" callback may still be a paid-but-unexecuted
        // payment (bKash auto-refunds those); only definitively failed or
        // cancelled payments are stamped failed here.
        if (in_array($error->get_error_code(), ['bkash_payment_id_mismatch', 'bkash_amount_mismatch'], true)) {
            $this->markTransactionFailed($transaction, $reason);
        }

        $this->redirectToCheckout($transaction, $reason);
    }

    protected function redirectToCheckout(OrderTransaction $transaction, string $reason): void
    {
        $cancelUrl = Arr::get($transaction->meta ?: [], 'bkash_cancel_url', '');

        if (!$cancelUrl) {
            $cancelUrl = (new StoreSettings())->getCheckoutPage();
        }

        $cancelUrl = add_query_arg([
            'fct_payment_status' => 'failed',
            'fct_payment_reason' => rawurlencode($reason),
        ], $cancelUrl);

        wp_safe_redirect($cancelUrl);
        exit;
    }

    /**
     * bKash time format: "2024-07-09T15:46:23:420 GMT+0600"
     */
    protected function parseBkashTime(string $bkashTime): string
    {
        $normalized = preg_replace('/:(\d{3})\s*GMT/', '.$1 GMT', $bkashTime);

        $timestamp = strtotime($normalized);

        if (!$timestamp) {
            return gmdate('Y-m-d H:i:s');
        }

        return gmdate('Y-m-d H:i:s', $timestamp);
    }
}
