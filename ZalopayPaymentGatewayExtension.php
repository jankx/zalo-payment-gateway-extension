<?php
namespace Jankx\Extensions\Zalopay;

use Jankx\Extensions\AbstractExtension;
use Jankx\Extensions\Zalopay\Gateways\ZaloPayGateway;
use Jankx\Extensions\PaymentSystem\Gateways\GatewayManager;

/**
 * ZaloPay Payment Gateway Extension.
 *
 * Registers the `zalopay` gateway channel with the payment-system extension:
 *
 *  1. purchase()          -> creates a ZaloPay order and redirects the
 *                            customer to `order_url`
 *  2. completePurchase()  -> verifies the redirect checksum (key2) and
 *                            reconciles the result with the query API
 *  3. queryStatus()       -> ZaloPay query order API (status reconciliation)
 *  4. refund()            -> ZaloPay refund API (+ query_refund poll)
 *
 * @package Jankx\Extensions\Zalopay
 */
class ZalopayPaymentGatewayExtension extends AbstractExtension
{
    protected static $instance;

    public function __construct()
    {
        $this->register_autoloader();
        parent::__construct();
    }

    protected function register_autoloader()
    {
        spl_autoload_register(function ($class) {
            $prefix = 'Jankx\\Extensions\\Zalopay\\';
            $base_dir = __DIR__ . '/src/';

            $len = strlen($prefix);
            if (strncmp($prefix, $class, $len) !== 0) {
                return;
            }

            $relative_class = substr($class, $len);
            $file = $base_dir . str_replace('\\', '/', $relative_class) . '.php';

            if (file_exists($file)) {
                require $file;
            }
        });
    }

    public function init(): void
    {
        self::$instance = $this;
    }

    public static function get_instance(): ?self
    {
        return self::$instance;
    }

    public function register_hooks(): void
    {
        add_action('jankx/payment/register_gateways', [$this, 'registerGateways']);

        add_filter('jankx/payment/gateway/zalopay/default_config', [$this, 'defaultConfig']);

        add_action('admin_init', [$this, 'registerGatewaySettings']);
    }

    public function registerGatewaySettings(): void
    {
        register_setting('jankx_payment', 'jankx_payment_gateway_zalopay', [
            'type'              => 'array',
            'sanitize_callback' => [$this, 'sanitizeGatewaySettings'],
        ]);
    }

    public function sanitizeGatewaySettings($value): array
    {
        $value = is_array($value) ? $value : [];
        foreach ($value as $key => $item) {
            $value[$key] = is_string($item) ? sanitize_text_field($item) : $item;
        }
        return $value;
    }

    public function registerGateways(): void
    {
        GatewayManager::getInstance()->register('zalopay', ZaloPayGateway::class);
    }

    public function defaultConfig(array $config): array
    {
        return array_merge($config, [
            'testMode'          => '1',
            'sandbox_app_id'    => '',
            'sandbox_key1'      => '',
            'sandbox_key2'      => '',
        ]);
    }
}