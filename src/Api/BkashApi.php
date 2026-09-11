<?php

namespace ShaplaPayBkash\Api;

use ShaplaPayBkash\BkashSettings;

/**
 * HTTP client for the bKash Payment Gateway (Tokenized Checkout, v1.2.0-beta).
 *
 * Endpoints used:
 *  - POST {base}/token/grant          (headers: username, password; body: app_key, app_secret)
 *  - POST {base}/create               (headers: Authorization: id_token, X-App-Key)
 *  - POST {base}/execute              (body: paymentID)
 *  - POST {base}/payment/status       (body: paymentID)
 *  - POST {base}/payment/refund       (body: amount, paymentID, trxID, sku, reason)
 *  - POST {base}/payment/refund/status/{paymentID}/{refundTrxID}
 *
 * @see https://developer.bka.sh/docs/tokenized-checkout-process
 */
class BkashApi
{
    /**
     * @var array{base_url:string,app_key:string,app_secret:string,username:string,password:string,cache_key:string}
     */
    protected array $config;

    protected bool $canCacheToken;

    public function __construct(array $config = [], ?BkashSettings $settings = null)
    {
        $settings = $settings ?: new BkashSettings();
        $mode = $settings->getMode();

        $hasOverride = !empty($config['app_key']);

        $this->config = wp_parse_args($config, [
            'base_url'   => $settings->getBaseUrl(),
            'app_key'    => $settings->getAppKey(),
            'app_secret' => $settings->getAppSecret(),
            'username'   => $settings->getUsername(),
            'password'   => $settings->getPassword(),
            'cache_key'  => 'shaplapay_bkash_token_' . $mode,
        ]);

        // Validation runs (credentials not saved yet) must never touch the
        // runtime token cache, and never reuse a cached token either.
        $this->canCacheToken = !$hasOverride;
    }

    public function getModeConfig(): array
    {
        return $this->config;
    }

    /**
     * Create Payment API. Returns the raw success response
     * (paymentID, bkashURL, callback URLs, transactionStatus: Initiated).
     *
     * @return array|\WP_Error
     */
    public function createPayment(array $payload)
    {
        return $this->request('create', $payload);
    }

    /**
     * Execute Payment API - captures a payment the customer has approved on
     * the bKash hosted page. A paymentID can only be executed once.
     *
     * @return array|\WP_Error
     */
    public function executePayment(string $paymentID)
    {
        return $this->request('execute', ['paymentID' => $paymentID]);
    }

    /**
     * Query Payment API - transactionStatus is Initiated until executed,
     * Completed after a successful execute.
     *
     * @return array|\WP_Error
     */
    public function queryPayment(string $paymentID)
    {
        return $this->request('payment/status', ['paymentID' => $paymentID]);
    }

    /**
     * Refund API. Allowed within 15 days of the original transaction.
     *
     * @return array|\WP_Error
     */
    public function refund(array $payload)
    {
        return $this->request('payment/refund', $payload);
    }

    /**
     * @return array|\WP_Error
     */
    public function refundStatus(string $paymentID, string $refundTrxID)
    {
        $path = 'payment/refund/status/' . rawurlencode($paymentID) . '/' . rawurlencode($refundTrxID);

        return $this->request($path, [
            'paymentID'   => $paymentID,
            'refundTrxID' => $refundTrxID,
        ]);
    }

    /**
     * Grant Token API with local caching. bKash blocks merchants that grant
     * tokens more than twice per hour, so the id_token is cached until 2
     * minutes before its stated expiry.
     *
     * @return string|\WP_Error id_token
     */
    public function grantToken(bool $force = false)
    {
        if ($this->canCacheToken && !$force) {
            $cached = get_option($this->config['cache_key']);

            if (is_array($cached)
                && !empty($cached['token'])
                && !empty($cached['expires_at'])
                && $cached['expires_at'] > time()
            ) {
                return $cached['token'];
            }
        }

        if (empty($this->config['app_key']) || empty($this->config['app_secret'])
            || empty($this->config['username']) || empty($this->config['password'])
        ) {
            return new \WP_Error(
                'bkash_missing_credentials',
                __('bKash API credentials are not configured. Please set App Key, App Secret, Username and Password for the current store mode.', 'shaplapay-bkash-payment-gateway-for-fluentcart')
            );
        }

        $response = $this->httpRequest('token/grant', [
            'app_key'    => $this->config['app_key'],
            'app_secret' => $this->config['app_secret'],
        ], [
            'username' => $this->config['username'],
            'password' => $this->config['password'],
        ]);

        if (is_wp_error($response)) {
            return $response;
        }

        if (empty($response['id_token'])) {
            return new \WP_Error(
                'bkash_token_error',
                __('bKash did not return an API token. Please verify your credentials.', 'shaplapay-bkash-payment-gateway-for-fluentcart'),
                $response
            );
        }

        if ($this->canCacheToken) {
            $expiresIn = isset($response['expires_in']) ? (int) $response['expires_in'] : 3600;

            update_option($this->config['cache_key'], [
                'token'      => $response['id_token'],
                'expires_at' => time() + max(60, $expiresIn - 120),
            ], false);
        }

        return $response['id_token'];
    }

    public function clearTokenCache(): void
    {
        delete_option($this->config['cache_key']);
    }

    /**
     * @return array|\WP_Error
     */
    protected function request(string $path, array $body)
    {
        $token = $this->grantToken();

        if (is_wp_error($token)) {
            return $token;
        }

        $response = $this->httpRequest($path, $body, [
            'Authorization' => $token,
            'X-App-Key'     => $this->config['app_key'],
        ]);

        // Token expired between cache check and use - refresh once and retry.
        if (is_wp_error($response) && $response->get_error_code() === 'bkash_http_401') {
            $token = $this->grantToken(true);

            if (is_wp_error($token)) {
                return $token;
            }

            $response = $this->httpRequest($path, $body, [
                'Authorization' => $token,
                'X-App-Key'     => $this->config['app_key'],
            ]);
        }

        return $response;
    }

    /**
     * @return array|\WP_Error Decoded JSON payload on success.
     */
    protected function httpRequest(string $path, array $body, array $authHeaders)
    {
        $url = trailingslashit($this->config['base_url']) . ltrim($path, '/');

        $headers = array_merge([
            'Content-Type' => 'application/json',
            'Accept'       => 'application/json',
        ], $authHeaders);

        $args = [
            'method'      => 'POST',
            'timeout'     => 30, // bKash recommends a 30s default timeout
            'redirection' => 2,
            'blocking'    => true,
            'headers'     => $headers,
            'body'        => wp_json_encode($body),
        ];

        $args = apply_filters('fluent_cart/bkash/http_request_args', $args, $path, $body);

        $response = wp_remote_post($url, $args);

        if (is_wp_error($response)) {
            return new \WP_Error(
                'bkash_http_error',
                sprintf(
                    /* translators: %s: bKash API error message */
                    __('Could not reach the bKash API: %s', 'shaplapay-bkash-payment-gateway-for-fluentcart'),
                    $response->get_error_message()
                )
            );
        }

        $statusCode = (int) wp_remote_retrieve_response_code($response);
        $rawBody = wp_remote_retrieve_body($response);
        $data = json_decode($rawBody, true);

        if (!is_array($data)) {
            $data = [];
        }

        if ($statusCode === 401) {
            if ($this->canCacheToken) {
                $this->clearTokenCache();
            }

            return new \WP_Error(
                'bkash_http_401',
                __('bKash API authorization failed (invalid or expired token).', 'shaplapay-bkash-payment-gateway-for-fluentcart'),
                $data
            );
        }

        if ($statusCode < 200 || $statusCode >= 300) {
            return new \WP_Error(
                'bkash_http_error',
                $this->extractErrorMessage($data, sprintf(
                    /* translators: %d: bKash API HTTP status code */
                    __('bKash API returned HTTP %d.', 'shaplapay-bkash-payment-gateway-for-fluentcart'),
                    $statusCode
                )),
                $data
            );
        }

        // bKash error envelope: { errorCode, errorMessage }
        if (!empty($data['errorCode'])) {
            return new \WP_Error(
                'bkash_api_error',
                $this->extractErrorMessage($data),
                $data
            );
        }

        // bKash status envelope: statusCode "0000" means success
        if (isset($data['statusCode']) && $data['statusCode'] !== '0000') {
            return new \WP_Error(
                'bkash_api_error',
                $this->extractErrorMessage($data),
                $data
            );
        }

        return $data;
    }

    protected function extractErrorMessage(array $data, string $fallback = ''): string
    {
        $message = $data['errorMessage'] ?? ($data['statusMessage'] ?? '');

        if ($message === '') {
            $message = $fallback ?: __('bKash API request failed.', 'shaplapay-bkash-payment-gateway-for-fluentcart');
        }

        return $message;
    }
}
