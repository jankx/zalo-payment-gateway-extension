<?php
namespace Jankx\Extensions\Zalopay\Tests\Gateways;

use PHPUnit\Framework\TestCase;
use Brain\Monkey;
use Brain\Monkey\Functions;
use Jankx\Extensions\Zalopay\Gateways\ZaloPayGateway;
use Jankx\Extensions\PaymentSystem\Gateways\AbstractGateway;
use Jankx\Extensions\PaymentSystem\Gateways\GatewayManager;

class DisplayTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
        zalopay_test_stub_wp_functions();

        // Real registry-backed filters so per-gateway filter tests are meaningful.
        $GLOBALS['__filters'] = [];
        Functions\when('add_filter')->alias(function ($tag, $callback) {
            $GLOBALS['__filters'][$tag][] = $callback;
            return true;
        });
        Functions\when('apply_filters')->alias(function ($tag, $value, ...$args) {
            foreach ($GLOBALS['__filters'][$tag] ?? [] as $callback) {
                $value = $callback($value, ...$args);
            }
            return $value;
        });
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['__filters']);
        Monkey\tearDown();
        parent::tearDown();
    }

    public function test_zalopay_display_defaults_to_icon()
    {
        $display = (new ZaloPayGateway())->getDisplay();

        $this->assertSame(AbstractGateway::SHOW_ICON_TEXT, $display['type']);
        $this->assertStringContainsString('<svg', $display['icon']);
        $this->assertStringContainsString('0068FF', $display['icon']);
        $this->assertSame('ZaloPay', $display['text']);
        $this->assertSame(AbstractGateway::ICON_LEFT, $display['icon_position']);
    }

    public function test_icon_filter_is_scoped_to_zalopay_slug()
    {
        add_filter('jankx/payment/gateway/zalopay/icon', function () {
            return '<svg>custom-zalopay</svg>';
        });

        $display = (new ZaloPayGateway())->getDisplay();

        $this->assertSame('<svg>custom-zalopay</svg>', $display['icon']);
    }

    public function test_text_filter_changes_label()
    {
        add_filter('jankx/payment/gateway/zalopay/text', function () {
            return 'Ví ZaloPay';
        });

        $display = (new ZaloPayGateway())->getDisplay();

        $this->assertSame('Ví ZaloPay', $display['text']);
    }

    public function test_display_falls_back_to_text_when_icon_filtered_to_empty()
    {
        add_filter('jankx/payment/gateway/zalopay/icon', function () {
            return '';
        });

        $display = (new ZaloPayGateway())->getDisplay();

        $this->assertSame(AbstractGateway::SHOW_TEXT, $display['type']);
        $this->assertSame('', $display['icon']);
        $this->assertSame('ZaloPay', $display['text']);
    }

    public function test_display_type_and_position_filters_apply()
    {
        add_filter('jankx/payment/gateway/zalopay/display_type', function () {
            return AbstractGateway::SHOW_ICON;
        });
        add_filter('jankx/payment/gateway/zalopay/icon_position', function () {
            return AbstractGateway::ICON_RIGHT;
        });

        $display = (new ZaloPayGateway())->getDisplay();

        $this->assertSame(AbstractGateway::SHOW_ICON, $display['type']);
        $this->assertSame(AbstractGateway::ICON_RIGHT, $display['icon_position']);
    }

    public function test_manager_assigns_registered_slug_to_gateway()
    {
        $ref = new \ReflectionProperty(GatewayManager::class, 'instance');
        $ref->setAccessible(true);
        $ref->setValue(null, null);

        $manager = GatewayManager::getInstance();
        $manager->register('zalopay', ZaloPayGateway::class);

        $gateway = $manager->get('zalopay');

        $this->assertInstanceOf(ZaloPayGateway::class, $gateway);
        $this->assertSame('zalopay', $gateway->getSlug());
    }
}