<?php
namespace YooKDS;
if (!defined('ABSPATH')) { exit; }

final class Snapshot {
    public static function text($value): string {
        if (!is_scalar($value)) { return ''; }
        $text = preg_replace('/<br\s*\/?\s*>/i', "\n", (string) $value);
        return trim(html_entity_decode(wp_strip_all_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    public static function fromOrder(\WC_Order $order): array {
        $items = [];
        foreach ($order->get_items('line_item') as $item) {
            $extra = Fields::item($item, $order);
            $items[] = [
                'id' => (int) $item->get_id(), 'name' => self::text($item->get_name()),
                'quantity' => (string) $item->get_quantity(),
                'product_id' => (int) $item->get_product_id(), 'variation_id' => (int) $item->get_variation_id(),
                'details' => $extra['details'], 'field_warnings' => $extra['warnings'],
                // Keep the original quantity. Refund quantities are a separate warning, not a new recipe.
                'refunded_quantity' => (string) abs((float) $order->get_qty_refunded_for_item($item->get_id())),
            ];
        }
        $shipping = [];
        foreach ($order->get_items('shipping') as $item) {
            $shipping[] = ['id' => (int) $item->get_id(), 'label' => self::text($item->get_method_title()), 'method' => self::text($item->get_method_id())];
        }
        $context = [
            'channel' => self::meta($order, ['_nx_channel', 'nx_channel']) ?: 'woocommerce',
            'origin' => self::meta($order, ['_nx_station', 'nx_station']),
            'table' => self::meta($order, ['_nx_table', 'nx_table']),
            'mode' => self::meta($order, ['_nx_mode', 'nx_mode']),
        ];
        $context = apply_filters('yookds_order_context', $context, $order);
        $context = is_array($context) ? $context : [];
        return [
            'id' => (int) $order->get_id(), 'order_number' => self::text(Settings::get()['four_digit_numbers'] ? Numbering::format($order->get_order_number()) : $order->get_order_number()),
            'woo_status' => $order->get_status('edit'), 'woo_status_label' => self::text(wc_get_order_status_name($order->get_status('edit'))),
            'order_url' => esc_url_raw($order->get_edit_order_url()), 'items' => $items,
            'note' => self::text($order->get_customer_note('edit')), 'shipping' => $shipping,
            'custom_fields' => Fields::order($order), 'mode' => self::text($context['mode'] ?? ''),
            'channel' => self::text($context['channel'] ?? 'woocommerce'),
            'origin' => self::text($context['origin'] ?? ''), 'table' => self::text($context['table'] ?? ''),
            'currency' => $order->get_currency('edit'), 'total' => (string) $order->get_total('edit'),
            'refunded_total' => (string) $order->get_total_refunded(),
            'payment_recorded' => (bool) $order->get_date_paid('edit') || (float) $order->get_total('edit') === 0.0,
            'created_at' => $order->get_date_created('edit') ? $order->get_date_created('edit')->getTimestamp() : 0,
        ];
    }

    private static function meta(\WC_Order $order, array $keys): string {
        foreach ($keys as $key) {
            $value = self::text($order->get_meta($key, true, 'edit'));
            if ($value !== '') { return $value; }
        }
        return '';
    }

    public static function searchText(array $data): string {
        $parts = [$data['id'], $data['order_number'], $data['note'], $data['channel'], $data['origin'], $data['table']];
        foreach ($data['custom_fields'] ?? [] as $detail) { $parts[] = $detail['label'] . ' ' . $detail['value']; }
        foreach ($data['shipping'] as $shipping) { $parts[] = $shipping['label']; }
        foreach ($data['items'] as $item) {
            $parts[] = $item['name'];
            foreach ($item['details'] as $detail) { $parts[] = $detail['label'] . ' ' . $detail['value']; }
        }
        return implode(' ', $parts);
    }
}
