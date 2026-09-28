<?php
/**
 * ZaloPay Payment Gateway Extension - PHPUnit bootstrap.
 *
 * Loads:
 *  1. The Composer autoloader (dev deps: phpunit, brain/monkey, mockery).
 *  2. A PSR-4 fallback autoloader for the extension src.
 *  3. The real payment-system classes (GatewayInterface, GatewayManager,
 *     Transaction) so the gateway is tested against its real contracts.
 *  4. Minimal framework classes (AbstractExtension / ExtensionInterface).
 *  5. WordPress class stubs (WP_Post, WP_Query, WP_Error, WP_REST_*).
 */

use Brain\Monkey;

if (!defined('JANKX_ZALOPAY_TEST_DIR')) {
    define('JANKX_ZALOPAY_TEST_DIR', __DIR__);
}

// 1. Composer autoloader (dev dependencies).
$composerAutoload = __DIR__ . '/../libs/autoload.php';
if (file_exists($composerAutoload)) {
    require_once $composerAutoload;
}

// 2. PSR-4 fallback autoloader for this extension and its dependencies.
spl_autoload_register(function ($class) {
    // The extension entry class lives at the extension root, not in src/.
    if ($class === 'Jankx\\Extensions\\Zalopay\\ZalopayPaymentGatewayExtension') {
        $entry = __DIR__ . '/../ZalopayPaymentGatewayExtension.php';
        if (file_exists($entry)) {
            require_once $entry;
        }
        return;
    }

    $prefixes = [
        'Jankx\\Extensions\\Zalopay\\' => __DIR__ . '/../src/',
        'Jankx\\Extensions\\PaymentSystem\\' => __DIR__ . '/../../payment-system/src/',
    ];

    foreach ($prefixes as $prefix => $baseDir) {
        $len = strlen($prefix);
        if (strncmp($prefix, $class, $len) !== 0) {
            continue;
        }

        $file = $baseDir . str_replace('\\', '/', substr($class, $len)) . '.php';
        if (file_exists($file)) {
            require_once $file;
            return;
        }
    }
});

// 3. Framework classes used by ZalopayPaymentGatewayExtension.
$frameworkDir = __DIR__ . '/../../../../jankx/includes/framework';
if (file_exists($frameworkDir . '/Contracts/Extension/ExtensionInterface.php')) {
    require_once $frameworkDir . '/Contracts/Extension/ExtensionInterface.php';
}
if (file_exists($frameworkDir . '/Extensions/AbstractExtension.php')) {
    require_once $frameworkDir . '/Extensions/AbstractExtension.php';
}

// 4. WordPress class stubs.
if (!class_exists('WP_Post')) {
    class WP_Post
    {
        public $ID;
        public $post_type;
        public $post_title;
        public $post_status;
        public $post_date;
        public $post_name;
        public $post_content;

        public function __construct($data = [])
        {
            foreach ($data as $key => $value) {
                $this->$key = $value;
            }
        }
    }
}

if (!class_exists('WP_Query')) {
    class WP_Query
    {
        public static $mock_posts = [];

        public $posts = [];
        public $found_posts = 0;
        public $post_count = 0;

        public function __construct($args = [])
        {
            $this->posts = self::$mock_posts;
            $this->found_posts = count($this->posts);
            $this->post_count = $this->found_posts;
        }

        public function have_posts()
        {
            return count($this->posts) > 0;
        }

        public function the_post()
        {
        }
    }
}

if (!class_exists('WP_Error')) {
    class WP_Error
    {
        private $errors = [];

        public function __construct($code = '', $message = '', $data = '')
        {
            if ($code) {
                $this->errors[$code] = [$message];
            }
        }

        public function get_error_message()
        {
            return reset($this->errors)[0] ?? '';
        }
    }
}

if (!class_exists('WP_REST_Request')) {
    class WP_REST_Request
    {
        protected $params = [];

        public function __construct($method = 'GET', $route = '')
        {
        }

        public function set_param($key, $value)
        {
            $this->params[$key] = $value;
        }

        public function get_param($key)
        {
            return $this->params[$key] ?? null;
        }

        public function get_params()
        {
            return $this->params;
        }
    }
}

if (!class_exists('WP_REST_Response')) {
    class WP_REST_Response
    {
        protected $data;
        protected $status;
        protected $headers;

        public function __construct($data = [], $status = 200, $headers = [])
        {
            $this->data = $data;
            $this->status = $status;
            $this->headers = $headers;
        }

        public function get_data()
        {
            return $this->data;
        }

        public function get_status()
        {
            return $this->status;
        }

        public function get_headers()
        {
            return $this->headers;
        }
    }
}

// 5. Helper: WP functions used across tests.
if (!function_exists('zalopay_test_stub_wp_functions')) {
    function zalopay_test_stub_wp_functions()
    {
        Brain\Monkey\Functions\when('__')->returnArg();
        Brain\Monkey\Functions\when('add_action')->justReturn(true);
        Brain\Monkey\Functions\when('add_filter')->justReturn(true);
        Brain\Monkey\Functions\when('apply_filters')->alias(function ($tag, $value) {
            return $value;
        });
        Brain\Monkey\Functions\when('do_action')->justReturn(null);
        Brain\Monkey\Functions\when('get_option')->alias(function ($key, $default = false) {
            return $GLOBALS['__options'][$key] ?? $default;
        });
        Brain\Monkey\Functions\when('update_option')->justReturn(true);
        Brain\Monkey\Functions\when('get_post')->alias(function ($id = null) {
            return new \WP_Post(['ID' => (int) $id, 'post_date' => '2026-08-09 00:00:00']);
        });
        Brain\Monkey\Functions\when('get_post_meta')->alias(function ($id, $key, $single = false) {
            return $GLOBALS['__post_meta'][$key] ?? ($single ? '' : []);
        });
        Brain\Monkey\Functions\when('update_post_meta')->alias(function ($id, $key, $value) {
            $GLOBALS['__post_meta'][$key] = $value;
            return true;
        });
        Brain\Monkey\Functions\when('home_url')->justReturn('https://example.com');
        Brain\Monkey\Functions\when('register_rest_route')->justReturn(true);
        Brain\Monkey\Functions\when('register_setting')->justReturn(true);
        Brain\Monkey\Functions\when('sanitize_email')->alias(function ($email) {
            return (string) $email;
        });
        Brain\Monkey\Functions\when('sanitize_text_field')->alias(function ($value) {
            return trim((string) $value);
        });
        Brain\Monkey\Functions\when('wp_parse_args')->alias(function ($args, $defaults = []) {
            $args = is_array($args) ? $args : [];
            return array_merge($defaults, $args);
        });

        $GLOBALS['__options'] = [];
        $GLOBALS['__post_meta'] = [];
        \WP_Query::$mock_posts = [];
    }
}