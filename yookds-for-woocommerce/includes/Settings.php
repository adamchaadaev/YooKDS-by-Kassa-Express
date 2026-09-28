<?php
namespace YooKDS;
if (!defined('ABSPATH')) { exit; }

final class Settings {
    public const KEY = 'yookds_v2_settings';
    public const CLOSED_STATUSES = ['completed', 'cancelled', 'refunded', 'failed', 'trash', 'draft', 'auto-draft', 'checkout-draft'];
    public static function openStatuses(): array {
        $registered = array_map(static fn($s) => preg_replace('/^wc-/', '', $s), array_keys(wc_get_order_statuses()));
        $open = array_values(array_diff($registered, self::CLOSED_STATUSES));
        $selected = apply_filters('yookds_open_order_statuses', $open);
        if (!is_array($selected)) { $selected = $open; }
        return array_values(array_intersect($open, array_filter($selected, 'is_string')));
    }
    public static function defaults(): array {
        return [
            'receive_statuses' => self::openStatuses(), 'poll_seconds' => 5,
            'complete_on_handover' => true, 'four_digit_numbers' => true, 'custom_fields' => [],
            'channels' => [
                ['id'=>'web', 'name'=>'Online', 'color'=>'#1152ac'],
                ['id'=>'express', 'name'=>'Express', 'color'=>'#0e7a0e'],
                ['id'=>'table', 'name'=>'QR / Bord', 'color'=>'#8b5cf6'],
            ],
        ];
    }
    public static function get(): array {
        $saved = get_option(self::KEY, []);
        try { return self::validate(is_array($saved) ? $saved : []); }
        catch (Problem $error) { return self::defaults(); }
    }
    public static function validate(array $input): array {
        $out = self::defaults();
        $poll = $input['poll_seconds'] ?? 5;
        if (!is_int($poll) || $poll < 3 || $poll > 60) {
            throw new Problem('settings_type', 'Uppdateringsintervallet ska vara 3–60 sekunder.', 400);
        }
        $four = $input['four_digit_numbers'] ?? true;
        if (!is_bool($four)) { throw new Problem('settings_type', 'Välj ja eller nej för fyrsiffriga nummer.', 400); }
        $fields = $input['custom_fields'] ?? [];
        if (!is_array($fields) || count($fields) > 50) { throw new Problem('settings_fields', 'Högst 50 anpassade fält stöds.', 400); }
        $clean = [];
        foreach ($fields as $field) {
            if (!is_array($field) || !in_array($field['scope'] ?? '', ['order', 'item', 'product'], true) ||
                !is_string($field['key'] ?? null) || !is_string($field['label'] ?? null)) {
                throw new Problem('settings_fields', 'Varje fält behöver källa, metanyckel och etikett.', 400);
            }
            $key = trim($field['key']);
            $label = trim(wp_strip_all_tags($field['label']));
            if ($key === '' || strlen($key) > 200 || preg_match('/[\x00-\x1F\x7F]/', $key) ||
                $label === '' || strlen($label) > 200 || Fields::forbiddenKey($key)) {
                throw new Problem('settings_fields', 'Ogiltig metanyckel eller etikett. Interna autentiserings- och betalningsfält tillåts inte.', 400);
            }
            $clean[$field['scope'] . ':' . $key] = ['scope'=>$field['scope'], 'key'=>$key, 'label'=>$label];
        }
        $channels = $input['channels'] ?? $out['channels'];
        if (!is_array($channels) || count($channels)>30) { throw new Problem('settings_channels', 'Högst 30 kanaler stöds.', 400); }
        $safeChannels=[];
        foreach ($channels as $channel) {
            if (!is_array($channel) || !is_string($channel['id'] ?? null) || !preg_match('/^[a-z0-9_-]{1,64}$/D', $channel['id']) ||
                !is_string($channel['name'] ?? null) || !is_string($channel['color'] ?? null) ||
                !preg_match('/^#[a-f0-9]{6}$/Di', $channel['color'])) {
                throw new Problem('settings_channels', 'En kanal behöver ett fast ID, namn och en giltig färg.', 400);
            }
            $name=trim(wp_strip_all_tags($channel['name']));
            if ($name==='' || strlen($name)>100) { throw new Problem('settings_channels', 'Ogiltigt kanalnamn.', 400); }
            $safeChannels[$channel['id']]=['id'=>$channel['id'], 'name'=>$name, 'color'=>$channel['color']];
        }
        // Old alpha.1 flags cannot turn off WC completion or hide otherwise open WC orders.
        return array_replace($out, ['poll_seconds'=>$poll, 'four_digit_numbers'=>$four,
            'custom_fields'=>array_values($clean), 'channels'=>array_values($safeChannels)]);
    }
}
