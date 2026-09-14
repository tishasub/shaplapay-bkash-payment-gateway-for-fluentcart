<?php

namespace ShaplaPayBkash\Admin;

use ShaplaPayBkash\BkashProcessor;
use FluentCart\App\Helpers\Helper;

/**
 * Standalone bKash report screen for the WordPress admin.
 *
 * Read-only: every query is a SELECT. Each statement is a literal string passed
 * directly to $wpdb->prepare(), with table names bound through the %i
 * identifier placeholder and every value through %s/%d. Nothing is
 * interpolated and no statement is assembled by concatenation, so the SQL is
 * static and reviewable. Requires WordPress 6.2 for %i support.
 *
 * Optional filters are neutralised in SQL with "= '' OR ..." and an empty
 * string argument, which keeps the statement literal without a dynamic WHERE.
 *
 * The filters come from a GET form and change nothing, so they are sanitized
 * and capability-gated rather than nonce-checked; a nonce would protect no
 * state change and would break plain menu navigation.
 *
 * The queries read FluentCart's own tables directly because FluentCart exposes
 * no aggregate reporting API for third-party gateway data.
 */
class BkashReport
{
    const MENU_SLUG = 'shaplapay-bkash';
    const CAPABILITY = 'manage_options';
    const PER_PAGE = 25;
    const PANEL_LIMIT = 100;

    public function register(): void
    {
        add_action('admin_menu', [$this, 'addMenuPage']);
        add_action('admin_enqueue_scripts', [$this, 'enqueueAssets']);
    }

    public function addMenuPage(): void
    {
        add_menu_page(
            __('bKash Reports', 'shaplapay-payment-gateway-bkash-fluentcart'),
            __('bKash', 'shaplapay-payment-gateway-bkash-fluentcart'),
            self::CAPABILITY,
            self::MENU_SLUG,
            [$this, 'renderPage'],
            'dashicons-money-alt',
            56
        );
    }

    protected static function statusLabels(): array
    {
        return [
            'succeeded'    => __('Paid', 'shaplapay-payment-gateway-bkash-fluentcart'),
            'pending'      => __('Pending', 'shaplapay-payment-gateway-bkash-fluentcart'),
            'failed'       => __('Failed', 'shaplapay-payment-gateway-bkash-fluentcart'),
            'refunded'     => __('Refunded', 'shaplapay-payment-gateway-bkash-fluentcart'),
            'authorized'   => __('Authorized', 'shaplapay-payment-gateway-bkash-fluentcart'),
            'dispute_lost' => __('Disputed', 'shaplapay-payment-gateway-bkash-fluentcart'),
        ];
    }

    public function renderPage(): void
    {
        if (!current_user_can(self::CAPABILITY)) {
            wp_die(esc_html__('You do not have permission to view this report.', 'shaplapay-payment-gateway-bkash-fluentcart'));
        }

        global $wpdb;

        $trxTable = $wpdb->prefix . 'fct_order_transactions';

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Read-only existence check for FluentCart's table; the name is escaped with esc_like().
        $foundTable = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($trxTable)));

        if ($foundTable !== $trxTable) {
            printf(
                '<div class="wrap"><h1>%s</h1><div class="notice notice-error"><p>%s</p></div></div>',
                esc_html__('bKash Reports', 'shaplapay-payment-gateway-bkash-fluentcart'),
                esc_html__('The FluentCart tables were not found. Install and activate FluentCart to use this report.', 'shaplapay-payment-gateway-bkash-fluentcart')
            );

            return;
        }

        $filters = $this->getFilters();
        $totals = $this->getTotals($filters);
        $paged = $this->getPage();
        $transactions = $this->getTransactions($filters, self::PER_PAGE, ($paged - 1) * self::PER_PAGE);
        $manualPending = $this->getManualPending();
        $refunds = $this->getRefunds($filters);

        ?>
        <div class="wrap shaplapay-bkash-report">
            <h1><?php echo esc_html__('bKash Reports', 'shaplapay-payment-gateway-bkash-fluentcart'); ?></h1>

            <?php $this->renderFilters($filters); ?>

            <div class="sb-cards">
                <?php
                $this->renderCard(__('Collected', 'shaplapay-payment-gateway-bkash-fluentcart'), $this->money($totals['collected']), 'sb-card-collected');
                $this->renderCard(__('Pending', 'shaplapay-payment-gateway-bkash-fluentcart'), $this->money($totals['pending']), 'sb-card-pending');
                $this->renderCard(__('Failed', 'shaplapay-payment-gateway-bkash-fluentcart'), $this->money($totals['failed']), 'sb-card-failed');
                $this->renderCard(__('Refunded', 'shaplapay-payment-gateway-bkash-fluentcart'), $this->money($totals['refunded']), 'sb-card-refunded');
                $this->renderCard(__('Transactions', 'shaplapay-payment-gateway-bkash-fluentcart'), number_format_i18n($totals['count']), 'sb-card-count');
                ?>
            </div>

            <p class="sb-range-note"><?php echo wp_kses_post($this->describeRange($filters)); ?></p>

            <?php $this->renderManualPending($manualPending); ?>
            <?php $this->renderTransactions($transactions, $totals['count'], $paged); ?>
            <?php $this->renderRefunds($refunds, $totals['refund_count']); ?>
        </div>
        <?php
    }

    /**
     * The report stylesheet only loads on this plugin's own screen, so no
     * other admin page pays for it.
     */
    public function enqueueAssets(string $hookSuffix): void
    {
        if ($hookSuffix !== 'toplevel_page_' . self::MENU_SLUG) {
            return;
        }

        wp_enqueue_style(
            'shaplapay-bkash-report',
            SHAPLAPAY_BKASH_URL . 'assets/css/admin-report.css',
            [],
            SHAPLAPAY_BKASH_VERSION
        );
    }

    protected function renderCard(string $label, string $value, string $class): void
    {
        printf(
            '<div class="sb-card %s"><span class="sb-card-label">%s</span><span class="sb-card-value">%s</span></div>',
            esc_attr($class),
            esc_html($label),
            wp_kses_post($value)
        );
    }

    protected function renderFilters(array $filters): void
    {
        ?>
        <form method="get" class="sb-filters">
            <input type="hidden" name="page" value="<?php echo esc_attr(self::MENU_SLUG); ?>" />
            <div>
                <label for="sb-date-from"><?php echo esc_html__('From', 'shaplapay-payment-gateway-bkash-fluentcart'); ?></label>
                <input type="date" id="sb-date-from" name="date_from" value="<?php echo esc_attr($filters['raw_from']); ?>" />
            </div>
            <div>
                <label for="sb-date-to"><?php echo esc_html__('To', 'shaplapay-payment-gateway-bkash-fluentcart'); ?></label>
                <input type="date" id="sb-date-to" name="date_to" value="<?php echo esc_attr($filters['raw_to']); ?>" />
            </div>
            <div>
                <label for="sb-status"><?php echo esc_html__('Status', 'shaplapay-payment-gateway-bkash-fluentcart'); ?></label>
                <select id="sb-status" name="status">
                    <option value=""><?php echo esc_html__('All statuses', 'shaplapay-payment-gateway-bkash-fluentcart'); ?></option>
                    <?php foreach (self::statusLabels() as $value => $label) : ?>
                        <option value="<?php echo esc_attr($value); ?>" <?php selected($filters['status'], $value); ?>>
                            <?php echo esc_html($label); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label for="sb-mode"><?php echo esc_html__('Store mode', 'shaplapay-payment-gateway-bkash-fluentcart'); ?></label>
                <select id="sb-mode" name="payment_mode">
                    <option value=""><?php echo esc_html__('Test and live', 'shaplapay-payment-gateway-bkash-fluentcart'); ?></option>
                    <option value="live" <?php selected($filters['mode'], 'live'); ?>><?php echo esc_html__('Live', 'shaplapay-payment-gateway-bkash-fluentcart'); ?></option>
                    <option value="test" <?php selected($filters['mode'], 'test'); ?>><?php echo esc_html__('Test', 'shaplapay-payment-gateway-bkash-fluentcart'); ?></option>
                </select>
            </div>
            <div>
                <?php submit_button(__('Filter', 'shaplapay-payment-gateway-bkash-fluentcart'), 'secondary', 'filter', false); ?>
                <a class="button button-link" href="<?php echo esc_url(admin_url('admin.php?page=' . self::MENU_SLUG)); ?>"><?php echo esc_html__('Reset', 'shaplapay-payment-gateway-bkash-fluentcart'); ?></a>
            </div>
        </form>
        <?php
    }

    protected function renderManualPending(array $rows): void
    {
        if (!$rows) {
            return;
        }
        ?>
        <div class="sb-panel sb-panel-warning">
            <h2><?php echo esc_html__('Manual transfers awaiting confirmation', 'shaplapay-payment-gateway-bkash-fluentcart'); ?></h2>
            <p class="sb-muted"><?php echo esc_html__('These customers were told to send money to your bKash wallet. Confirm each transfer in FluentCart once it appears in your bKash app.', 'shaplapay-payment-gateway-bkash-fluentcart'); ?></p>
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th><?php echo esc_html__('Date', 'shaplapay-payment-gateway-bkash-fluentcart'); ?></th>
                        <th><?php echo esc_html__('Order', 'shaplapay-payment-gateway-bkash-fluentcart'); ?></th>
                        <th><?php echo esc_html__('Customer', 'shaplapay-payment-gateway-bkash-fluentcart'); ?></th>
                        <th><?php echo esc_html__('Reference', 'shaplapay-payment-gateway-bkash-fluentcart'); ?></th>
                        <th><?php echo esc_html__('bKash TrxID from customer', 'shaplapay-payment-gateway-bkash-fluentcart'); ?></th>
                        <th><?php echo esc_html__('Amount', 'shaplapay-payment-gateway-bkash-fluentcart'); ?></th>
                        <th><?php echo esc_html__('Your wallet', 'shaplapay-payment-gateway-bkash-fluentcart'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($rows as $row) : ?>
                        <?php $details = $this->trxDetails($row); ?>
                        <tr>
                            <td><?php echo esc_html($this->localDate($row['created_at'])); ?></td>
                            <td><?php echo wp_kses_post($this->orderLink($row)); ?></td>
                            <td><?php echo wp_kses_post($this->customerText($row)); ?></td>
                            <td class="sb-mono"><?php echo esc_html($details['manual_reference'] ?: '—'); ?></td>
                            <td class="sb-mono">
                                <?php if ($details['submitted_trx_id'] !== '') : ?>
                                    <?php echo esc_html($details['submitted_trx_id']); ?>
                                <?php else : ?>
                                    <span class="sb-muted"><?php echo esc_html__('not provided', 'shaplapay-payment-gateway-bkash-fluentcart'); ?></span>
                                <?php endif; ?>
                            </td>
                            <td><?php echo wp_kses_post($this->money($row['total'])); ?></td>
                            <td class="sb-mono"><?php echo esc_html($details['manual_wallet'] !== '' ? BkashProcessor::maskWallet($details['manual_wallet']) : '—'); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    protected function renderTransactions(array $rows, int $total, int $paged): void
    {
        ?>
        <h2><?php echo esc_html__('Transactions', 'shaplapay-payment-gateway-bkash-fluentcart'); ?></h2>
        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th><?php echo esc_html__('Date', 'shaplapay-payment-gateway-bkash-fluentcart'); ?></th>
                    <th><?php echo esc_html__('Order', 'shaplapay-payment-gateway-bkash-fluentcart'); ?></th>
                    <th><?php echo esc_html__('Customer', 'shaplapay-payment-gateway-bkash-fluentcart'); ?></th>
                    <th><?php echo esc_html__('bKash TrxID', 'shaplapay-payment-gateway-bkash-fluentcart'); ?></th>
                    <th><?php echo esc_html__('Payment ID', 'shaplapay-payment-gateway-bkash-fluentcart'); ?></th>
                    <th><?php echo esc_html__('Payer wallet', 'shaplapay-payment-gateway-bkash-fluentcart'); ?></th>
                    <th><?php echo esc_html__('Method', 'shaplapay-payment-gateway-bkash-fluentcart'); ?></th>
                    <th><?php echo esc_html__('Status', 'shaplapay-payment-gateway-bkash-fluentcart'); ?></th>
                    <th><?php echo esc_html__('Amount', 'shaplapay-payment-gateway-bkash-fluentcart'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php if (!$rows) : ?>
                    <tr><td colspan="9" class="sb-empty"><?php echo esc_html__('No bKash transactions match these filters.', 'shaplapay-payment-gateway-bkash-fluentcart'); ?></td></tr>
                <?php endif; ?>
                <?php foreach ($rows as $row) : ?>
                    <?php $details = $this->trxDetails($row); ?>
                    <tr>
                        <td><?php echo esc_html($this->localDate($row['created_at'])); ?></td>
                        <td><?php echo wp_kses_post($this->orderLink($row)); ?></td>
                        <td><?php echo wp_kses_post($this->customerText($row)); ?></td>
                        <td class="sb-mono"><?php echo esc_html($details['trx_id'] ?: '—'); ?></td>
                        <td class="sb-mono"><?php echo esc_html($details['payment_id'] ?: '—'); ?></td>
                        <td class="sb-mono"><?php echo esc_html($details['payer'] ?: '—'); ?></td>
                        <td><?php echo esc_html($details['manual'] ? __('Manual transfer', 'shaplapay-payment-gateway-bkash-fluentcart') : __('API', 'shaplapay-payment-gateway-bkash-fluentcart')); ?></td>
                        <td><?php echo wp_kses_post($this->statusBadge((string) $row['status'])); ?></td>
                        <td><?php echo wp_kses_post($this->money($row['total'])); ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php $this->renderPagination($total, $paged); ?>
        <?php
    }

    protected function renderRefunds(array $rows, int $total): void
    {
        ?>
        <div class="sb-panel">
            <h2><?php echo esc_html__('Refunds', 'shaplapay-payment-gateway-bkash-fluentcart'); ?></h2>
            <?php if (!$rows) : ?>
                <p class="sb-empty"><?php echo esc_html__('No bKash refunds recorded for these filters.', 'shaplapay-payment-gateway-bkash-fluentcart'); ?></p>
            <?php else : ?>
                <?php if ($total > count($rows)) : ?>
                    <p class="sb-muted">
                        <?php
                        printf(
                            /* translators: %s: number of refunds shown */
                            esc_html__('Showing the most recent %s refunds.', 'shaplapay-payment-gateway-bkash-fluentcart'),
                            esc_html(number_format_i18n(count($rows)))
                        );
                        ?>
                    </p>
                <?php endif; ?>
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th><?php echo esc_html__('Date', 'shaplapay-payment-gateway-bkash-fluentcart'); ?></th>
                            <th><?php echo esc_html__('Order', 'shaplapay-payment-gateway-bkash-fluentcart'); ?></th>
                            <th><?php echo esc_html__('Customer', 'shaplapay-payment-gateway-bkash-fluentcart'); ?></th>
                            <th><?php echo esc_html__('bKash refund TrxID', 'shaplapay-payment-gateway-bkash-fluentcart'); ?></th>
                            <th><?php echo esc_html__('Status', 'shaplapay-payment-gateway-bkash-fluentcart'); ?></th>
                            <th><?php echo esc_html__('Amount', 'shaplapay-payment-gateway-bkash-fluentcart'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($rows as $row) : ?>
                            <tr>
                                <td><?php echo esc_html($this->localDate($row['created_at'])); ?></td>
                                <td><?php echo wp_kses_post($this->orderLink($row)); ?></td>
                                <td><?php echo wp_kses_post($this->customerText($row)); ?></td>
                                <td class="sb-mono"><?php echo esc_html($row['vendor_charge_id'] ?: '—'); ?></td>
                                <td><?php echo wp_kses_post($this->statusBadge((string) $row['status'])); ?></td>
                                <td><?php echo wp_kses_post($this->money($row['total'])); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
        <?php
    }

    protected function renderPagination(int $total, int $paged): void
    {
        $pages = (int) ceil($total / self::PER_PAGE);

        if ($pages < 2) {
            return;
        }

        $links = paginate_links([
            'base'      => add_query_arg('paged', '%#%'),
            'format'    => '',
            'current'   => $paged,
            'total'     => $pages,
            'prev_text' => '&laquo;',
            'next_text' => '&raquo;',
            'type'      => 'plain',
        ]);

        if ($links) {
            printf(
                '<div class="tablenav"><div class="tablenav-pages">%s</div></div>',
                wp_kses_post($links)
            );
        }
    }

    protected function describeRange(array $filters): string
    {
        if ($filters['raw_from'] === '' && $filters['raw_to'] === '') {
            return __('Showing all dates.', 'shaplapay-payment-gateway-bkash-fluentcart');
        }

        if ($filters['raw_from'] !== '' && $filters['raw_to'] !== '') {
            return sprintf(
                /* translators: 1: start date, 2: end date */
                __('Showing %1$s to %2$s (site time).', 'shaplapay-payment-gateway-bkash-fluentcart'),
                $filters['raw_from'],
                $filters['raw_to']
            );
        }

        if ($filters['raw_from'] !== '') {
            return sprintf(
                /* translators: %s: start date */
                __('Showing from %s (site time).', 'shaplapay-payment-gateway-bkash-fluentcart'),
                $filters['raw_from']
            );
        }

        return sprintf(
            /* translators: %s: end date */
            __('Showing up to %s (site time).', 'shaplapay-payment-gateway-bkash-fluentcart'),
            $filters['raw_to']
        );
    }

    /**
     * Read one sanitized string from the query string.
     *
     * The filter is a read-only view of existing data, so it is sanitized and
     * capability-gated rather than nonce-verified: a nonce here would protect
     * nothing, and would break plain navigation from the admin menu.
     */
    protected function getQueryParam(string $key): string
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only report filter, no state change.
        return sanitize_text_field(wp_unslash((string) ($_GET[$key] ?? '')));
    }

    protected function getPage(): int
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only pagination, no state change.
        return max(1, absint(wp_unslash((string) ($_GET['paged'] ?? 1))));
    }

    protected function getFilters(): array
    {
        $from = $this->sanitizeDate($this->getQueryParam('date_from'));
        $to = $this->sanitizeDate($this->getQueryParam('date_to'));

        $status = $this->getQueryParam('status');
        if (!array_key_exists($status, self::statusLabels())) {
            $status = '';
        }

        $mode = $this->getQueryParam('payment_mode');
        if (!in_array($mode, ['test', 'live'], true)) {
            $mode = '';
        }

        return [
            'from'     => $from !== '' ? get_gmt_from_date($from . ' 00:00:00') : '',
            'to'       => $to !== '' ? get_gmt_from_date($to . ' 23:59:59') : '',
            'status'   => $status,
            'mode'     => $mode,
            'raw_from' => $from,
            'raw_to'   => $to,
        ];
    }

    protected function sanitizeDate(string $value): string
    {
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $matches)) {
            return '';
        }

        return checkdate((int) $matches[2], (int) $matches[3], (int) $matches[1]) ? $value : '';
    }

    protected function getTotals(array $filters): array
    {
        global $wpdb;

        $trxTable = $wpdb->prefix . 'fct_order_transactions';

        // Every statement in this class is a literal string so it stays static
        // and statically analyzable. Filters that are not set are passed as an
        // empty string and neutralised in SQL with "= '' OR ...", so no query
        // is ever assembled by concatenation.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Read-only aggregate over FluentCart's table; the table name and every filter value are bound through $wpdb->prepare().
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT status, COUNT(*) AS cnt, COALESCE(SUM(total), 0) AS total
                 FROM %i t
                 WHERE t.payment_method = %s
                   AND ( t.transaction_type IS NULL OR t.transaction_type <> %s )
                   AND ( %s = '' OR t.status = %s )
                   AND ( %s = '' OR t.payment_mode = %s )
                   AND ( %s = '' OR t.created_at >= %s )
                   AND ( %s = '' OR t.created_at <= %s )
                 GROUP BY status",
                $trxTable,
                'bkash',
                'refund',
                $filters['status'],
                $filters['status'],
                $filters['mode'],
                $filters['mode'],
                $filters['from'],
                $filters['from'],
                $filters['to'],
                $filters['to']
            ),
            ARRAY_A
        ) ?: [];

        $totals = [
            'collected' => 0,
            'pending'   => 0,
            'failed'    => 0,
            'count'     => 0,
        ];

        foreach ($rows as $row) {
            $status = (string) $row['status'];
            $totals['count'] += (int) $row['cnt'];

            if ($status === 'succeeded') {
                $totals['collected'] += (int) $row['total'];
            } elseif ($status === 'pending') {
                $totals['pending'] += (int) $row['total'];
            } elseif ($status === 'failed') {
                $totals['failed'] += (int) $row['total'];
            }
        }

        $refunds = $this->getRefundsSummary($filters);
        $totals['refunded'] = $refunds['total'];
        $totals['refund_count'] = $refunds['count'];

        return $totals;
    }

    protected function getRefundsSummary(array $filters): array
    {
        global $wpdb;

        $trxTable = $wpdb->prefix . 'fct_order_transactions';

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Read-only refund aggregate over FluentCart's table; the table name and every filter value are bound through $wpdb->prepare().
        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT COUNT(*) AS cnt, COALESCE(SUM(total), 0) AS total
                 FROM %i t
                 WHERE t.payment_method = %s
                   AND t.transaction_type = %s
                   AND ( %s = '' OR t.payment_mode = %s )
                   AND ( %s = '' OR t.created_at >= %s )
                   AND ( %s = '' OR t.created_at <= %s )",
                $trxTable,
                'bkash',
                'refund',
                $filters['mode'],
                $filters['mode'],
                $filters['from'],
                $filters['from'],
                $filters['to'],
                $filters['to']
            ),
            ARRAY_A
        ) ?: ['cnt' => 0, 'total' => 0];

        return [
            'count' => (int) $row['cnt'],
            'total' => (int) $row['total'],
        ];
    }

    protected function getTransactions(array $filters, int $limit, int $offset): array
    {
        global $wpdb;

        $trxTable = $wpdb->prefix . 'fct_order_transactions';
        $ordersTable = $wpdb->prefix . 'fct_orders';
        $customersTable = $wpdb->prefix . 'fct_customers';

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Read-only report join over FluentCart's tables; table names and every filter and paging value are bound through $wpdb->prepare().
        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT t.*,
                        o.uuid AS order_uuid,
                        o.status AS order_status,
                        c.email AS customer_email,
                        c.first_name AS customer_first_name,
                        c.last_name AS customer_last_name
                 FROM %i t
                 LEFT JOIN %i o ON o.id = t.order_id
                 LEFT JOIN %i c ON c.id = o.customer_id
                 WHERE t.payment_method = %s
                   AND ( t.transaction_type IS NULL OR t.transaction_type <> %s )
                   AND ( %s = '' OR t.status = %s )
                   AND ( %s = '' OR t.payment_mode = %s )
                   AND ( %s = '' OR t.created_at >= %s )
                   AND ( %s = '' OR t.created_at <= %s )
                 ORDER BY t.id DESC
                 LIMIT %d OFFSET %d",
                $trxTable,
                $ordersTable,
                $customersTable,
                'bkash',
                'refund',
                $filters['status'],
                $filters['status'],
                $filters['mode'],
                $filters['mode'],
                $filters['from'],
                $filters['from'],
                $filters['to'],
                $filters['to'],
                $limit,
                $offset
            ),
            ARRAY_A
        ) ?: [];
    }

    protected function getManualPending(): array
    {
        global $wpdb;

        $trxTable = $wpdb->prefix . 'fct_order_transactions';
        $ordersTable = $wpdb->prefix . 'fct_orders';
        $customersTable = $wpdb->prefix . 'fct_customers';

        // FluentCart stores meta through json_encode(), so the manual flag is
        // always serialized as "bkash_manual":1. Matching the serialized form
        // avoids reading the flag with a JSON function, which raises a hard
        // MySQL error on any row whose meta is not valid JSON.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Read-only report join over FluentCart's tables; table names and every value are bound through $wpdb->prepare().
        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT t.*,
                        o.uuid AS order_uuid,
                        o.status AS order_status,
                        c.email AS customer_email,
                        c.first_name AS customer_first_name,
                        c.last_name AS customer_last_name
                 FROM %i t
                 LEFT JOIN %i o ON o.id = t.order_id
                 LEFT JOIN %i c ON c.id = o.customer_id
                 WHERE t.payment_method = %s
                   AND t.status = %s
                   AND t.meta LIKE %s
                 ORDER BY t.id DESC
                 LIMIT %d",
                $trxTable,
                $ordersTable,
                $customersTable,
                'bkash',
                'pending',
                '%"bkash_manual":1%',
                self::PANEL_LIMIT
            ),
            ARRAY_A
        ) ?: [];
    }

    protected function getRefunds(array $filters): array
    {
        global $wpdb;

        $trxTable = $wpdb->prefix . 'fct_order_transactions';
        $ordersTable = $wpdb->prefix . 'fct_orders';
        $customersTable = $wpdb->prefix . 'fct_customers';

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Read-only refund report join over FluentCart's tables; table names and every filter value are bound through $wpdb->prepare().
        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT t.*,
                        o.uuid AS order_uuid,
                        o.status AS order_status,
                        c.email AS customer_email,
                        c.first_name AS customer_first_name,
                        c.last_name AS customer_last_name
                 FROM %i t
                 LEFT JOIN %i o ON o.id = t.order_id
                 LEFT JOIN %i c ON c.id = o.customer_id
                 WHERE t.payment_method = %s
                   AND t.transaction_type = %s
                   AND ( %s = '' OR t.payment_mode = %s )
                   AND ( %s = '' OR t.created_at >= %s )
                   AND ( %s = '' OR t.created_at <= %s )
                 ORDER BY t.id DESC
                 LIMIT %d",
                $trxTable,
                $ordersTable,
                $customersTable,
                'bkash',
                'refund',
                $filters['mode'],
                $filters['mode'],
                $filters['from'],
                $filters['from'],
                $filters['to'],
                $filters['to'],
                self::PANEL_LIMIT
            ),
            ARRAY_A
        ) ?: [];
    }

    protected function trxDetails(array $row): array
    {
        $meta = json_decode((string) ($row['meta'] ?? ''), true);

        if (!is_array($meta)) {
            $meta = [];
        }

        $vendor = (string) ($row['vendor_charge_id'] ?? '');
        $paymentId = (string) ($meta['bkash_payment_id'] ?? '');
        $trxId = (string) ($meta['bkash_trx_id'] ?? '');

        if ($trxId === '' && $vendor !== '' && $vendor !== $paymentId) {
            $trxId = $vendor;
        }

        if ($paymentId === '' && $vendor !== '' && $vendor !== $trxId) {
            $paymentId = $vendor;
        }

        return [
            'payment_id'       => $paymentId,
            'trx_id'           => $trxId,
            // Masked again here so rows written before masking was added cannot
            // surface a full wallet number on screen.
            'payer'            => BkashProcessor::maskWallet((string) ($meta['bkash_customer_msisdn'] ?? '')),
            'manual'           => !empty($meta['bkash_manual']),
            'manual_wallet'    => (string) ($meta['bkash_manual_wallet'] ?? ''),
            'manual_reference' => (string) ($meta['bkash_manual_reference'] ?? ''),
            'submitted_trx_id' => (string) ($meta['bkash_manual_submitted_trx_id'] ?? ''),
        ];
    }

    protected function orderLink(array $row): string
    {
        $orderId = (int) ($row['order_id'] ?? 0);
        $label = $row['order_uuid'] ?? '';

        if (!$orderId) {
            return '<span class="sb-muted">—</span>';
        }

        if (!$label) {
            $label = '#' . $orderId;
        }

        return sprintf(
            '<a href="%s" class="sb-mono">%s</a>',
            esc_url(admin_url('admin.php?page=fluent-cart#/orders/' . $orderId . '/view')),
            esc_html($label)
        );
    }

    protected function customerText(array $row): string
    {
        $name = trim(((string) ($row['customer_first_name'] ?? '')) . ' ' . ((string) ($row['customer_last_name'] ?? '')));
        $email = (string) ($row['customer_email'] ?? '');

        if ($name === '' && $email === '') {
            return '<span class="sb-muted">—</span>';
        }

        $text = $name !== '' ? esc_html($name) : esc_html($email);

        if ($name !== '' && $email !== '') {
            $text .= '<br /><span class="sb-muted">' . esc_html($email) . '</span>';
        }

        return $text;
    }

    protected function statusBadge(string $status): string
    {
        $labels = self::statusLabels();
        $label = $labels[$status] ?? ucfirst(str_replace('_', ' ', $status));

        return sprintf(
            '<span class="sb-status sb-status-%s">%s</span>',
            esc_attr(sanitize_html_class($status)),
            esc_html($label)
        );
    }

    protected function money($minorUnits): string
    {
        if (class_exists(Helper::class) && method_exists(Helper::class, 'toDecimal')) {
            return Helper::toDecimal((int) $minorUnits);
        }

        return number_format(((int) $minorUnits) / 100, 2, '.', ',') . ' BDT';
    }

    protected function localDate($gmt): string
    {
        $gmt = (string) $gmt;

        if ($gmt === '' || strpos($gmt, '0000-00-00') === 0) {
            return '—';
        }

        return (string) get_date_from_gmt($gmt, 'M j, Y H:i');
    }
}
