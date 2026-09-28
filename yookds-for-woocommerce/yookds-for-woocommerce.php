<?php
/**
 * Plugin Name: YooKDS for WooCommerce
 * Plugin URI: https://github.com/adamchaadaev/YooKDS-by-Kassa-Express
 * Description: Testversion: gemensam köksvy, historik och WooCommerce-baserat orderflöde.
 * Author: Ninja Nuts AB / Kassa Express
 * Version: 1.1.0-alpha.2
 * Requires at least: 6.5
 * Requires PHP: 8.0
 * Requires Plugins: woocommerce
 * WC requires at least: 8.2
 * License: GPL-2.0-or-later
 * Text Domain: yookds
 */
if (!defined('ABSPATH')) { exit; }
if (defined('YOOKDS_PLUGIN_FILE')) { return; }
define('YOOKDS_VERSION', '1.1.0-alpha.2');
define('YOOKDS_PLUGIN_FILE', __FILE__);
define('YOOKDS_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('YOOKDS_PLUGIN_URL', plugin_dir_url(__FILE__));

spl_autoload_register(static function (string $class): void {
    if (strpos($class, 'YooKDS\\') !== 0) { return; }
    $file = YOOKDS_PLUGIN_DIR . 'includes/' . str_replace('\\', '/', substr($class, 7)) . '.php';
    if (is_file($file)) { require_once $file; }
});
add_action('before_woocommerce_init', static function (): void {
    if (class_exists(\Automattic\WooCommerce\Utilities\FeaturesUtil::class)) {
        // API compatibility declaration, not a claim of a completed production certification.
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
    }
});
add_action('plugins_loaded', static function (): void {
    if (!class_exists('WooCommerce') || !defined('WC_VERSION') || version_compare(WC_VERSION, '8.2', '<')) {
        add_action('admin_notices', static function (): void {
            echo '<div class="notice notice-error"><p>YooKDS kräver aktiv WooCommerce 8.2 eller senare.</p></div>';
        });
        return;
    }
    foreach (['YOOKDS_SNIPPET_LOADED', 'YOOKDS_ALL_IN_ONE_V32', 'YOOKDS_ARCHIVE_PATCH_V1'] as $legacy) {
        if (defined($legacy)) {
            add_action('admin_notices', static function (): void {
                echo '<div class="notice notice-error"><p>Inaktivera äldre YooKDS-snippets innan testpluginet används. Inga äldre data har raderats.</p></div>';
            });
            return;
        }
    }
    (new \YooKDS\Plugin())->init();
}, 30);
register_activation_hook(__FILE__, static function (): void {
    \YooKDS\Shortcodes::route();
    flush_rewrite_rules(false);
});
register_deactivation_hook(__FILE__, static function (): void { flush_rewrite_rules(false); });
