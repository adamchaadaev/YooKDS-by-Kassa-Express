<?php
namespace YooKDS;
if (!defined('ABSPATH')) { exit; }

final class Admin {
    public function init(): void {
        add_action('admin_menu', static function (): void {
            add_submenu_page('woocommerce', 'YooKDS', 'YooKDS', 'edit_shop_orders', 'yookds-main', static function (): void {
                echo '<div class="wrap yookds-admin-wrap">';
                echo '<p><a class="button" target="_blank" rel="noopener" href="' . esc_url(Shortcodes::url()) . '">Öppna fristående köksskärm ↗</a></p>';
                echo Shortcodes::render();
                echo '</div>';
            });
        });
        add_action('woocommerce_admin_order_data_after_order_details', static function ($order): void {
            if (!Rest::allowed() || !($order instanceof \WC_Order)) { return; }
            $flow = $order->get_meta(Orders::FLOW, true, 'edit');
            if (!is_array($flow)) { return; }
            $names = ['preparing' => 'Förbereds', 'ready' => 'Klar', 'blocked' => 'Stoppad', 'archived' => 'Historik'];
            echo '<p class="form-field form-field-wide"><strong>YooKDS:</strong> ' . esc_html($names[$flow['state'] ?? ''] ?? 'Okänt');
            if (!empty($flow['changed'])) { echo ' — ändring måste bekräftas i köket'; }
            echo ' <small>(revision ' . esc_html((string) ($flow['revision'] ?? 0)) . ')</small></p>';
        });
    }
}
