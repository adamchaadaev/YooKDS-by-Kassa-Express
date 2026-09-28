<?php
namespace YooKDS;
if (!defined('ABSPATH')) { exit; }

final class Assets {
    public function init(): void {
        add_action('wp_enqueue_scripts', [self::class, 'register']);
        add_action('admin_enqueue_scripts', [self::class, 'register']);
    }
    public static function register(): void {
        wp_register_style('yookds-kds', YOOKDS_PLUGIN_URL . 'assets/css/kds.css', [], YOOKDS_VERSION);
        wp_register_script('yookds-qr', YOOKDS_PLUGIN_URL . 'assets/js/qr.js', [], YOOKDS_VERSION, true);
        wp_register_script('yookds-kds', YOOKDS_PLUGIN_URL . 'assets/js/kds.js', ['yookds-qr'], YOOKDS_VERSION, true);
    }
    public static function enqueue(): void {
        self::register();
        wp_enqueue_style('yookds-kds');
        wp_enqueue_script('yookds-kds');
        wp_localize_script('yookds-kds', 'YooKDSBoot', [
            'rest' => trailingslashit(rest_url('yookds/v1')),
            'nonce' => wp_create_nonce('wp_rest'), 'settings' => Settings::get(),
            'can_configure' => current_user_can('manage_woocommerce'),
            'timezone' => wp_timezone_string(), 'version' => YOOKDS_VERSION,
            'source' => 'woocommerce', 'schema' => 2, 'site' => home_url('/'),
        ]);
    }
}
