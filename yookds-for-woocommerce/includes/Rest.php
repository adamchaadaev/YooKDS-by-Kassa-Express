<?php
namespace YooKDS;
if (!defined('ABSPATH')) { exit; }

final class Rest {
    private Orders $orders;
    public function __construct(Orders $orders) { $this->orders = $orders; }

    public static function allowed(): bool {
        return is_user_logged_in() && (current_user_can('manage_woocommerce') ||
            (current_user_can('edit_shop_orders') && current_user_can('edit_others_shop_orders')));
    }

    public function init(): void { add_action('rest_api_init', [$this, 'routes']); }

    public function routes(): void {
        $permission = static function () {
            return self::allowed() ? true : new \WP_Error('yookds_forbidden', 'Logga in med behörighet att hantera butikens ordrar.', ['status' => 403]);
        };
        register_rest_route('yookds/v1', '/orders', [
            'methods' => 'GET', 'permission_callback' => $permission,
            'args' => ['read_id' => ['required'=>true, 'type'=>'string', 'pattern'=>'^[a-f0-9]{32}$']],
            'callback' => function ($request) { return $this->respond(fn() => array_merge($this->board(), ['read_id' => (string) $request->get_param('read_id')])); },
        ]);
        register_rest_route('yookds/v1', '/orders/(?P<id>[1-9][0-9]*)/actions', [
            'methods' => 'POST', 'permission_callback' => $permission,
            'callback' => function ($request) {
                return $this->respond(function () use ($request) {
                    $body = $request->get_json_params();
                    if (!is_array($body)) { throw new Problem('invalid_json', 'Skicka ett JSON-objekt.', 400); }
                    return $this->orders->change((int) $request['id'], $body, get_current_user_id());
                });
            },
        ]);
        register_rest_route('yookds/v1', '/archive', [
            'methods' => 'GET', 'permission_callback' => $permission,
            'args' => [
                'read_id' => ['required'=>true, 'type'=>'string', 'pattern'=>'^[a-f0-9]{32}$'],
                'range' => ['default' => 'today', 'type' => 'string', 'enum' => ['today', 'yesterday', 'week', 'month']],
                'q' => ['default' => '', 'type' => 'string', 'maxLength' => 100, 'sanitize_callback' => 'sanitize_text_field'],
                'page' => ['default' => 1, 'type' => 'integer', 'minimum' => 1, 'maximum' => 100000],
            ],
            'callback' => function ($request) {
                return $this->respond(fn() => array_merge($this->orders->archive($request['range'], $request['q'], (int) $request['page']), ['read_id' => (string) $request->get_param('read_id')]));
            },
        ]);
        register_rest_route('yookds/v1', '/settings', [
            'methods' => 'POST',
            'permission_callback' => static function () { return is_user_logged_in() && current_user_can('manage_woocommerce'); },
            'callback' => function ($request) {
                return $this->respond(function () use ($request) {
                    $body = $request->get_json_params();
                    if (!is_array($body)) { throw new Problem('invalid_settings', 'Ogiltiga inställningar.', 400); }
                    $settings = Settings::validate(array_replace(Settings::get(), $body));
                    update_option(Settings::KEY, $settings, false);
                    if (Settings::get() !== $settings) { throw new Problem('settings_failed', 'Inställningarna kunde inte sparas.', 503); }
                    return ['settings' => $settings];
                });
            },
        ]);
        register_rest_route('yookds/v1', '/health', [
            'methods' => 'GET', 'permission_callback' => $permission,
            'callback' => function () { return $this->respond(fn() => array_merge(Orders::source(), ['server_time' => time(), 'settings' => Settings::get()])); },
        ]);
    }

    private function board(): array {
        $board = $this->orders->board();
        try {
            $external = ExternalOrders::board();
            $board['orders'] = array_merge($board['orders'], $external['orders']);
            $board['issues'] = array_merge($board['issues'], $external['issues']);
        } catch (\Throwable $error) { $board['issues'][] = ['id'=>0, 'code'=>'external_unavailable']; }
        $board['complete'] = !$board['issues'];
        usort($board['orders'], static fn($a,$b) => ($a['created_at'] <=> $b['created_at']) ?: ($a['id'] <=> $b['id']));
        return $board;
    }

    private function respond(callable $callback): \WP_REST_Response {
        try { $response = new \WP_REST_Response($callback(), 200); }
        catch (Problem $error) {
            $response = new \WP_REST_Response(['code' => $error->slug, 'message' => $error->getMessage()], $error->status);
        } catch (\Throwable $error) {
            wc_get_logger()->error('KDS request failed: ' . get_class($error), ['source' => 'yookds']);
            $response = new \WP_REST_Response(['code' => 'server_error', 'message' => 'Ett serverfel inträffade. Ingen åtgärd är bekräftad; uppdatera vyn.'], 500);
        }
        $response->header('Cache-Control', 'private, no-store, max-age=0');
        $response->header('Vary', 'Cookie');
        return $response;
    }
}
