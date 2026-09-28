<?php
namespace YooKDS;
if (!defined('ABSPATH')) { exit; }

/** Secrets are never included in the board bootstrap or returned by the settings API. */
final class Connections {
    public const KEY = 'yookds_connections_v1';
    public const FIELDS = [
        'bizprint' => ['public_key', 'secret_key', 'printer_id', 'enabled', 'auto_print', 'paper_width'],
        'foodora' => ['username', 'password', 'venue_id', 'webhook_secret', 'enabled', 'environment'],
        'wolt' => ['client_id', 'client_secret', 'refresh_token', 'venue_id', 'webhook_secret', 'enabled', 'environment'],
    ];
    public const SECRETS = ['secret_key', 'client_secret', 'refresh_token', 'webhook_secret', 'password'];
    public static function defaults(string $provider): array {
        $out = array_fill_keys(self::FIELDS[$provider] ?? [], '');
        return array_replace($out, ['enabled'=>false], $provider === 'bizprint'
            ? ['printer_id'=>0, 'auto_print'=>false, 'paper_width'=>80]
            : ['environment'=>'sandbox', 'webhook_header'=>'Authorization']);
    }
    public static function all(): array {
        $saved = get_option(self::KEY, []);
        return is_array($saved) ? $saved : [];
    }
    public static function get(string $provider): array {
        if (!isset(self::FIELDS[$provider])) { throw new Problem('provider', 'Okänd integration.', 400); }
        $out = array_replace(self::defaults($provider), self::all()[$provider] ?? []);
        foreach (self::SECRETS as $key) {
            if (!empty($out[$key])) { $out[$key] = self::decrypt($out[$key]); }
        }
        return $out;
    }
    private static function key(): string { return hash('sha256', wp_salt('auth') . ':yookds-connections', true); }
    private static function encrypt(string $plain): string {
        if ($plain === '') { return ''; }
        if (!function_exists('openssl_encrypt')) { throw new Problem('crypto_missing', 'Servern behöver PHP OpenSSL för att spara API-nycklar.', 503); }
        $iv = random_bytes(12); $tag = '';
        $cipher = openssl_encrypt($plain, 'aes-256-gcm', self::key(), OPENSSL_RAW_DATA, $iv, $tag);
        if ($cipher === false) { throw new Problem('crypto_failed', 'API-nyckeln kunde inte sparas säkert.', 503); }
        return 'enc1:' . base64_encode($iv . $tag . $cipher);
    }
    private static function decrypt(string $value): string {
        $raw = strpos($value, 'enc1:') === 0 ? base64_decode(substr($value, 5), true) : false;
        if ($raw === false || strlen($raw) < 29 || !function_exists('openssl_decrypt')) { return ''; }
        $plain = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', self::key(), OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16));
        return $plain === false ? '' : $plain;
    }
    public static function save(string $provider, array $input): array {
        return (new Lock())->named('oauth:' . $provider, static fn()=>self::saveUnlocked($provider, $input));
    }
    private static function saveUnlocked(string $provider, array $input): array {
        $current = self::get($provider);
        foreach (self::FIELDS[$provider] as $key) {
            if (!array_key_exists($key, $input)) { continue; }
            $value = $input[$key];
            if (in_array($key, ['enabled','auto_print'], true)) {
                if (!is_bool($value)) { throw new Problem('connection_value', 'Välj ja eller nej.', 400); }
            } elseif (in_array($key, ['printer_id','paper_width'], true)) {
                if (!is_int($value) || $value < 0 || ($key === 'paper_width' && !in_array($value, [58,80], true))) {
                    throw new Problem('connection_value', 'Ogiltigt skrivarval eller pappersformat.', 400);
                }
            } else {
                if (!is_string($value) || strlen($value) > 8192 || preg_match('/[\x00-\x1f\x7f]/', $value)) {
                    throw new Problem('connection_value', 'Ogiltigt värde i anslutningsuppgifterna.', 400);
                }
                $value = trim($value);
                if (in_array($key, self::SECRETS, true) && $value === '') { continue; } // Blank preserves a stored secret.
            }
            $current[$key] = $value;
        }
        if (isset($input['clear_secrets']) && !is_array($input['clear_secrets'])) { throw new Problem('connection_value', 'Ogiltigt fält för nyckelrensning.', 400); }
        foreach ($input['clear_secrets'] ?? [] as $key) {
            if (in_array($key, self::SECRETS, true)) { $current[$key] = ''; }
        }
        if ($provider !== 'bizprint') {
            if (!in_array($current['environment'], ['sandbox','production'], true)) { throw new Problem('environment', 'Välj testmiljö eller produktion.', 400); }
            if ($current['webhook_secret'] !== '' && strlen($current['webhook_secret']) < 32) {
                throw new Problem('weak_secret', 'Webhook-hemligheten behöver minst 32 tecken.', 400);
            }

        }
        if ($current['enabled']) {
            $required = $provider === 'bizprint' ? ['public_key','secret_key','printer_id'] : ['client_id','client_secret','venue_id','webhook_secret'];
            if ($provider === 'foodora') { $required = ['username','password','venue_id','webhook_secret']; }
            if ($provider === 'wolt') { $required[] = 'refresh_token'; }
            foreach ($required as $key) { if (empty($current[$key])) { throw new Problem('connection_incomplete', 'Fyll i anslutningsuppgifterna innan integrationen aktiveras.', 400); } }
        }
        self::store($provider, $current);
        delete_transient('yookds_token_' . $provider);
        delete_option('yookds_connection_check_' . $provider);
        return self::public($provider);
    }
    public static function store(string $provider, array $plain): void {
        (new Lock())->named('connections-store', static function () use ($provider, $plain) {
        $all = self::all();
        foreach (self::SECRETS as $key) { if (isset($plain[$key])) { $plain[$key] = self::encrypt($plain[$key]); } }
        $all[$provider] = $plain;
        update_option(self::KEY, $all, false);
        if (get_option(self::KEY) !== $all) { throw new Problem('settings_failed', 'Anslutningen kunde inte sparas.', 503); }
        });
    }
    public static function public(string $provider): array {
        $out = self::get($provider);
        foreach (self::SECRETS as $key) {
            if (array_key_exists($key, $out)) { $out[$key . '_saved'] = $out[$key] !== ''; unset($out[$key]); }
        }
        $out['check'] = get_option('yookds_connection_check_' . $provider, null);
        if ($provider !== 'bizprint') { $out['webhook_url'] = rest_url('yookds/v1/' . ($provider === 'foodora' ? 'foodora' : 'webhooks/wolt')); }
        return $out;
    }
    public static function status(string $provider, string $state): void {
        update_option('yookds_connection_check_' . $provider, ['state'=>$state, 'at'=>time()], false);
    }
    public static function summary(): array {
        $result = [];
        foreach (array_keys(self::FIELDS) as $provider) { $result[$provider] = self::public($provider); }
        return $result;
    }
}
