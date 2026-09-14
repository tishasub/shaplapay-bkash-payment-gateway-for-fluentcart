<?php

namespace ShaplaPayBkash;

use FluentCart\Api\StoreSettings;
use FluentCart\App\Helpers\Helper;
use FluentCart\App\Helpers\Status;
use FluentCart\App\Models\OrderTransaction;
use FluentCart\Framework\Support\Arr;

/**
 * Customer-facing side of the manual wallet transfer mode.
 *
 * FluentCart only acts on the redirect returned by a gateway, so the payment
 * instructions a manual customer needs cannot travel in the gateway response
 * message - they have to be rendered on the confirmation page, where the order
 * (and therefore the exact amount and the reference) already exists.
 *
 * A manual order is never marked paid here. The order stays pending until the
 * merchant confirms the transfer; submitting a transaction ID only records what
 * the customer says they sent.
 */
class ManualPaymentNotice
{
    const ROUTE = 'fct_bkash_manual_trx';
    const NONCE_FIELD = 'shaplapay_bkash_nonce';
    const NONCE_ACTION = 'shaplapay_bkash_manual_trx';

    public function init(): void
    {
        add_action('wp_enqueue_scripts', [$this, 'enqueueAssets']);
        add_action('fluent_cart/receipt/thank_you/after_order_header', [$this, 'renderNotice']);
        add_action('fluent_cart_action_' . self::ROUTE, [$this, 'handleSubmission']);
    }

    /**
     * The pre-order hint shown under the bKash option at checkout, when the
     * merchant asked for the notice in both places. The amount and reference
     * cannot appear here because no order has been placed yet.
     */
    public static function buildCheckoutNotice(BkashSettings $settings): string
    {
        $notice = trim($settings->getManualCheckoutNotice());

        if ($notice === '') {
            $notice = __('Send the money from your bKash app to wallet {wallet}. Your exact amount and payment reference are shown on the next page.', 'shaplapay-payment-gateway-bkash-fluentcart');
        }

        $notice = str_replace('{wallet}', $settings->getWalletNumber(), $notice);

        return nl2br(esc_html($notice));
    }

    /**
     * The per-order instruction text, shared with the gateway response so the
     * wording stays identical wherever it is shown.
     */
    public static function buildInstructions(BkashSettings $settings, string $wallet, string $amount, string $reference): string
    {
        $custom = trim($settings->getManualInstructions());

        if ($custom !== '') {
            return str_replace(
                ['{wallet}', '{amount}', '{reference}'],
                [$wallet, $amount, $reference],
                $custom
            );
        }

        if ($settings->getManualCapture() === 'customer_trx_id') {
            return sprintf(
                /* translators: 1: amount, 2: wallet number, 3: order reference */
                __('Send %1$s BDT from your bKash app (Send Money) to wallet %2$s using reference %3$s, then enter the bKash Transaction ID you received so we can verify your payment.', 'shaplapay-payment-gateway-bkash-fluentcart'),
                $amount,
                $wallet,
                $reference
            );
        }

        return sprintf(
            /* translators: 1: amount, 2: wallet number, 3: order reference */
            __('Send %1$s BDT from your bKash app (Send Money) to wallet %2$s using reference %3$s. Your order will be confirmed once the transfer is verified.', 'shaplapay-payment-gateway-bkash-fluentcart'),
            $amount,
            $wallet,
            $reference
        );
    }

    public function renderNotice($config): void
    {
        $order = Arr::get($config, 'order');

        if (!$order || $order->payment_status === Status::PAYMENT_PAID) {
            return;
        }

        $transaction = $order->getLatestTransaction();

        if (!$transaction || $transaction->payment_method !== 'bkash') {
            return;
        }

        $meta = $transaction->meta ?: [];

        if (empty($meta['bkash_manual'])) {
            return;
        }

        $settings = new BkashSettings();

        $wallet = (string) (Arr::get($meta, 'bkash_manual_wallet') ?: $settings->getWalletNumber());
        $reference = (string) (Arr::get($meta, 'bkash_manual_reference') ?: $order->uuid);
        $amount = (string) (Arr::get($meta, 'bkash_manual_amount') ?: BkashProcessor::formatAmount($transaction->total));
        $submittedTrxId = (string) Arr::get($meta, 'bkash_manual_submitted_trx_id', '');
        $askForTrxId = $settings->getManualCapture() === 'customer_trx_id';

        $instructions = self::buildInstructions($settings, $wallet, $amount, $reference);
        $flag = $this->submissionFlag();

        ?>
        <div class="shaplapay-bkash-manual">
            <h3><?php echo esc_html__('Complete your bKash payment', 'shaplapay-payment-gateway-bkash-fluentcart'); ?></h3>

            <?php $this->renderFlag($flag, $submittedTrxId); ?>

            <p><?php echo wp_kses_post(nl2br(esc_html($instructions))); ?></p>

            <table class="shaplapay-bkash-manual-details">
                <tbody>
                    <tr>
                        <th><?php echo esc_html__('Amount to send', 'shaplapay-payment-gateway-bkash-fluentcart'); ?></th>
                        <td><strong><?php echo wp_kses_post($this->money($transaction->total)); ?></strong></td>
                    </tr>
                    <tr>
                        <th><?php echo esc_html__('Send to (bKash wallet)', 'shaplapay-payment-gateway-bkash-fluentcart'); ?></th>
                        <td class="shaplapay-bkash-manual-code"><?php echo esc_html($wallet); ?></td>
                    </tr>
                    <tr>
                        <th><?php echo esc_html__('Reference', 'shaplapay-payment-gateway-bkash-fluentcart'); ?></th>
                        <td class="shaplapay-bkash-manual-code"><?php echo esc_html($reference); ?></td>
                    </tr>
                </tbody>
            </table>

            <?php if ($askForTrxId) : ?>
                <?php $this->renderTrxForm($transaction, $submittedTrxId); ?>
            <?php else : ?>
                <p class="shaplapay-bkash-manual-note">
                    <?php echo esc_html__('We will confirm your order once the transfer is verified.', 'shaplapay-payment-gateway-bkash-fluentcart'); ?>
                </p>
            <?php endif; ?>
        </div>
        <?php
    }

    protected function renderTrxForm(OrderTransaction $transaction, string $submittedTrxId): void
    {
        $hasSubmitted = $submittedTrxId !== '';
        ?>
        <form class="shaplapay-bkash-manual-form" method="post" action="<?php echo esc_url(home_url('/?fluent-cart=' . self::ROUTE)); ?>">
            <input type="hidden" name="trx_hash" value="<?php echo esc_attr($transaction->uuid); ?>" />
            <?php wp_nonce_field(self::NONCE_ACTION . '_' . $transaction->uuid, self::NONCE_FIELD); ?>

            <label for="shaplapay-bkash-trx-id">
                <?php
                echo $hasSubmitted
                    ? esc_html__('Sent a different one? Enter the correct bKash Transaction ID', 'shaplapay-payment-gateway-bkash-fluentcart')
                    : esc_html__('bKash Transaction ID', 'shaplapay-payment-gateway-bkash-fluentcart');
                ?>
            </label>
            <p class="shaplapay-bkash-manual-hint">
                <?php echo esc_html__('You will find it in your bKash app or in the confirmation SMS, for example 8N7A1B2C3D.', 'shaplapay-payment-gateway-bkash-fluentcart'); ?>
            </p>
            <div class="shaplapay-bkash-manual-form-row">
                <input
                    type="text"
                    id="shaplapay-bkash-trx-id"
                    name="bkash_trx_id"
                    class="shaplapay-bkash-manual-input"
                    placeholder="<?php echo esc_attr($submittedTrxId !== '' ? $submittedTrxId : '8N7A1B2C3D'); ?>"
                    autocomplete="off"
                    <?php echo $hasSubmitted ? '' : 'required'; ?>
                />
                <button type="submit" class="shaplapay-bkash-manual-button">
                    <?php
                    echo $hasSubmitted
                        ? esc_html__('Update Transaction ID', 'shaplapay-payment-gateway-bkash-fluentcart')
                        : esc_html__('Submit Transaction ID', 'shaplapay-payment-gateway-bkash-fluentcart');
                    ?>
                </button>
            </div>
        </form>
        <?php
    }

    protected function renderFlag(string $flag, string $submittedTrxId): void
    {
        if ($flag === '' && $submittedTrxId === '') {
            return;
        }

        $messages = [
            'submitted' => [
                'type' => 'success',
                'text' => __('Thanks - we saved your bKash Transaction ID.', 'shaplapay-payment-gateway-bkash-fluentcart'),
            ],
            'invalid' => [
                'type' => 'error',
                'text' => __('We could not verify that request. Please enter your bKash Transaction ID again.', 'shaplapay-payment-gateway-bkash-fluentcart'),
            ],
            'format' => [
                'type' => 'error',
                'text' => __('That does not look like a bKash Transaction ID. Use the letters and numbers from your bKash app, for example 8N7A1B2C3D.', 'shaplapay-payment-gateway-bkash-fluentcart'),
            ],
            'locked' => [
                'type' => 'error',
                'text' => __('This order can no longer be updated. Please contact us if something is wrong.', 'shaplapay-payment-gateway-bkash-fluentcart'),
            ],
        ];

        $message = $messages[$flag] ?? null;

        if ($message === null && $submittedTrxId === '') {
            return;
        }

        if ($message === null) {
            $message = [
                'type' => 'success',
                'text' => sprintf(
                    /* translators: %s: bKash transaction ID submitted by the customer */
                    __('We received your bKash Transaction ID: %s', 'shaplapay-payment-gateway-bkash-fluentcart'),
                    $submittedTrxId
                ),
            ];
        }

        printf(
            '<div class="shaplapay-bkash-manual-flag is-%s">%s</div>',
            esc_attr($message['type']),
            esc_html($message['text'])
        );
    }

    /**
     * The notice stylesheet is only needed on the order confirmation page.
     */
    public function enqueueAssets(): void
    {
        $receiptPageId = (int) (new StoreSettings())->getReceiptPageId();

        if ($receiptPageId <= 0 || !is_page($receiptPageId)) {
            return;
        }

        wp_enqueue_style(
            'shaplapay-bkash-manual',
            SHAPLAPAY_BKASH_URL . 'assets/css/manual-notice.css',
            [],
            SHAPLAPAY_BKASH_VERSION
        );
    }

    public function handleSubmission($request): void
    {
        $trxHash = sanitize_text_field(wp_unslash((string) Arr::get($request, 'trx_hash', '')));
        $trxId = strtoupper(sanitize_text_field(wp_unslash((string) Arr::get($request, 'bkash_trx_id', ''))));
        $nonce = sanitize_text_field(wp_unslash((string) Arr::get($request, self::NONCE_FIELD, '')));

        $transaction = $trxHash === '' ? null : OrderTransaction::query()
            ->where('uuid', $trxHash)
            ->where('payment_method', 'bkash')
            ->first();

        if (!$transaction) {
            wp_safe_redirect(home_url());
            exit;
        }

        $meta = $transaction->meta ?: [];

        if (empty($meta['bkash_manual'])) {
            $this->redirectBack($transaction, 'invalid');
        }

        if (!wp_verify_nonce($nonce, self::NONCE_ACTION . '_' . $transaction->uuid)) {
            $this->redirectBack($transaction, 'invalid');
        }

        if ($transaction->status !== Status::TRANSACTION_PENDING) {
            $this->redirectBack($transaction, 'locked');
        }

        if (!preg_match('/^[A-Za-z0-9]{6,30}$/', $trxId)) {
            $this->redirectBack($transaction, 'format');
        }

        $meta['bkash_manual_submitted_trx_id'] = $trxId;
        $meta['bkash_manual_submitted_at'] = gmdate('Y-m-d H:i:s');

        $transaction->meta = $meta;
        $transaction->save();

        fluent_cart_add_log(
            __('bKash Transaction ID received', 'shaplapay-payment-gateway-bkash-fluentcart'),
            sprintf(
                /* translators: %s: bKash transaction ID submitted by the customer */
                __('The customer submitted bKash Transaction ID %s. Verify the transfer and confirm the order.', 'shaplapay-payment-gateway-bkash-fluentcart'),
                $trxId
            ),
            'info',
            ['module_name' => 'Order', 'module_id' => $transaction->order_id]
        );

        $this->redirectBack($transaction, 'submitted');
    }

    /**
     * Display-only flag set by our own redirect after a submission. It changes
     * nothing, so it is sanitized rather than nonce-checked.
     */
    protected function submissionFlag(): string
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only display flag from our own redirect.
        $flag = isset($_GET['bkash_manual']) ? sanitize_key(wp_unslash($_GET['bkash_manual'])) : '';

        return in_array($flag, ['submitted', 'invalid', 'format', 'locked'], true) ? $flag : '';
    }

    protected function redirectBack(OrderTransaction $transaction, string $flag): void
    {
        wp_safe_redirect(add_query_arg('bkash_manual', $flag, $transaction->getSuccessUrl()));
        exit;
    }

    protected function money($minorUnits): string
    {
        if (class_exists(Helper::class) && method_exists(Helper::class, 'toDecimal')) {
            return Helper::toDecimal((int) $minorUnits);
        }

        return BkashProcessor::formatAmount($minorUnits) . ' BDT';
    }
}
