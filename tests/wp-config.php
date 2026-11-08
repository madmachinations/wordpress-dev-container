<?php
define('DB_NAME', getenv('DB_NAME'));
define('DB_USER', getenv('DB_USER'));
define('DB_PASSWORD', getenv('DB_PASS'));
define('DB_HOST', getenv('DB_HOST') . ':' . getenv('DB_PORT'));
define('DB_CHARSET', 'utf8mb4');
define('DB_COLLATE', '');
$table_prefix = 'wp_';
define('WP_ENVIRONMENT_TYPE', 'local');
define('WP_DEBUG', true);
define('WP_DEBUG_DISPLAY', false);
define('WP_DEBUG_LOG', true);
define('AUTH_KEY', 'integration-test-only');
define('SECURE_AUTH_KEY', 'integration-test-only');
define('LOGGED_IN_KEY', 'integration-test-only');
define('NONCE_KEY', 'integration-test-only');
define('AUTH_SALT', 'integration-test-only');
define('SECURE_AUTH_SALT', 'integration-test-only');
define('LOGGED_IN_SALT', 'integration-test-only');
define('NONCE_SALT', 'integration-test-only');
if (!defined('ABSPATH')) {
    define('ABSPATH', __DIR__ . '/');
}
require_once ABSPATH . 'wp-settings.php';
