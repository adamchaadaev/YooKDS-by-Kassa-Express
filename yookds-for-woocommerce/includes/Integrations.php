<?php
namespace YooKDS;
if (!defined('ABSPATH')) { exit; }

final class Integrations {
    public function init(): void {
        add_action('rest_api_init', [$this, 'routes']);
        add_filter('rest_pre_serve_request', static function ($served, $response, $request) {
            if ($request->get_route() === '/yookds/v1/print-receipt' && $response->get_status() === 200 && is_string($response->get_data())) {
                echo $response->get_data(); return true; // HTML generated solely by the escaping receipt renderer.
            }
            return $served;
        }, 10, 3);
    }
    public static function install(): void {
        ExternalOrders::install(); BizPrint::install(); update_option('yookds_integrations_schema', 1, false);
    }
    public static function manager(): bool { return is_user_logged_in() && current_user_can('manage_woocommerce'); }
    private static function response(callable $work): \WP_REST_Response {
        try { $response = new \WP_REST_Response($work(), 200); }
        catch (Problem $e) { $response = new \WP_REST_Response(['code'=>$e->slug,'message'=>$e->getMessage()], $e->status); }
        catch (\Throwable $e) { $response = new \WP_REST_Response(['code'=>'integration_error','message'=>'Integrationen kunde inte slutföra åtgärden. Kontrollera inställningarna.'], 500); }
        $response->header('Cache-Control', 'private, no-store, max-age=0');
        $response->header('Vary', 'Cookie');
        return $response;
    }
    public static function signature(string $provider, string $body, string $header, string $secret): bool {
        if (strlen($secret) < 32 || strlen($body) > 2097152) { return false; }
        if ($provider === 'wolt') { return hash_equals(hash_hmac('sha256', $body, $secret), strtolower(trim($header))); }
        return FoodoraRestaurant::signature($header, $secret);
    }
    public function routes(): void {
        FoodoraRestaurant::routes();
        $manager = [self::class, 'manager'];
        $staff = [Rest::class, 'allowed'];
        register_rest_route('yookds/v1', '/integrations', [
            'methods'=>'GET','permission_callback'=>$manager,
            'callback'=>static fn()=>self::response(static fn()=>['connections'=>Connections::summary(), 'jobs'=>BizPrint::jobs()]),
        ]);
        register_rest_route('yookds/v1', '/integrations/(?P<provider>bizprint|foodora|wolt)', [
            'methods'=>'POST','permission_callback'=>$manager,
            'callback'=>static fn($r)=>self::response(static function () use ($r) {
                $body=$r->get_json_params();
                if (!is_array($body)) { throw new Problem('json','Ogiltiga inställningar.',400); }
                return ['connection'=>Connections::save($r['provider'], $body)];
            }),
        ]);
        register_rest_route('yookds/v1', '/integrations/(?P<provider>bizprint|foodora|wolt)/test', [
            'methods'=>'POST','permission_callback'=>$manager,
            'callback'=>static fn($r)=>self::response(static function () use ($r) {
                if ($r['provider']==='bizprint') { return ['printers'=>BizPrint::printers()]; }
                ProviderApi::token($r['provider']); Connections::status($r['provider'],'credentials_verified');
                return ['message'=>'API-uppgifterna fungerar. Orderflödet verifieras när första ordern tas emot.'];
            }),
        ]);
        register_rest_route('yookds/v1', '/integrations/retry', [
            'methods'=>'POST','permission_callback'=>$manager,
            'callback'=>static fn()=>self::response(static fn()=>['queued'=>ExternalOrders::retry()]),
        ]);
        register_rest_route('yookds/v1', '/external/(?P<id>[1-9][0-9]*)/actions', [
            'methods'=>'POST','permission_callback'=>$staff,
            'callback'=>static fn($r)=>self::response(static function () use ($r) {
                $body=$r->get_json_params();
                if (!is_array($body)) { throw new Problem('json','Ogiltig orderåtgärd.',400); }
                return ExternalOrders::change((int)$r['id'], $body, get_current_user_id());
            }),
        ]);
        register_rest_route('yookds/v1', '/external/archive', [
            'methods'=>'GET','permission_callback'=>$staff,
            'args'=>['range'=>['default'=>'today','enum'=>['today','yesterday','week','month']], 'q'=>['default'=>'','type'=>'string','maxLength'=>100]],
            'callback'=>static fn($r)=>self::response(static fn()=>ExternalOrders::archive($r['range'], $r['q'])),
        ]);
        register_rest_route('yookds/v1', '/print', [
            'methods'=>'POST','permission_callback'=>$staff,
            'callback'=>static fn($r)=>self::response(static function () use ($r) {
                $body=$r->get_json_params();
                if (!is_array($body) || !is_int($body['order_id'] ?? null) || !$body['order_id'] || !is_string($body['request_id'] ?? null)) { throw new Problem('print_body','Ogiltigt utskriftsanrop.',400); }
                return BizPrint::send($body['order_id'], $body['request_id']);
            }),
        ]);
        register_rest_route('yookds/v1', '/print/test', [
            'methods'=>'POST','permission_callback'=>$manager,
            'callback'=>static fn($r)=>self::response(static function () use ($r) {
                $body=$r->get_json_params();
                if (!is_array($body) || !is_string($body['request_id'] ?? null)) { throw new Problem('print_body','Ogiltigt utskriftsanrop.',400); }
                return BizPrint::send(0, $body['request_id'], true);
            }),
        ]);
        register_rest_route('yookds/v1', '/print/jobs/(?P<id>[1-9][0-9]*)', [
            'methods'=>'POST','permission_callback'=>$manager,
            'callback'=>static fn($r)=>self::response(static fn()=>BizPrint::refreshJob((int)$r['id'])),
        ]);
        register_rest_route('yookds/v1', '/print-receipt', [
            'methods'=>'GET','permission_callback'=>static function ($r) {
                try { BizPrint::receiptForToken((string)$r->get_param('token')); return true; }
                catch (Problem $e) { return new \WP_Error('receipt_missing','Utskriften är inte tillgänglig.',['status'=>404]); }
            },
            'callback'=>static function ($r) {
                $response = self::response(static fn()=>BizPrint::receiptForToken((string)$r->get_param('token')));
                $response->header('Content-Type','text/html; charset=UTF-8');
                $response->header('X-Robots-Tag','noindex, nofollow, noarchive');
                $response->header('Referrer-Policy','no-referrer');
                $response->header('Content-Security-Policy',"default-src 'none'; style-src 'unsafe-inline'; frame-ancestors 'none'");
                return $response;
            },
        ]);
        register_rest_route('yookds/v1', '/webhooks/(?P<provider>wolt)', [
            'methods'=>'POST',
            'permission_callback'=>static function ($r) {
                $p=$r['provider']; $c=Connections::get($p);
                $header=(string)$r->get_header($p==='wolt'?'wolt-signature':$c['webhook_header']);
                return $c['enabled'] && self::signature($p,$r->get_body(),$header,$c['webhook_secret'])
                    ? true : new \WP_Error('webhook_auth','Webhook-autentisering misslyckades.',['status'=>401]);
            },
            'callback'=>static fn($r)=>self::response(static function () use ($r) {
                $data=$r->get_json_params(); $p=$r['provider']; $c=Connections::get($p);
                if (!is_array($data)) { throw new Problem('webhook_json','Ogiltig webhook.',400); }
                if ($p==='wolt' && !in_array($data['type'] ?? '', ['order.notification','pickup_completed.notification'], true)) { return ['received'=>true,'ignored'=>true]; }
                $venue=$p==='wolt'?($data['order']['venue_id'] ?? ''):($data['client']['store_id'] ?? '');
                if ($venue!==$c['venue_id'] || ($p==='foodora' && ($data['client']['chain_id'] ?? '')!==$c['chain_id'])) { throw new Problem('webhook_venue','Fel restaurang i orderhändelsen.',422); }
                $id=$p==='wolt'?($data['order']['id'] ?? ''):($data['order_id'] ?? '');
                if (!is_string($id)) { throw new Problem('webhook_id','Ogiltigt order-ID.',400); }
                ExternalOrders::enqueue($p,$id);
                return ['received'=>true];
            }),
        ]);
    }
}
