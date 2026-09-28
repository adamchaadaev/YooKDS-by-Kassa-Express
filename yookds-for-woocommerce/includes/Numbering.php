<?php
namespace YooKDS;
if (!defined('ABSPATH')) { exit; }

final class Numbering {
    public static function format($number) {
        $digits=preg_replace('/\D+/', '', (string) $number);
        return $digits==='' ? $number : str_pad(substr($digits, -4), 4, '0', STR_PAD_LEFT);
    }
    public function init(): void {
        add_filter('woocommerce_order_number', static function ($number, $order) {
            return Settings::get()['four_digit_numbers'] ? self::format($number) : $number;
        }, PHP_INT_MAX, 2);
        add_action('wp_enqueue_scripts', static function (): void {
            if (Settings::get()['four_digit_numbers'] && function_exists('is_order_received_page') && is_order_received_page()) {
                wp_enqueue_script('yookds-receipt-numbers', YOOKDS_PLUGIN_URL.'assets/js/receipt-numbers.js', [], YOOKDS_VERSION, true);
            }
        }, 30);
    }
}
