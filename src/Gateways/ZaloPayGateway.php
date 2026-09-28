<?php
namespace Jankx\Extensions\Zalopay\Gateways;

use Jankx\Extensions\PaymentSystem\Gateways\AbstractGateway;
use Jankx\Extensions\PaymentSystem\Models\Transaction;

/**
 * ZaloPay gateway.
 *
 * Implements the ZaloPay OpenAPI v2 hosted-redirect flow
 * (https://docs.zalopay.vn):
 *
 *  - Create order : POST /v2/create -> `order_url` (redirect the customer
 *                   to the ZaloPay gateway, optionally with NAPAS VietQR)
 *  - Return flow  : verify the redirect `checksum` (HMAC-SHA256 with key2)
 *                   then reconcile the final result via /v2/query
 *  - Status query : POST /v2/query
 *  - Refund       : POST /v2/refund (followed by /v2/query_refund while
 *                   the refund is processing)
 *
 * Request `mac` values are HMAC-SHA256 with key1. The redirect/return
 * checksum uses key2 over the exact `appid|apptransid|pmcid|bankcode|
 * amount|discountamount|status` raw string. Refunds are addressed by
 * `zp_trans_id` which is persisted on the transaction when the payment
 * result is reconciled.
 *
 * @package Jankx\Extensions\Zalopay
 */
class ZaloPayGateway extends AbstractGateway
{
    const API_URL_TEST = 'https://sb-openapi.zalopay.vn';
    const API_URL_PROD = 'https://openapi.zalopay.vn';

    const PATH_CREATE = '/v2/create';
    const PATH_QUERY = '/v2/query';
    const PATH_REFUND = '/v2/refund';
    const PATH_QUERY_REFUND = '/v2/query_refund';

    const MIN_AMOUNT = 1000;

    const RETURN_CODE_SUCCESS = 1;
    const RETURN_CODE_PROCESSING = 3;

    protected $slug = 'zalopay';

    protected $displayName = 'ZaloPay';

    protected $config = [];

    protected $credentials = [
        'app_id' => '',
        'key1'   => '',
        'key2'   => '',
    ];

    public function getName(): string
    {
        return $this->displayName;
    }

    public function initialize(array $parameters): void
    {
        $this->config = wp_parse_args($parameters, [
            'testMode'          => false,
            'sandbox_app_id'    => '',
            'sandbox_key1'      => '',
            'sandbox_key2'      => '',
            'production_app_id' => '',
            'production_key1'   => '',
            'production_key2'   => '',
        ]);

        $isTest = !empty($this->config['testMode']);
        $prefix = $isTest ? 'sandbox' : 'production';

        $this->credentials = [
            'app_id' => (string) ($this->config["{$prefix}_app_id"] ?? ''),
            'key1'   => (string) ($this->config["{$prefix}_key1"] ?? ''),
            'key2'   => (string) ($this->config["{$prefix}_key2"] ?? ''),
        ];
    }

    /**
     * Whether the gateway can be used (needs an app id, key1 and key2 for the
     * active environment).
     */
    public function isAvailable(): bool
    {
        return $this->credentials['app_id'] !== ''
            && $this->credentials['key1'] !== ''
            && $this->credentials['key2'] !== '';
    }

    /**
     * Create a ZaloPay order and return a redirect payload to `order_url`.
     *
     * @return array{status: string, redirectUrl: string, redirectMethod: string, redirectData: array, transactionId: string}
     */
    public function purchase(array $parameters): array
    {
        $transactionId = (string) ($parameters['transactionId'] ?? '');
        $amount = (int) round((float) ($parameters['amount'] ?? 0));

        if ($amount < self::MIN_AMOUNT) {
            return [
                'status'  => 'failed',
                'message' => __('ZaloPay amount must be at least 1,000 VND.', 'jankx'),
                'code'    => 'AMOUNT_INVALID',
            ];
        }

        $appTransId = $this->buildAppTransId();
        $appTime = $this->buildTimestamp();
        $appUser = $this->buildAppUser($parameters);
        $description = $this->sanitizeOrderInfo(
            (string) ($parameters['description'] ?? ''),
            $appTransId
        );
        $bankCode = (string) ($parameters['bankCode'] ?? 'zalopayapp');

        $embedData = $this->buildEmbedData($parameters);
        $item = $this->buildItemArray($parameters);

        $body = [
            'app_id'       => $this->credentials['app_id'],
            'app_user'     => $appUser,
            'app_trans_id' => $appTransId,
            'app_time'     => $appTime,
            'amount'       => $amount,
            'item'         => $item,
            'description'  => $description,
            'embed_data'   => $embedData,
            'bank_code'    => $bankCode,
        ];

        foreach (['callback_url', 'expire_duration_seconds', 'title', 'currency', 'phone', 'email', 'address'] as $key) {
            if (!empty($parameters[$key])) {
                $body[$key] = $parameters[$key];
            }
        }

        $body['mac'] = $this->sign($this->rawCreateMac(
            $amount,
            $appTransId,
            $appUser,
            $appTime,
            $embedData,
            $item
        ));

        $response = $this->apiRequest(self::PATH_CREATE, $body);

        $this->persistOrderRef($transactionId, $appTransId);

        $returnCode = (int) ($response['return_code'] ?? -1);
        if ($returnCode !== self::RETURN_CODE_SUCCESS) {
            return [
                'status'  => 'failed',
                'message' => (string) ($response['return_message'] ?? ($response['sub_return_message'] ?? __('ZaloPay order could not be created.', 'jankx'))),
                'code'    => (string) ($response['return_code'] ?? ''),
                'sub_code'=> (string) ($response['sub_return_code'] ?? ''),
                'raw'     => $response,
            ];
        }

        return [
            'status'         => 'redirect',
            'redirectUrl'    => (string) ($response['order_url'] ?? ''),
            'redirectMethod' => 'GET',
            'redirectData'   => $response,
            'transactionId'  => $appTransId,
        ];
    }

    /**
     * Verify the ZaloPay redirect result and reconcile the final status with
     * the query API (the redirect result alone is not authoritative: per the
     * ZaloPay flow the merchant must query the order status when no callback
     * was received yet).
     *
     * @return array{status: string, transactionId: string, message: string, code?: string, raw?: array}
     */
    public function completePurchase(array $parameters): array
    {
        $params = is_array($parameters['request_params'] ?? null) ? $parameters['request_params'] : $parameters;

        if (!$this->verifyRedirectChecksum($params)) {
            return [
                'status'        => 'failed',
                'transactionId' => (string) ($params['apptransid'] ?? ''),
                'message'       => __('Invalid checksum from ZaloPay.', 'jankx'),
                'code'          => 'HASH_MISMATCH',
                'raw'           => $params,
            ];
        }

        if ((string) ($params['appid'] ?? '') !== $this->credentials['app_id']) {
            return [
                'status'        => 'failed',
                'transactionId' => (string) ($params['apptransid'] ?? ''),
                'message'       => __('App id does not match.', 'jankx'),
                'code'          => 'APP_ID_MISMATCH',
                'raw'           => $params,
            ];
        }

        $appTransId = (string) ($params['apptransid'] ?? '');

        $status = $this->fetchOrderStatus($appTransId);
        if (!empty($status['zp_trans_id'])) {
            $this->persistZpTransId($appTransId, (string) $status['zp_trans_id']);
        }

        $returnCode = (int) ($status['return_code'] ?? -1);

        if ($returnCode === self::RETURN_CODE_SUCCESS) {
            return [
                'status'        => 'success',
                'transactionId' => $appTransId,
                'message'       => __('Payment successful.', 'jankx'),
                'code'          => '1',
                'raw'           => $params,
            ];
        }

        if ($returnCode === self::RETURN_CODE_PROCESSING) {
            return [
                'status'        => 'pending',
                'transactionId' => $appTransId,
                'message'       => __('Payment is being processed.', 'jankx'),
                'code'          => '3',
                'raw'           => $params,
            ];
        }

        // Query could not be reached or returned a terminal failure: fall back
        // to the redirect `status` flag (1 = success per the ZaloPay spec).
        if ($returnCode < 0 && (int) ($params['status'] ?? 0) === 1) {
            return [
                'status'        => 'success',
                'transactionId' => $appTransId,
                'message'       => __('Payment successful.', 'jankx'),
                'code'          => '1',
                'raw'           => $params,
            ];
        }

        return [
            'status'        => 'failed',
            'transactionId' => $appTransId,
            'message'       => (string) ($status['return_message'] ?? __('Payment failed.', 'jankx')),
            'code'          => (string) ($status['return_code'] ?? ''),
            'raw'           => $params,
        ];
    }

    /**
     * Refund a paid transaction.
     *
     * @param array $parameters Required: `zp_trans_id` (the ZaloPay
     *                          transaction id), `amount`, `description`.
     * @return array{status: string, message: string, code?: string, sub_code?: string, raw?: array}
     */
    public function refund(array $parameters): array
    {
        $zpTransId = $this->resolveZpTransId($parameters);
        if ($zpTransId === '') {
            return [
                'status'  => 'failed',
                'message' => __('ZaloPay zp_trans_id is required to refund.', 'jankx'),
                'code'    => 'MISSING_ZPTRANS_ID',
            ];
        }

        $mRefundId = (string) ($parameters['mRefundId'] ?? $this->buildMRefundId());
        $amount = (int) round((float) ($parameters['amount'] ?? 0));
        $description = $this->sanitizeOrderInfo((string) ($parameters['description'] ?? ''), $mRefundId);
        $timestamp = (int) ($parameters['timestamp'] ?? $this->buildTimestamp());

        $body = [
            'm_refund_id'  => $mRefundId,
            'app_id'       => $this->credentials['app_id'],
            'zp_trans_id'  => $zpTransId,
            'amount'       => $amount,
            'timestamp'    => $timestamp,
            'description'  => $description,
            'mac'          => $this->sign($this->rawRefundMac($zpTransId, $amount, $description, $timestamp)),
        ];

        $response = $this->apiRequest(self::PATH_REFUND, $body);

        $returnCode = (int) ($response['return_code'] ?? -1);

        if ($returnCode === self::RETURN_CODE_SUCCESS) {
            return [
                'status'  => 'success',
                'message' => (string) ($response['return_message'] ?? __('Refund successful.', 'jankx')),
                'code'    => '1',
                'sub_code'=> (string) ($response['sub_return_code'] ?? ''),
                'raw'     => $response,
            ];
        }

        if ($returnCode === self::RETURN_CODE_PROCESSING) {
            return $this->awaitRefundStatus($mRefundId, $response);
        }

        return [
            'status'  => 'failed',
            'message' => (string) ($response['return_message'] ?? __('Refund failed.', 'jankx')),
            'code'    => (string) ($response['return_code'] ?? ''),
            'sub_code'=> (string) ($response['sub_return_code'] ?? ''),
            'raw'     => $response,
        ];
    }

    /**
     * ZaloPay query order API: reconcile the payment status.
     *
     * @return string 'completed' | 'pending' | 'failed'
     */
    public function queryStatus(string $transactionId): string
    {
        $status = $this->fetchOrderStatus($transactionId);
        if (!empty($status['zp_trans_id'])) {
            $this->persistZpTransId($transactionId, (string) $status['zp_trans_id']);
        }

        $returnCode = (int) ($status['return_code'] ?? -1);

        if ($returnCode === self::RETURN_CODE_SUCCESS) {
            return 'completed';
        }
        if ($returnCode === self::RETURN_CODE_PROCESSING) {
            return 'pending';
        }

        return 'failed';
    }

    /**
     * Query the status of a refund request.
     *
     * @return array Raw query_refund response.
     */
    public function queryRefundStatus(string $mRefundId): array
    {
        $timestamp = $this->buildTimestamp();

        $body = [
            'app_id'      => $this->credentials['app_id'],
            'm_refund_id' => $mRefundId,
            'timestamp'   => $timestamp,
            'mac'         => $this->sign($this->rawQueryRefundMac($mRefundId, $timestamp)),
        ];

        return $this->apiRequest(self::PATH_QUERY_REFUND, $body);
    }

    /**
     * Admin settings fields (keys map to the saved option array).
     */
    public function getSettingsFields(): array
    {
        return [
            'testMode' => [
                'label'       => __('Test mode (ZaloPay sandbox)', 'jankx'),
                'type'        => 'checkbox',
                'description' => __('Enable to use the ZaloPay sandbox environment (sb-openapi.zalopay.vn).', 'jankx'),
                'default'     => '1',
            ],
            'sandbox_app_id' => [
                'label'   => __('App ID (test)', 'jankx'),
                'type'    => 'text',
                'default' => '',
            ],
            'sandbox_key1' => [
                'label'   => __('Key 1 (test)', 'jankx'),
                'type'    => 'password',
                'default' => '',
            ],
            'sandbox_key2' => [
                'label'   => __('Key 2 (test)', 'jankx'),
                'type'    => 'password',
                'default' => '',
            ],
            'production_app_id' => [
                'label' => __('App ID (production)', 'jankx'),
                'type'  => 'text',
            ],
            'production_key1' => [
                'label' => __('Key 1 (production)', 'jankx'),
                'type'  => 'password',
            ],
            'production_key2' => [
                'label' => __('Key 2 (production)', 'jankx'),
                'type'  => 'password',
            ],
        ];
    }

    /**
     * Sign a raw parameter string with a ZaloPay key (defaults to key1).
     */
    protected function sign(string $raw, string $key = ''): string
    {
        if ($key === '') {
            $key = $this->credentials['key1'];
        }
        return hash_hmac('sha256', $raw, $key);
    }

    /**
     * Verify the ZaloPay gateway redirect checksum (HMAC-SHA256 with key2
     * over `appid|apptransid|pmcid|bankcode|amount|discountamount|status`).
     */
    public function verifyRedirectChecksum(array $params): bool
    {
        $checksum = (string) ($params['checksum'] ?? '');
        if ($checksum === '' || $this->credentials['key2'] === '') {
            return false;
        }

        $raw = (string) ($params['appid'] ?? '')
            . '|' . (string) ($params['apptransid'] ?? '')
            . '|' . (string) ($params['pmcid'] ?? '')
            . '|' . (string) ($params['bankcode'] ?? '')
            . '|' . (string) ($params['amount'] ?? '')
            . '|' . (string) ($params['discountamount'] ?? '')
            . '|' . (string) ($params['status'] ?? '');

        return hash_equals($checksum, $this->sign($raw, $this->credentials['key2']));
    }

    /**
     * POST a JSON body to a ZaloPay OpenAPI endpoint.
     */
    protected function apiRequest(string $path, array $body): array
    {
        $response = wp_remote_post($this->getApiUrl($path), [
            'headers' => ['Content-Type' => 'application/json; charset=UTF-8'],
            'body'    => json_encode($body),
            'timeout' => 30,
        ]);

        if (is_wp_error($response)) {
            return [
                'success' => false,
                'message' => $response->get_error_message(),
            ];
        }

        $data = json_decode(wp_remote_retrieve_body($response), true);
        if (!is_array($data)) {
            return [
                'success' => false,
                'message' => __('Invalid response from ZaloPay.', 'jankx'),
            ];
        }

        return $data;
    }

    protected function getApiUrl(string $path): string
    {
        return ($this->isTestMode() ? self::API_URL_TEST : self::API_URL_PROD) . $path;
    }

    protected function isTestMode(): bool
    {
        return !empty($this->config['testMode']);
    }

    /**
     * Raw create-order mac string (fixed order from the ZaloPay v2 spec).
     */
    protected function rawCreateMac(int $amount, string $appTransId, string $appUser, int $appTime, string $embedData, string $item): string
    {
        return $this->credentials['app_id']
            . '|' . $appTransId
            . '|' . $appUser
            . '|' . $amount
            . '|' . $appTime
            . '|' . $embedData
            . '|' . $item;
    }

    /**
     * Raw query-order mac string (the trailing `key1` is the key value).
     */
    protected function rawQueryMac(string $appTransId): string
    {
        return $this->credentials['app_id']
            . '|' . $appTransId
            . '|' . $this->credentials['key1'];
    }

    /**
     * Raw refund mac string (fixed order from the ZaloPay v2 spec).
     */
    protected function rawRefundMac(string $zpTransId, int $amount, string $description, int $timestamp): string
    {
        return $this->credentials['app_id']
            . '|' . $zpTransId
            . '|' . $amount
            . '|' . $description
            . '|' . $timestamp;
    }

    /**
     * Raw query-refund mac string.
     */
    protected function rawQueryRefundMac(string $mRefundId, int $timestamp): string
    {
        return $this->credentials['app_id']
            . '|' . $mRefundId
            . '|' . $timestamp;
    }

    /**
     * Query the ZaloPay order status API.
     *
     * @return array Raw v2/query response.
     */
    protected function fetchOrderStatus(string $appTransId): array
    {
        $body = [
            'app_id'       => $this->credentials['app_id'],
            'app_trans_id' => $appTransId,
            'mac'          => $this->sign($this->rawQueryMac($appTransId)),
        ];

        return $this->apiRequest(self::PATH_QUERY, $body);
    }

    /**
     * Poll query_refund until the refund reaches a terminal state.
     *
     * @return array{status: string, message: string, code?: string, sub_code?: string, raw?: array}
     */
    protected function awaitRefundStatus(string $mRefundId, array $initial): array
    {
        $attempts = 0;
        $status = $initial;

        while ((int) ($status['return_code'] ?? -1) === self::RETURN_CODE_PROCESSING && $attempts < 10) {
            $status = $this->queryRefundStatus($mRefundId);
            if (!empty($status['success']) && $status['success'] === false) {
                break;
            }
            $attempts++;
            sleep(1);
        }

        $returnCode = (int) ($status['return_code'] ?? -1);

        if ($returnCode === self::RETURN_CODE_SUCCESS) {
            return [
                'status'  => 'success',
                'message' => (string) ($status['return_message'] ?? __('Refund successful.', 'jankx')),
                'code'    => '1',
                'sub_code'=> (string) ($status['sub_return_code'] ?? ''),
                'raw'     => $status,
            ];
        }

        return [
            'status'  => 'failed',
            'message' => (string) ($status['return_message'] ?? __('Refund failed.', 'jankx')),
            'code'    => (string) ($status['return_code'] ?? ''),
            'sub_code'=> (string) ($status['sub_return_code'] ?? ''),
            'raw'     => $status,
        ];
    }

    /**
     * Build a unique ZaloPay merchant transaction id: `yyMMdd_{app_id}_{suffix}`.
     */
    protected function buildAppTransId(): string
    {
        return $this->buildMerchantId();
    }

    /**
     * Build a unique ZaloPay refund id: `yyMMdd_{app_id}_{suffix}`.
     */
    protected function buildMRefundId(): string
    {
        return $this->buildMerchantId();
    }

    protected function buildMerchantId(): string
    {
        $day = gmdate('ymd', time() + 7 * 3600); // Vietnam timezone (GMT+7)
        $suffix = substr(md5(uniqid('', true)), 0, 12);
        return $day . '_' . $this->credentials['app_id'] . '_' . $suffix;
    }

    protected function buildTimestamp(): int
    {
        return (int) round(microtime(true) * 1000);
    }

    protected function buildAppUser(array $parameters): string
    {
        foreach (['app_user', 'customer_email', 'customer_phone', 'customer_name'] as $key) {
            if (!empty($parameters[$key])) {
                return substr((string) $parameters[$key], 0, 50);
            }
        }
        return 'appuser';
    }

    protected function buildEmbedData(array $parameters): string
    {
        $embedData = [];

        if (!empty($parameters['returnUrl'])) {
            $embedData['redirecturl'] = (string) $parameters['returnUrl'];
        }

        if (!empty($parameters['preferredPaymentMethod']) && is_array($parameters['preferredPaymentMethod'])) {
            $embedData['preferred_payment_method'] = array_map('strval', $parameters['preferredPaymentMethod']);
        }

        return json_encode($embedData, JSON_UNESCAPED_UNICODE);
    }

    protected function buildItemArray(array $parameters): string
    {
        $items = is_array($parameters['items'] ?? null) ? array_values($parameters['items']) : [];
        return json_encode($items, JSON_UNESCAPED_UNICODE);
    }

    protected function sanitizeOrderInfo(string $description, string $orderId): string
    {
        $info = trim((string) preg_replace('/\s+/', ' ', $description));
        if ($info === '') {
            $info = 'Order ' . $orderId;
        }
        return substr($info, 0, 255);
    }

    protected function persistOrderRef(string $transactionId, string $appTransId): void
    {
        if (!is_numeric($transactionId) || (int) $transactionId <= 0) {
            return;
        }

        $transaction = new Transaction((int) $transactionId);
        if ($transaction->getId()) {
            $transaction->setTransactionId($appTransId);
        }
    }

    protected function persistZpTransId(string $appTransId, string $zpTransId): void
    {
        $transaction = Transaction::find($appTransId, $this->getSlug());
        if ($transaction) {
            $transaction->updateMeta('_zp_trans_id', $zpTransId);
        }
    }

    protected function resolveZpTransId(array $parameters): string
    {
        $zpTransId = (string) ($parameters['zp_trans_id'] ?? ($parameters['zpTransId'] ?? ''));

        if ($zpTransId === '') {
            $appTransId = (string) ($parameters['app_trans_id'] ?? ($parameters['apptransid'] ?? ($parameters['transactionId'] ?? '')));
            if ($appTransId !== '') {
                $transaction = Transaction::find($appTransId, $this->getSlug());
                if ($transaction) {
                    $zpTransId = (string) $transaction->getMeta('_zp_trans_id');
                }
            }
        }

        return $zpTransId;
    }

    protected function getDefaultIcon(): string
    {
        $asset = __DIR__ . '/../../assets/zalopay.svg';
        if (is_file($asset)) {
            $icon = (string) file_get_contents($asset);
            if ($icon !== '') {
                return $icon;
            }
        }

        return '<svg width="60" height="60" viewBox="0 0 60 60" fill="none" xmlns="http://www.w3.org/2000/svg"><rect width="60" height="60" rx="14" fill="#0068FF"/><path d="M12 14H48V20.8L29.4 39H48V46H12V39.2L30.6 20.8H12V14Z" fill="#ffffff"/></svg>';
    }

    protected function getDefaultText(): string
    {
        return $this->displayName;
    }
}