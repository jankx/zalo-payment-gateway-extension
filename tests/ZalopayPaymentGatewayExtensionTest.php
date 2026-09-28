<?php
namespace Jankx\Extensions\Zalopay\Tests;

use PHPUnit\Framework\TestCase;
use Brain\Monkey;
use Brain\Monkey\Functions;
use Jankx\Extensions\Zalopay\ZalopayPaymentGatewayExtension;
use Jankx\Extensions\Zalopay\Gateways\ZaloPayGateway;
use Jankx\Extensions\PaymentSystem\Gateways\GatewayManager;

class ZalopayPaymentGatewayExtensionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
        zalopay_test_stub_wp_functions();

        $ref = new \ReflectionProperty(GatewayManager::class, 'instance');
        $ref->setAccessible(true);
        $ref->setValue(null, null);
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['__options'], $GLOBALS['__post_meta']);
        Monkey\tearDown();
        parent::tearDown();
    }

    public function test_get_instance_returns_singleton()
    {
        $instance = new ZalopayPaymentGatewayExtension();
        $this->assertSame($instance, ZalopayPaymentGatewayExtension::get_instance());
    }

    public function test_register_hooks_wires_all_hooks()
    {
        $extension = new ZalopayPaymentGatewayExtension();

        $actions = [];
        $filters = [];
        Functions\when('add_action')->alias(function ($tag, $callback) use (&$actions) {
            $actions[] = ['tag' => $tag, 'callback' => $callback];
            return true;
        });
        Functions\when('add_filter')->alias(function ($tag, $callback) use (&$filters) {
            $filters[] = ['tag' => $tag, 'callback' => $callback];
            return true;
        });

        $extension->register_hooks();

        $this->assertContains(
            ['tag' => 'jankx/payment/register_gateways', 'callback' => [$extension, 'registerGateways']],
            $actions
        );
        $this->assertContains(
            ['tag' => 'admin_init', 'callback' => [$extension, 'registerGatewaySettings']],
            $actions
        );
        $this->assertContains(
            ['tag' => 'jankx/payment/gateway/zalopay/default_config', 'callback' => [$extension, 'defaultConfig']],
            $filters
        );
    }

    public function test_registerGateways_registers_zalopay_gateway()
    {
        GatewayManager::getInstance();
        $extension = new ZalopayPaymentGatewayExtension();
        $extension->registerGateways();

        $manager = GatewayManager::getInstance();
        $this->assertTrue($manager->hasGateway('zalopay'));
    }

    public function test_registerGateways_registers_correct_class()
    {
        GatewayManager::getInstance();
        $extension = new ZalopayPaymentGatewayExtension();
        $extension->registerGateways();

        $gateways = GatewayManager::getInstance()->getAll();
        $this->assertSame(ZaloPayGateway::class, $gateways['zalopay']);
    }

    public function test_default_config()
    {
        $extension = new ZalopayPaymentGatewayExtension();
        $config = $extension->defaultConfig(['testMode' => false]);

        $this->assertEquals('1', $config['testMode']);
        $this->assertEquals('', $config['sandbox_app_id']);
        $this->assertEquals('', $config['sandbox_key1']);
        $this->assertEquals('', $config['sandbox_key2']);
    }

    public function test_default_config_preserves_custom_keys()
    {
        $extension = new ZalopayPaymentGatewayExtension();
        $config = $extension->defaultConfig([
            'testMode' => false,
            'custom_key' => 'custom-value',
        ]);

        $this->assertEquals('custom-value', $config['custom_key']);
    }

    public function test_sanitize_gateway_settings_strips_text_fields()
    {
        $extension = new ZalopayPaymentGatewayExtension();
        $sanitized = $extension->sanitizeGatewaySettings([
            'sandbox_app_id' => "  2553  ",
            'sandbox_key1' => "  KEY1-X \n",
        ]);

        $this->assertEquals('2553', $sanitized['sandbox_app_id']);
        $this->assertEquals('KEY1-X', $sanitized['sandbox_key1']);
    }

    public function test_sanitize_gateway_settings_handles_non_array()
    {
        $extension = new ZalopayPaymentGatewayExtension();
        $this->assertEquals([], $extension->sanitizeGatewaySettings('not-an-array'));
    }

    public function test_sanitize_gateway_settings_keeps_non_string_values()
    {
        $extension = new ZalopayPaymentGatewayExtension();
        $sanitized = $extension->sanitizeGatewaySettings(['testMode' => 1, 'enabled' => true]);
        $this->assertSame(1, $sanitized['testMode']);
        $this->assertTrue($sanitized['enabled']);
    }

    public function test_register_gateway_settings_registers_zalopay_option()
    {
        $extension = new ZalopayPaymentGatewayExtension();

        $captured = [];
        Functions\when('register_setting')->alias(function ($group, $name, $args) use (&$captured) {
            $captured[] = ['group' => $group, 'name' => $name, 'args' => $args];
            return true;
        });

        $extension->registerGatewaySettings();

        $this->assertCount(1, $captured);
        $this->assertEquals('jankx_payment', $captured[0]['group']);
        $this->assertEquals('jankx_payment_gateway_zalopay', $captured[0]['name']);
        $this->assertEquals('array', $captured[0]['args']['type']);
        $this->assertSame([$extension, 'sanitizeGatewaySettings'], $captured[0]['args']['sanitize_callback']);
    }
}