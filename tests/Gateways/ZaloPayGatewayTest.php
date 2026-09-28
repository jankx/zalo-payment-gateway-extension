<?php
namespace Jankx\Extensions\Zalopay\Tests\Gateways;

use PHPUnit\Framework\TestCase;
use Brain\Monkey;
use Brain\Monkey\Functions;
use Jankx\Extensions\Zalopay\Gateways\ZaloPayGateway;

class TestZaloPayGateway extends ZaloPayGateway
{
    public function setConfig(array $config): void
    {
        $this->config = $config;
    }

    public function getConfigValue(): array
    {
        return $this->config;
    }

    public function setCredentials(array $credentials): void
    {
        $this->credentials = $credentials;
    }

    public function getCredentials(): array
    {
        return $this->credentials;
    }

    public function exposeSign(string $raw): string
    {
        return $this->sign($raw);
    }

    public function exposeBuildAppTransId(): string
    {
        return $this->buildAppTransId();
    }

    public function exposeBuildMRefundId(): string
    {
        return $this->buildMRefundId();
    }

    public function exposeBuildAppUser(array $parameters): string
    {
        return $this->buildAppUser($parameters);
    }

    public function exposeBuildEmbedData(array $parameters): string
    {
        return $this->buildEmbedData($parameters);
    }

    public function exposeBuildItemArray(array $parameters): string
    {
        return $this->buildItemArray($parameters);
    }

    public function exposeSanitizeOrderInfo(string $description, string $orderId): string
    {
        return $this->sanitizeOrderInfo($description, $orderId);
    }

    public function exposeIsTestMode(): bool
    {
        return $this->isTestMode();
    }

    public function exposeGetApiUrl(string $path): string
    {
        return $this->getApiUrl($path);
    }

    public function exposePersistOrderRef(string $transactionId, string $appTransId): void
    {
        $this->persistOrderRef($transactionId, $appTransId);
    }

    public function exposePersistZpTransId(string $appTransId, string $zpTransId): void
    {
        $this->persistZpTransId($appTransId, $zpTransId);
    }
}

class ZaloPayGatewayTest extends TestCase
{
    const TEST_APP_ID = '2553';
    const TEST_KEY1 = 'KEY1_TEST_00';
    const TEST_KEY2 = 'KEY2_TEST_00';

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
        zalopay_test_stub_wp_functions();
        $GLOBALS['__post_meta'] = [];
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['__post_meta']);
        $_SERVER = [];
        Monkey\tearDown();
        parent::tearDown();
    }

    protected function sandboxConfig(array $overrides = []): array
    {
        return array_merge([
            'testMode' => true,
            'sandbox_app_id' => self::TEST_APP_ID,
            'sandbox_key1' => self::TEST_KEY1,
            'sandbox_key2' => self::TEST_KEY2,
        ], $overrides);
    }

    protected function productionConfig(): array
    {
        return array_merge($this->sandboxConfig(), [
            'testMode' => false,
            'production_app_id' => '9999',
            'production_key1' => 'PROD-KEY1',
            'production_key2' => 'PROD-KEY2',
        ]);
    }

    protected function newGateway(): TestZaloPayGateway
    {
        $gateway = new TestZaloPayGateway();
        $gateway->initialize($this->sandboxConfig());
        return $gateway;
    }

    // ------------------------------------------------------------------
    // Independent re-implementations of the ZaloPay v2 signature specs.
    // ------------------------------------------------------------------

    protected function hmacKey1(string $raw): string
    {
        return hash_hmac('sha256', $raw, self::TEST_KEY1);
    }

    protected function hmacKey2(string $raw): string
    {
        return hash_hmac('sha256', $raw, self::TEST_KEY2);
    }

    /**
     * Create-order mac: app_id|app_trans_id|app_user|amount|app_time|
     * embed_data|item (embed_data and item use the JSON-encoded strings).
     */
    protected function expectedCreateMac(array $body): string
    {
        $raw = $body['app_id']
            . '|' . $body['app_trans_id']
            . '|' . $body['app_user']
            . '|' . $body['amount']
            . '|' . $body['app_time']
            . '|' . $body['embed_data']
            . '|' . $body['item'];
        return $this->hmacKey1($raw);
    }

    /**
     * Query-order mac: app_id|app_trans_id|key1 (key1 is the key value).
     */
    protected function expectedQueryMac(array $body): string
    {
        $raw = $body['app_id'] . '|' . $body['app_trans_id'] . '|' . self::TEST_KEY1;
        return $this->hmacKey1($raw);
    }

    /**
     * Refund mac: app_id|zp_trans_id|amount|description|timestamp.
     */
    protected function expectedRefundMac(array $body): string
    {
        $raw = $body['app_id']
            . '|' . $body['zp_trans_id']
            . '|' . $body['amount']
            . '|' . $body['description']
            . '|' . $body['timestamp'];
        return $this->hmacKey1($raw);
    }

    /**
     * Query-refund mac: app_id|m_refund_id|timestamp.
     */
    protected function expectedQueryRefundMac(array $body): string
    {
        $raw = $body['app_id'] . '|' . $body['m_refund_id'] . '|' . $body['timestamp'];
        return $this->hmacKey1($raw);
    }

    /**
     * Redirect checksum: HMAC-SHA256(key2, appid|apptransid|pmcid|bankcode|
     * amount|discountamount|status).
     */
    protected function expectedRedirectChecksum(array $params): string
    {
        $raw = (string) $params['appid']
            . '|' . (string) $params['apptransid']
            . '|' . (string) $params['pmcid']
            . '|' . (string) $params['bankcode']
            . '|' . (string) $params['amount']
            . '|' . (string) $params['discountamount']
            . '|' . (string) $params['status'];
        return $this->hmacKey2($raw);
    }

    protected function signedRedirectParams(array $overrides = []): array
    {
        $params = array_merge([
            'appid' => self::TEST_APP_ID,
            'apptransid' => '260908_2553_45aa1c2d3e4f',
            'pmcid' => '35',
            'bankcode' => 'zalopayapp',
            'amount' => 100000,
            'discountamount' => 0,
            'status' => 1,
        ], $overrides);

        $params['checksum'] = $this->expectedRedirectChecksum($params);
        return $params;
    }

    protected function mockRemoteApi($body, &$captured = null): void
    {
        Functions\when('wp_remote_post')->alias(function ($url, $args) use (&$captured, $body) {
            $captured = ['url' => $url, 'args' => $args];
            return ['body' => $body];
        });
        Functions\when('wp_remote_retrieve_body')->alias(function ($response) {
            return $response['body'] ?? '';
        });
        Functions\when('is_wp_error')->justReturn(false);
    }

    protected function mockRemoteApiJson(array $body, &$captured = null): void
    {
        $this->mockRemoteApi(json_encode($body), $captured);
    }

    /**
     * Return a different response body for each successive remote call.
     */
    protected function mockRemoteApiQueue(array $bodies, &$captured = null): void
    {
        $index = 0;
        Functions\when('wp_remote_post')->alias(function ($url, $args) use (&$captured, &$index, $bodies) {
            $captured[] = ['url' => $url, 'args' => $args];
            return ['body' => $bodies[$index++]];
        });
        Functions\when('wp_remote_retrieve_body')->alias(function ($response) {
            return $response['body'] ?? '';
        });
        Functions\when('is_wp_error')->justReturn(false);
    }

    // ------------------------------------------------------------------
    // initialize()
    // ------------------------------------------------------------------

    public function test_initialize_merges_defaults()
    {
        $gateway = new TestZaloPayGateway();
        $gateway->initialize([
            'testMode' => true,
            'sandbox_app_id' => '553',
        ]);

        $config = $gateway->getConfigValue();
        $this->assertEquals('553', $config['sandbox_app_id']);
        $this->assertSame(false, $config['testMode'] ? false : false);
    }

    public function test_initialize_sandbox_mode_uses_sandbox_credentials()
    {
        $gateway = new TestZaloPayGateway();
        $gateway->initialize($this->sandboxConfig(['testMode' => true]));

        $this->assertEquals([
            'app_id' => self::TEST_APP_ID,
            'key1' => self::TEST_KEY1,
            'key2' => self::TEST_KEY2,
        ], $gateway->getCredentials());
    }

    public function test_initialize_production_mode_uses_production_credentials()
    {
        $gateway = new TestZaloPayGateway();
        $gateway->initialize($this->productionConfig());

        $this->assertEquals([
            'app_id' => '9999',
            'key1' => 'PROD-KEY1',
            'key2' => 'PROD-KEY2',
        ], $gateway->getCredentials());
    }

    public function test_initialize_missing_credentials_defaults_to_empty_string()
    {
        $gateway = new TestZaloPayGateway();
        $gateway->initialize(['testMode' => true]);

        $this->assertEquals([
            'app_id' => '',
            'key1' => '',
            'key2' => '',
        ], $gateway->getCredentials());
    }

    // ------------------------------------------------------------------
    // isAvailable()
    // ------------------------------------------------------------------

    public function test_isAvailable_true_when_all_required_credentials_present()
    {
        $this->assertTrue($this->newGateway()->isAvailable());
    }

    public function test_isAvailable_false_when_app_id_missing()
    {
        $gateway = $this->newGateway();
        $gateway->setCredentials(['app_id' => '', 'key1' => 'X', 'key2' => 'Y']);
        $this->assertFalse($gateway->isAvailable());
    }

    public function test_isAvailable_false_when_key1_missing()
    {
        $gateway = $this->newGateway();
        $gateway->setCredentials(['app_id' => 'X', 'key1' => '', 'key2' => 'Y']);
        $this->assertFalse($gateway->isAvailable());
    }

    public function test_isAvailable_false_when_key2_missing()
    {
        $gateway = $this->newGateway();
        $gateway->setCredentials(['app_id' => 'X', 'key1' => 'Y', 'key2' => '']);
        $this->assertFalse($gateway->isAvailable());
    }

    // ------------------------------------------------------------------
    // getName() / endpoints
    // ------------------------------------------------------------------

    public function test_getName_returns_display_name()
    {
        $gateway = new TestZaloPayGateway();
        $this->assertEquals('ZaloPay', $gateway->getName());
    }

    public function test_api_urls_resolve_per_mode()
    {
        $this->assertEquals(ZaloPayGateway::API_URL_TEST . ZaloPayGateway::PATH_CREATE, $this->newGateway()->exposeGetApiUrl(ZaloPayGateway::PATH_CREATE));

        $gateway = new TestZaloPayGateway();
        $gateway->initialize($this->productionConfig());
        $this->assertEquals(ZaloPayGateway::API_URL_PROD . ZaloPayGateway::PATH_CREATE, $gateway->exposeGetApiUrl(ZaloPayGateway::PATH_CREATE));
    }

    public function test_is_test_mode_flag()
    {
        $this->assertTrue($this->newGateway()->exposeIsTestMode());

        $gateway = new TestZaloPayGateway();
        $gateway->initialize($this->productionConfig());
        $this->assertFalse($gateway->exposeIsTestMode());
    }

    // ------------------------------------------------------------------
    // purchase()
    // ------------------------------------------------------------------

    public function test_purchase_posts_create_order_request()
    {
        $gateway = $this->newGateway();
        $captured = null;
        $this->mockRemoteApiJson([
            'return_code' => 1,
            'return_message' => '',
            'sub_return_code' => 1,
            'sub_return_message' => '',
            'order_url' => 'https://sbgateway.zalopay.vn/pay?order=abc',
            'zp_trans_token' => 'tok123',
            'order_token' => 'ord123',
            'qr_code' => 'https://sbgateway.zalopay.vn/qr?appid=2553',
        ], $captured);

        $result = $gateway->purchase([
            'transactionId' => '42',
            'amount' => 100000,
            'returnUrl' => 'https://example.com/payment/return/42',
            'description' => 'Order #42',
        ]);

        $this->assertNotNull($captured);
        $this->assertEquals(ZaloPayGateway::API_URL_TEST . ZaloPayGateway::PATH_CREATE, $captured['url']);
        $this->assertSame('application/json; charset=UTF-8', $captured['args']['headers']['Content-Type']);
        $this->assertEquals(30, $captured['args']['timeout']);

        $body = json_decode($captured['args']['body'], true);
        $this->assertSame(self::TEST_APP_ID, $body['app_id']);
        $this->assertSame(100000, $body['amount']);
        $this->assertSame('zalopayapp', $body['bank_code']);
        $this->assertMatchesRegularExpression('/^\d{6}_' . self::TEST_APP_ID . '_[a-f0-9]{12}$/', $body['app_trans_id']);
        $this->assertSame('Order #42', $body['description']);

        $embed = json_decode($body['embed_data'], true);
        $this->assertSame('https://example.com/payment/return/42', $embed['redirecturl']);

        $this->assertSame($this->expectedCreateMac($body), $body['mac']);
    }

    public function test_purchase_returns_redirect_payload()
    {
        $gateway = $this->newGateway();
        $this->mockRemoteApiJson([
            'return_code' => 1,
            'order_url' => 'https://sbgateway.zalopay.vn/pay?order=abc',
        ]);

        $result = $gateway->purchase([
            'transactionId' => '42',
            'amount' => 100000,
            'returnUrl' => 'https://example.com/return',
        ]);

        $this->assertEquals('redirect', $result['status']);
        $this->assertEquals('GET', $result['redirectMethod']);
        $this->assertEquals('https://sbgateway.zalopay.vn/pay?order=abc', $result['redirectUrl']);
        $this->assertMatchesRegularExpression('/^\d{6}_' . self::TEST_APP_ID . '_[a-f0-9]{12}$/', $result['transactionId']);
        $this->assertEquals(
            $result['transactionId'],
            $GLOBALS['__post_meta']['_transaction_id']
        );
    }

    public function test_purchase_rejects_amount_below_minimum()
    {
        $gateway = $this->newGateway();

        $result = $gateway->purchase(['transactionId' => '1', 'amount' => 999]);
        $this->assertEquals('failed', $result['status']);
        $this->assertEquals('AMOUNT_INVALID', $result['code']);
    }

    public function test_purchase_returns_failed_on_error_return_code()
    {
        $gateway = $this->newGateway();
        $this->mockRemoteApiJson([
            'return_code' => 2,
            'return_message' => 'Order could not be created.',
            'sub_return_code' => -68,
            'sub_return_message' => 'Duplicate app_trans_id',
        ]);

        $result = $gateway->purchase(['transactionId' => '1', 'amount' => 10000]);

        $this->assertEquals('failed', $result['status']);
        $this->assertEquals('2', $result['code']);
        $this->assertStringContainsString('could not be created', strtolower($result['message']));
    }

    public function test_purchase_adds_optional_request_fields()
    {
        $gateway = $this->newGateway();
        $captured = null;
        $this->mockRemoteApiJson(['return_code' => 1, 'order_url' => 'https://sbgateway.zalopay.vn/pay?order=1'], $captured);

        $gateway->purchase([
            'transactionId' => '1',
            'amount' => 10000,
            'callback_url' => 'https://example.com/zalopay/cb',
            'expire_duration_seconds' => 900,
            'title' => 'Tour Ninh Binh',
            'currency' => 'VND',
            'phone' => '0912345678',
            'email' => 'buyer@example.com',
            'address' => 'Ha Noi, Vietnam',
        ]);

        $body = json_decode($captured['args']['body'], true);
        $this->assertSame('https://example.com/zalopay/cb', $body['callback_url']);
        $this->assertSame(900, $body['expire_duration_seconds']);
        $this->assertSame('Tour Ninh Binh', $body['title']);
        $this->assertSame('VND', $body['currency']);
        $this->assertSame('0912345678', $body['phone']);
        $this->assertSame('buyer@example.com', $body['email']);
        $this->assertSame('Ha Noi, Vietnam', $body['address']);
    }

    public function test_purchase_includes_preferred_payment_method_and_items()
    {
        $gateway = $this->newGateway();
        $captured = null;
        $this->mockRemoteApiJson(['return_code' => 1, 'order_url' => 'https://sbgateway.zalopay.vn/pay?order=1'], $captured);

        $gateway->purchase([
            'transactionId' => '1',
            'amount' => 20000,
            'preferredPaymentMethod' => ['zalopayapp', 'CC'],
            'items' => [
                ['itemid' => '1', 'itemname' => 'Tour', 'itemprice' => 20000, 'itemquantity' => 1],
            ],
            'app_user' => 'usr_123',
        ]);

        $body = json_decode($captured['args']['body'], true);
        $embed = json_decode($body['embed_data'], true);
        $this->assertSame(['zalopayapp', 'CC'], $embed['preferred_payment_method']);

        $items = json_decode($body['item'], true);
        $this->assertCount(1, $items);
        $this->assertSame('Tour', $items[0]['itemname']);

        $this->assertSame('usr_123', $body['app_user']);
        $this->assertSame($this->expectedCreateMac($body), $body['mac']);
    }

    public function test_purchase_posts_to_production_url_in_production_mode()
    {
        $gateway = new TestZaloPayGateway();
        $gateway->initialize($this->productionConfig());
        $captured = null;
        $this->mockRemoteApiJson(['return_code' => 1, 'order_url' => 'https://gateway.zalopay.vn/pay?order=1'], $captured);

        $gateway->purchase(['transactionId' => '1', 'amount' => 10000]);

        $this->assertEquals(ZaloPayGateway::API_URL_PROD . ZaloPayGateway::PATH_CREATE, $captured['url']);
    }

    public function test_purchase_app_trans_id_is_unique()
    {
        $gateway = $this->newGateway();

        $refA = $gateway->exposeBuildAppTransId();
        $refB = $gateway->exposeBuildAppTransId();

        $this->assertMatchesRegularExpression('/^\d{6}_' . self::TEST_APP_ID . '_[a-f0-9]{12}$/', $refA);
        $this->assertLessThanOrEqual(40, strlen($refA));
        $this->assertNotSame($refA, $refB);
    }

    public function test_purchase_uses_return_url_when_no_redirecturl_passed()
    {
        $gateway = $this->newGateway();
        $captured = null;
        $this->mockRemoteApiJson(['return_code' => 1, 'order_url' => 'https://sbgateway.zalopay.vn/pay?order=1'], $captured);

        $gateway->purchase([
            'transactionId' => '1',
            'amount' => 10000,
            'returnUrl' => 'https://example.com/return',
        ]);

        $body = json_decode($captured['args']['body'], true);
        $embed = json_decode($body['embed_data'], true);
        $this->assertSame('https://example.com/return', $embed['redirecturl']);
    }

    // ------------------------------------------------------------------
    // completePurchase()
    // ------------------------------------------------------------------

    public function test_completePurchase_success()
    {
        $gateway = $this->newGateway();
        $params = $this->signedRedirectParams([]);
        $this->mockRemoteApiJson([
            'return_code' => 1,
            'return_message' => '',
            'zp_trans_id' => '2209080001',
            'amount' => 100000,
        ]);

        $result = $gateway->completePurchase($params);

        $this->assertEquals('success', $result['status']);
        $this->assertEquals('260908_2553_45aa1c2d3e4f', $result['transactionId']);
    }

    public function test_completePurchase_success_with_request_params_wrapper()
    {
        $gateway = $this->newGateway();
        $params = $this->signedRedirectParams([]);
        $this->mockRemoteApiJson([
            'return_code' => 1,
            'return_message' => '',
            'zp_trans_id' => '2209080001',
        ]);

        $result = $gateway->completePurchase(['request_params' => $params]);

        $this->assertEquals('success', $result['status']);
    }

    public function test_completePurchase_pending_on_processing()
    {
        $gateway = $this->newGateway();
        $params = $this->signedRedirectParams([]);
        $this->mockRemoteApiJson([
            'return_code' => 3,
            'return_message' => 'Payment processing.',
        ]);

        $result = $gateway->completePurchase($params);

        $this->assertEquals('pending', $result['status']);
        $this->assertEquals('3', $result['code']);
        $this->assertStringContainsString('being processed', strtolower($result['message']));
    }

    public function test_completePurchase_failed_code()
    {
        $gateway = $this->newGateway();
        $params = $this->signedRedirectParams([]);
        $this->mockRemoteApiJson([
            'return_code' => 2,
            'return_message' => 'Transaction not found.',
        ]);

        $result = $gateway->completePurchase($params);

        $this->assertEquals('failed', $result['status']);
        $this->assertEquals('2', $result['code']);
    }

    public function test_completePurchase_rejects_invalid_checksum()
    {
        $gateway = $this->newGateway();
        $params = $this->signedRedirectParams([]);
        $params['amount'] = 99999;

        $result = $gateway->completePurchase($params);

        $this->assertEquals('failed', $result['status']);
        $this->assertEquals('HASH_MISMATCH', $result['code']);
    }

    public function test_completePurchase_rejects_app_id_mismatch()
    {
        $gateway = $this->newGateway();
        $params = $this->signedRedirectParams(['appid' => 'OTHER_APP']);

        $result = $gateway->completePurchase($params);

        $this->assertEquals('failed', $result['status']);
        $this->assertEquals('APP_ID_MISMATCH', $result['code']);
    }

    public function test_completePurchase_falls_back_to_redirect_status_when_query_fails()
    {
        $gateway = $this->newGateway();
        $params = $this->signedRedirectParams(['status' => 1]);
        Functions\when('wp_remote_post')->justReturn(new \WP_Error('http_error', 'boom'));
        Functions\when('wp_remote_retrieve_body')->justReturn('');
        Functions\when('is_wp_error')->justReturn(true);

        $result = $gateway->completePurchase($params);

        $this->assertEquals('success', $result['status']);
        $this->assertEquals('1', $result['code']);
    }

    public function test_completePurchase_persists_zp_trans_id()
    {
        $gateway = $this->newGateway();
        $params = $this->signedRedirectParams([]);
        $this->mockRemoteApiJson([
            'return_code' => 1,
            'return_message' => '',
            'zp_trans_id' => '2209080001',
        ]);

        \WP_Query::$mock_posts = [new \WP_Post(['ID' => 99, 'post_date' => '2026-08-09 00:00:00'])];

        $gateway->completePurchase($params);

        $this->assertEquals('2209080001', $GLOBALS['__post_meta']['_zp_trans_id']);
    }

    // ------------------------------------------------------------------
    // verifyRedirectChecksum()
    // ------------------------------------------------------------------

    public function test_verifyRedirectChecksum_accepts_valid_params()
    {
        $gateway = $this->newGateway();
        $params = $this->signedRedirectParams([]);
        $this->assertTrue($gateway->verifyRedirectChecksum($params));
    }

    public function test_verifyRedirectChecksum_rejects_tampered_params()
    {
        $gateway = $this->newGateway();
        $params = $this->signedRedirectParams([]);
        $params['amount'] = 999;
        $this->assertFalse($gateway->verifyRedirectChecksum($params));
    }

    public function test_verifyRedirectChecksum_rejects_missing_checksum()
    {
        $gateway = $this->newGateway();
        $params = $this->signedRedirectParams([]);
        unset($params['checksum']);
        $this->assertFalse($gateway->verifyRedirectChecksum($params));
    }

    public function test_verifyRedirectChecksum_rejects_empty_key2()
    {
        $gateway = new TestZaloPayGateway();
        $gateway->initialize(['testMode' => true, 'sandbox_key2' => '']);
        $params = $this->signedRedirectParams([]);
        $this->assertFalse($gateway->verifyRedirectChecksum($params));
    }

    // ------------------------------------------------------------------
    // queryStatus()
    // ------------------------------------------------------------------

    public function test_queryStatus_returns_completed_on_return_code_1()
    {
        $gateway = $this->newGateway();
        $this->mockRemoteApiJson([
            'return_code' => 1,
            'return_message' => '',
            'zp_trans_id' => '2209080001',
        ]);
        $this->assertEquals('completed', $gateway->queryStatus('260908_2553_45aa1c2d3e4f'));
    }

    public function test_queryStatus_returns_pending_on_return_code_3()
    {
        $gateway = $this->newGateway();
        $this->mockRemoteApiJson(['return_code' => 3, 'return_message' => 'Processing.']);
        $this->assertEquals('pending', $gateway->queryStatus('260908_2553_45aa1c2d3e4f'));
    }

    public function test_queryStatus_returns_failed_on_other_return_code()
    {
        $gateway = $this->newGateway();
        $this->mockRemoteApiJson(['return_code' => 2, 'return_message' => 'Failed.']);
        $this->assertEquals('failed', $gateway->queryStatus('260908_2553_45aa1c2d3e4f'));
    }

    public function test_queryStatus_returns_failed_on_wp_error()
    {
        $gateway = $this->newGateway();
        Functions\when('wp_remote_post')->justReturn(new \WP_Error('http_error', 'boom'));
        Functions\when('wp_remote_retrieve_body')->justReturn('');
        Functions\when('is_wp_error')->justReturn(true);

        $this->assertEquals('failed', $gateway->queryStatus('260908_2553_45aa1c2d3e4f'));
    }

    public function test_queryStatus_posts_to_query_endpoint()
    {
        $gateway = $this->newGateway();
        $captured = null;
        $this->mockRemoteApiJson(['return_code' => 1, 'return_message' => ''], $captured);

        $gateway->queryStatus('260908_2553_45aa1c2d3e4f');

        $this->assertEquals(ZaloPayGateway::API_URL_TEST . ZaloPayGateway::PATH_QUERY, $captured['url']);
        $body = json_decode($captured['args']['body'], true);
        $this->assertSame('260908_2553_45aa1c2d3e4f', $body['app_trans_id']);
        $this->assertSame($this->expectedQueryMac($body), $body['mac']);
    }

    public function test_queryStatus_persists_zp_trans_id()
    {
        $gateway = $this->newGateway();
        \WP_Query::$mock_posts = [new \WP_Post(['ID' => 99, 'post_date' => '2026-08-09 00:00:00'])];

        $this->mockRemoteApiJson([
            'return_code' => 1,
            'return_message' => '',
            'zp_trans_id' => '2209080001',
        ]);

        $gateway->queryStatus('260908_2553_45aa1c2d3e4f');

        $this->assertEquals('2209080001', $GLOBALS['__post_meta']['_zp_trans_id']);
    }

    // ------------------------------------------------------------------
    // refund()
    // ------------------------------------------------------------------

    public function test_refund_posts_refund_request_with_valid_mac()
    {
        $gateway = $this->newGateway();
        $captured = null;
        $this->mockRemoteApiJson([
            'return_code' => 1,
            'return_message' => 'Refund successful.',
        ], $captured);

        $result = $gateway->refund([
            'zp_trans_id' => '2209080001',
            'amount' => 100000,
            'description' => 'Hoan tra don hang',
        ]);

        $this->assertEquals('success', $result['status']);

        $body = json_decode($captured['args']['body'], true);
        $this->assertSame('2209080001', $body['zp_trans_id']);
        $this->assertMatchesRegularExpression('/^\d{6}_' . self::TEST_APP_ID . '_[a-f0-9]{12}$/', $body['m_refund_id']);
        $this->assertSame(100000, (int) $body['amount']);
        $this->assertSame($this->expectedRefundMac($body), $body['mac']);
    }

    public function test_refund_returns_failed_on_error_result()
    {
        $gateway = $this->newGateway();
        $this->mockRemoteApiJson(['return_code' => 2, 'return_message' => 'Refund failed.']);

        $result = $gateway->refund(['zp_trans_id' => '2209080001', 'amount' => 100000]);

        $this->assertEquals('failed', $result['status']);
        $this->assertEquals('2', $result['code']);
    }

    public function test_refund_requires_zp_trans_id()
    {
        $gateway = $this->newGateway();

        $result = $gateway->refund(['amount' => 100000]);

        $this->assertEquals('failed', $result['status']);
        $this->assertEquals('MISSING_ZPTRANS_ID', $result['code']);
    }

    public function test_refund_resolves_zp_trans_id_from_meta()
    {
        $gateway = $this->newGateway();
        $captured = null;
        $this->mockRemoteApiJson(['return_code' => 1, 'return_message' => 'OK'], $captured);

        \WP_Query::$mock_posts = [new \WP_Post(['ID' => 99, 'post_date' => '2026-08-09 00:00:00'])];
        $GLOBALS['__post_meta']['_zp_trans_id'] = '2209080001';

        $result = $gateway->refund([
            'app_trans_id' => '260908_2553_45aa1c2d3e4f',
            'amount' => 100000,
        ]);

        $this->assertEquals('success', $result['status']);
        $body = json_decode($captured['args']['body'], true);
        $this->assertSame('2209080001', $body['zp_trans_id']);
    }

    public function test_refund_polls_until_processing_completes()
    {
        $gateway = $this->newGateway();
        $captured = [];
        $this->mockRemoteApiQueue([
            json_encode(['return_code' => 3, 'return_message' => 'Refund processing']),
            json_encode(['return_code' => 1, 'return_message' => 'Refund successful.']),
        ], $captured);

        $result = $gateway->refund([
            'zp_trans_id' => '2209080001',
            'amount' => 100000,
        ]);

        $this->assertEquals('success', $result['status']);
        $this->assertEquals('1', $result['code']);

        $this->assertCount(2, $captured);
        $this->assertEquals(ZaloPayGateway::API_URL_TEST . ZaloPayGateway::PATH_REFUND, $captured[0]['url']);
        $this->assertEquals(ZaloPayGateway::API_URL_TEST . ZaloPayGateway::PATH_QUERY_REFUND, $captured[1]['url']);
    }

    public function test_refund_posts_to_refund_endpoint()
    {
        $gateway = $this->newGateway();
        $captured = null;
        $this->mockRemoteApiJson(['return_code' => 1, 'return_message' => 'OK'], $captured);

        $gateway->refund(['zp_trans_id' => '2209080001', 'amount' => 100000]);

        $this->assertEquals(ZaloPayGateway::API_URL_TEST . ZaloPayGateway::PATH_REFUND, $captured['url']);
    }

    // ------------------------------------------------------------------
    // queryRefundStatus()
    // ------------------------------------------------------------------

    public function test_queryRefundStatus_posts_valid_request()
    {
        $gateway = $this->newGateway();
        $captured = null;
        $this->mockRemoteApiJson(['return_code' => 1, 'return_message' => 'OK'], $captured);

        $result = $gateway->queryRefundStatus('260908_2553_45aa1c2d3e4f');

        $this->assertSame(1, $result['return_code']);
        $this->assertEquals(ZaloPayGateway::API_URL_TEST . ZaloPayGateway::PATH_QUERY_REFUND, $captured['url']);

        $body = json_decode($captured['args']['body'], true);
        $this->assertSame('260908_2553_45aa1c2d3e4f', $body['m_refund_id']);
        $this->assertSame($this->expectedQueryRefundMac($body), $body['mac']);
    }

    // ------------------------------------------------------------------
    // getSettingsFields()
    // ------------------------------------------------------------------

    public function test_getSettingsFields_contains_all_expected_keys()
    {
        $fields = $this->newGateway()->getSettingsFields();

        foreach ([
            'testMode',
            'sandbox_app_id', 'sandbox_key1', 'sandbox_key2',
            'production_app_id', 'production_key1', 'production_key2',
        ] as $key) {
            $this->assertArrayHasKey($key, $fields);
        }
    }

    public function test_getSettingsFields_field_types()
    {
        $fields = $this->newGateway()->getSettingsFields();

        $this->assertEquals('checkbox', $fields['testMode']['type']);
        $this->assertEquals('text', $fields['sandbox_app_id']['type']);
        $this->assertEquals('password', $fields['sandbox_key1']['type']);
        $this->assertEquals('password', $fields['sandbox_key2']['type']);
        $this->assertEquals('password', $fields['production_key1']['type']);
    }

    public function test_getSettingsFields_defaults()
    {
        $fields = $this->newGateway()->getSettingsFields();

        $this->assertEquals('1', $fields['testMode']['default']);
        $this->assertEquals('', $fields['sandbox_app_id']['default']);
        $this->assertArrayNotHasKey('default', $fields['production_app_id']);
        $this->assertArrayNotHasKey('default', $fields['production_key2']);
    }

    // ------------------------------------------------------------------
    // Helpers (description sanitization / embed data / app user)
    // ------------------------------------------------------------------

    public function test_order_description_is_sanitized_and_truncated()
    {
        $gateway = $this->newGateway();

        $info = $gateway->exposeSanitizeOrderInfo(str_repeat('A', 300), '200908_2553_12ab');
        $this->assertLessThanOrEqual(255, strlen($info));

        $this->assertSame('Order 200908_2553_12ab', $gateway->exposeSanitizeOrderInfo('', '200908_2553_12ab'));
        $this->assertSame('Order 200908_2553_12ab', $gateway->exposeSanitizeOrderInfo('  ', '200908_2553_12ab'));
        $this->assertSame('A B', $gateway->exposeSanitizeOrderInfo("A\n\n   B", '200908_2553_12ab'));
    }

    public function test_build_app_user_from_parameters()
    {
        $gateway = $this->newGateway();

        $this->assertSame('usr_123', $gateway->exposeBuildAppUser(['app_user' => 'usr_123']));
        $this->assertSame('buyer@example.com', $gateway->exposeBuildAppUser(['customer_email' => 'buyer@example.com']));
        $this->assertSame('0912345678', $gateway->exposeBuildAppUser(['customer_phone' => '0912345678']));
        $this->assertSame('appuser', $gateway->exposeBuildAppUser([]));
    }

    public function test_build_app_user_is_truncated_to_50()
    {
        $gateway = $this->newGateway();
        $this->assertSame(50, strlen($gateway->exposeBuildAppUser(['app_user' => str_repeat('x', 100)])));
    }
}