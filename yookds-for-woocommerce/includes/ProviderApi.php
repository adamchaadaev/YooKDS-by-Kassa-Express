<?php
namespace YooKDS;
if (!defined('ABSPATH')) { exit; }

final class ProviderApi {
    /** Fixed service URLs, no URLs from webhook bodies: prevents SSRF and credential forwarding. */
    public static function host(string $provider, bool $auth = false): string {
        $sandbox = Connections::get($provider)['environment'] === 'sandbox';
        if ($provider === 'foodora') { return $sandbox ? 'https://integration-middleware.stg.restaurant-partners.com' : 'https://integration-middleware.eu.restaurant-partners.com'; }
        $service = $auth ? 'integrations-authentication-service' : 'pos-integration-service';
        return 'https://' . $service . ($sandbox ? '.development.dev.woltapi.com' : '.wolt.com');
    }
    public static function http(string $url, array $args): array {
        $response = wp_remote_request($url, array_replace(['timeout'=>20, 'redirection'=>0, 'limit_response_size'=>2097152], $args));
        if (is_wp_error($response)) { throw new Problem('provider_unreachable', 'Tjänsten svarade inte. Kontrollera anslutningen och försök igen.', 503); }
        $code = wp_remote_retrieve_response_code($response);
        if ($code < 200 || $code >= 300) {
            throw new Problem('provider_http_' . $code, 'Tjänsten svarade med HTTP ' . $code . '. Kontrollera konto, API-behörigheter och vald miljö.', $code === 429 ? 429 : 502);
        }
        $body = wp_remote_retrieve_body($response);
        if ($body === '') { return []; }
        $json = json_decode($body, true);
        if (!is_array($json)) { throw new Problem('provider_response', 'Tjänsten gav ett ogiltigt svar.', 502); }
        return $json;
    }
    public static function token(string $provider): string {
        if ($provider === 'foodora') { return FoodoraRestaurant::token(); }
        $cached = get_transient('yookds_token_' . $provider);
        if (is_string($cached) && $cached !== '') { return $cached; }
        return (new Lock())->named('oauth:' . $provider, static function () use ($provider) {
            $cached = get_transient('yookds_token_' . $provider);
            if (is_string($cached) && $cached !== '') { return $cached; }
            $c = Connections::get($provider);
            if ($c['client_id'] === '' || $c['client_secret'] === '') { throw new Problem('credentials_missing', 'Fyll i Client ID och Client Secret.', 400); }
            $headers = ['Content-Type'=>'application/x-www-form-urlencoded'];
            if ($c['refresh_token'] === '') { throw new Problem('wolt_onboarding', 'Slutför Wolt-onboarding och ange din refresh token.', 400); }
            $path = '/oauth2/token';
            $headers['Authorization'] = 'Basic ' . base64_encode($c['client_id'] . ':' . $c['client_secret']);
            $body = ['grant_type'=>'refresh_token', 'refresh_token'=>$c['refresh_token']];
            $result = self::http(self::host($provider, true) . $path, ['method'=>'POST', 'headers'=>$headers, 'body'=>http_build_query($body, '', '&')]);
            if (!is_string($result['access_token'] ?? null) || empty($result['expires_in'])) { throw new Problem('token_response', 'Tjänsten gav ingen användbar åtkomsttoken.', 502); }
            if ($provider === 'wolt') {
                if (empty($result['refresh_token']) || !is_string($result['refresh_token'])) { throw new Problem('token_response', 'Wolt returnerade ingen ny refresh token. Återanslut kontot.', 502); }
                $c['refresh_token'] = $result['refresh_token']; Connections::store($provider, $c);
            }
            set_transient('yookds_token_' . $provider, $result['access_token'], max(1, (int) $result['expires_in'] - 60));
            return $result['access_token'];
        });
    }
    public static function request(string $provider, string $path, string $method = 'GET', ?array $body = null): array {
        $args = ['method'=>$method, 'headers'=>['Authorization'=>'Bearer ' . self::token($provider), 'Content-Type'=>'application/json']];
        if ($body !== null) { $args['body'] = wp_json_encode($body); }
        return self::http(self::host($provider) . $path, $args);
    }
    public static function order(string $provider, string $id): array {
        if ($provider !== 'wolt') { throw new Problem('provider_flow','Foodora levererar orderinnehållet via restaurangwebhook.',400); }
        $c = Connections::get('wolt');
        $data = self::request('wolt', '/v2/orders/' . rawurlencode($id));
        $venue = $data['venue']['id'] ?? '';
        if (!is_string($venue) || !hash_equals($c['venue_id'], $venue) || ($data['id'] ?? '') !== $id) {
            throw new Problem('venue_mismatch', 'Ordern tillhör inte den konfigurerade restaurangen.', 422);
        }
        return $data;
    }
    public static function ready(string $provider, string $id): void {
        if ($provider === 'wolt') { self::request($provider, '/orders/' . rawurlencode($id) . '/ready', 'PUT', []); return; }
        FoodoraRestaurant::ready($id);
    }
}
