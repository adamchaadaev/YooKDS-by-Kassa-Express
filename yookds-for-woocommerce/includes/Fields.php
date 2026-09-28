<?php
namespace YooKDS;
if (!defined('ABSPATH')) { exit; }

/** Read saved values; never execute field markup, shortcodes, formulas or PHP. */
final class Fields {
    public const PRODUCT_SNAPSHOT = '_yookds_product_fields';
    public static function forbiddenKey(string $key): bool {
        $key = strtolower($key);
        if (strpos($key, '_yookds_') === 0) { return true; }
        return (bool) preg_match('/(?:password|passwd|secret|token|nonce|authorization|api[_ -]?key|order[_ -]?key|card[_ -]?(?:number|cvc|cvv)|payment[_ -]?(?:data|method)|transaction[_ -]?id|stripe|paypal|klarna|session|customer_ip|user_agent)/', $key);
    }
    /** Handles text, numbers, booleans and arrays without dropping a zero or false. */
    public static function value($value, int $depth = 0): string {
        if ($depth > 6) { return ''; }
        if (is_bool($value)) { return $value ? 'Ja' : 'Nej'; }
        if (is_scalar($value)) { return Snapshot::text($value); }
        if (!is_array($value)) { return ''; }
        $parts=[];
        foreach ($value as $key=>$part) {
            if (is_string($key) && self::forbiddenKey($key)) { continue; }
            $text=self::value($part, $depth+1);
            if ($text==='') { continue; }
            $parts[]=(is_string($key) ? Snapshot::text($key) . ': ' : '') . $text;
        }
        return implode("\n", $parts);
    }
    private static function same(array $a, array $b): bool {
        return $a['label']===$b['label'] && $a['value']===$b['value'];
    }
    /** Consume matching standard rows one at a time; retain repeated WAPF selections. */
    public static function mergeFallback(array $standard, array $extra): array {
        $unmatched=$standard;
        foreach ($extra as $row) {
            $found=false;
            foreach ($unmatched as $i=>$existing) {
                if (self::same($existing, $row)) { unset($unmatched[$i]); $found=true; break; }
            }
            if (!$found) { $standard[]=$row; }
        }
        return $standard;
    }
    /** Supported saved WAPF representation: field IDs -> {label, value, raw?}. Raw IDs are not printed. */
    public static function wapf($data): array {
        if ($data==='' || $data===null || $data===[]) { return ['details'=>[], 'warnings'=>[]]; }
        if (is_string($data)) {
            $decoded=json_decode($data, true);
            if (is_array($decoded)) { $data=$decoded; }
            else { return ['details'=>[], 'warnings'=>['WAPF-data finns men formatet kan inte läsas säkert. Kontrollera tillvalen på WooCommerce-ordern.']]; }
        }
        if (!is_array($data)) { return ['details'=>[], 'warnings'=>['WAPF-formatet behöver kontrolleras på WooCommerce-ordern.']]; }
        if (array_key_exists('label', $data) && array_key_exists('value', $data)) { $data=[$data]; }
        $rows=[]; $warnings=[];
        foreach ($data as $field) {
            if (!is_array($field) || !array_key_exists('label', $field) || !array_key_exists('value', $field)) {
                $warnings[]='Ett sparat WAPF-fält kunde inte tolkas. Kontrollera WooCommerce-ordern.'; continue;
            }
            $label=Snapshot::text($field['label']);
            $value=self::value($field['value']);
            if ($value==='' && ($field['value']==='' || $field['value']===null || $field['value']===[])) { continue; }
            if ($label==='' || $value==='') { $warnings[]='Ett WAPF-värde kunde inte läsas fullständigt.'; continue; }
            $rows[]=['label'=>$label, 'value'=>$value];
        }
        return ['details'=>$rows, 'warnings'=>array_values(array_unique($warnings))];
    }
    public static function item($item, \WC_Order $order): array {
        $rows=[]; $warnings=[]; $mapped=[];
        foreach (Settings::get()['custom_fields'] as $map) { if ($map['scope']==='item') { $mapped[$map['key']]=$map; } }
        // WooCommerce's display pipeline keeps variation names and add-on display filters.
        $hidden=apply_filters('woocommerce_hidden_order_itemmeta', []);
        $hidden=is_array($hidden) ? $hidden : [];
        foreach ($item->get_formatted_meta_data('_', true) as $meta) {
            $key=(string) ($meta->key ?? $meta->display_key ?? '');
            if (self::forbiddenKey($key) || in_array($key, $hidden, true) || isset($mapped[$key]) || strpos($key, '_')===0) { continue; }
            $value=self::value($meta->display_value ?? '');
            $label=Snapshot::text($meta->display_key ?? $key);
            if ($value!=='' && $label!=='') { $rows[]=['label'=>$label, 'value'=>$value]; }
        }
        // Read only the explicitly known WAPF selection container, not all hidden item meta.
        $wapf=self::wapf($item->get_meta('_wapf_meta', true, 'edit'));
        $rows=self::mergeFallback($rows, $wapf['details']);
        $warnings=$wapf['warnings'];
        foreach ($mapped as $key=>$map) {
            if (!$item->meta_exists($key)) { continue; }
            $value=self::value($item->get_meta($key, true, 'edit'));
            if ($value!=='') { $rows[]=['label'=>$map['label'], 'value'=>$value]; }
        }
        $snap=$item->get_meta(self::PRODUCT_SNAPSHOT, true, 'edit');
        foreach (Settings::get()['custom_fields'] as $map) {
            if ($map['scope']!=='product') { continue; }
            // Product values are copied only during order creation, never backfilled from today's catalog.
            if (is_array($snap) && array_key_exists($map['key'], $snap)) {
                $value=self::value($snap[$map['key']]['value'] ?? '');
                if ($value!=='') { $rows[]=['label'=>$map['label'], 'value'=>$value]; }
            }
        }
        $rows=apply_filters('yookds_item_details', $rows, $item, $order);
        return ['details'=>self::cleanRows($rows), 'warnings'=>$warnings];
    }
    public static function order(\WC_Order $order): array {
        $rows=[];
        foreach (Settings::get()['custom_fields'] as $map) {
            if ($map['scope']!=='order' || !$order->meta_exists($map['key'])) { continue; }
            $value=self::value($order->get_meta($map['key'], true, 'edit'));
            if ($value!=='') { $rows[]=['label'=>$map['label'], 'value'=>$value]; }
        }
        return self::cleanRows(apply_filters('yookds_order_details', $rows, $order));
    }
    public static function cleanRows($rows): array {
        $safe=[];
        foreach (is_array($rows) ? $rows : [] as $row) {
            if (!is_array($row)) { continue; }
            $label=Snapshot::text($row['label'] ?? ''); $value=self::value($row['value'] ?? '');
            if ($label!=='' && $value!=='') { $safe[]=['label'=>$label, 'value'=>$value]; }
        }
        return $safe;
    }
    public static function snapshotProduct($item, $cartKey, $values, $order): void {
        if ($item->meta_exists(self::PRODUCT_SNAPSHOT)) { return; }
        $product=$item->get_product();
        if (!$product) { return; }
        $snapshot=[];
        foreach (Settings::get()['custom_fields'] as $map) {
            if ($map['scope']!=='product') { continue; }
            $source=$product;
            if (!$source->meta_exists($map['key']) && $source->get_parent_id()) { $source=wc_get_product($source->get_parent_id()); }
            if (!$source || !$source->meta_exists($map['key'])) { continue; }
            $snapshot[$map['key']]=['label'=>$map['label'], 'value'=>self::value($source->get_meta($map['key'], true, 'edit'))];
        }
        // Empty snapshot still marks the historical point; future catalog edits are not substituted.
        $item->update_meta_data(self::PRODUCT_SNAPSHOT, $snapshot);
    }
    public function init(): void {
        add_action('woocommerce_checkout_create_order_line_item', [self::class, 'snapshotProduct'], 100, 4);
        // Checkout Blocks may not execute the legacy line-item hook; capture before final order save.
        add_action('woocommerce_store_api_checkout_update_order_from_request', static function ($order, $request): void {
            foreach ($order->get_items('line_item') as $item) {
                self::snapshotProduct($item, '', [], $order);
                $item->save();
            }
        }, 100, 2);
    }
}
