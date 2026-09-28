<?php
namespace YooKDS;
if (!defined('ABSPATH')) { exit; }

final class BizPrint {
    public const BASE = 'https://print.bizswoop.app/api/connect-application/v1/';
    public static function table(): string { global $wpdb; return $wpdb->prefix . 'yookds_print_jobs'; }
    public static function install(): void {
        global $wpdb; require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $table = self::table(); $collation = $wpdb->get_charset_collate();
        dbDelta("CREATE TABLE $table (
            id bigint unsigned NOT NULL AUTO_INCREMENT,
            request_hash char(64) NOT NULL,
            order_id bigint NOT NULL,
            token_hash char(64) NOT NULL,
            receipt longtext NOT NULL,
            job_id bigint unsigned NOT NULL DEFAULT 0,
            state varchar(30) NOT NULL,
            created_at bigint NOT NULL,
            expires_at bigint NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY request_hash (request_hash),
            UNIQUE KEY token_hash (token_hash)
        ) $collation;");
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table))) !== $table) { throw new Problem('print_schema', 'Utskriftskön kunde inte skapas.', 503); }
        add_option('yookds_print_installed_at', time(), '', false);
    }
    public function init(): void {
        add_action('woocommerce_order_status_changed', static function ($id): void { self::schedule((int) $id); });
        add_action('woocommerce_checkout_order_processed', static function ($id): void { self::schedule((int) $id); });
        add_action('woocommerce_store_api_checkout_order_processed', static function ($order): void { self::schedule((int) $order->get_id()); });
        add_action('yookds_auto_print', [self::class, 'maybeAuto']);
        add_action('yookds_external_maintenance', [self::class, 'cleanup']);
    }
    private static function schedule(int $id): void {
        if (!Connections::get('bizprint')['auto_print']) { return; }
        if (function_exists('as_schedule_single_action')) { as_schedule_single_action(time()+5, 'yookds_auto_print', [$id], 'yookds', true); }
        elseif (!wp_next_scheduled('yookds_auto_print', [$id])) { wp_schedule_single_event(time()+5, 'yookds_auto_print', [$id]); }
    }
    public static function maybeAuto(int $id): void {
        $c = Connections::get('bizprint');
        if (!$c['enabled'] || !$c['auto_print']) { return; }
        try {
            $row = self::order($id);
            if (!$row || !empty($row['integration_error']) || !empty($row['integration_pending']) || $row['created_at'] < (int) get_option('yookds_print_installed_at', time()) || $row['state'] !== 'preparing' || $row['changed']) { return; }
            self::send($id, 'auto-order-' . $id, false);
        } catch (\Throwable $e) {
            Connections::status('bizprint', 'print_needs_attention');
        }
    }
    public static function order(int $id): ?array {
        $row = $id < 0 ? ExternalOrders::view(ExternalOrders::record($id)) : (new Orders())->sync($id);
        if ($row && (!empty($row['integration_error']) || !empty($row['integration_pending']))) { throw new Problem('external_unsynced','Invänta synkronisering före utskrift.',409); }
        return $row;
    }
    public static function signedGet(array $query, string $public, string $secret, int $time): string {
        $query['publicKey'] = $public; $query['time'] = (string) $time;
        $encoded = str_replace('%2A', '*', http_build_query($query, '', '&', PHP_QUERY_RFC1738));
        return $encoded . '&hash=' . hash('sha256', $encoded . ':' . $secret);
    }
    public static function signedPost(array $body, string $public, string $secret, int $time): string {
        $body['publicKey'] = $public; $body['time'] = $time;
        $json = json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $body['hash'] = hash('sha256', $json . ':' . $secret);
        return json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }
    public static function api(string $path, ?array $body = null, array $query = []): array {
        $c = Connections::get('bizprint');
        if (!$c['public_key'] || !$c['secret_key']) { throw new Problem('bizprint_keys', 'Ange BizPrint Public Key och Secret Key.', 400); }
        if ($body === null) {
            return ProviderApi::http(self::BASE . $path . '?' . self::signedGet($query, $c['public_key'], $c['secret_key'], time()), ['method'=>'GET']);
        }
        return ProviderApi::http(self::BASE . $path, ['method'=>'POST', 'headers'=>['Content-Type'=>'application/json'],
            'body'=>self::signedPost($body, $c['public_key'], $c['secret_key'], time())]);
    }
    public static function printers(): array {
        $rows = [];
        for ($page=1; $page<=20; $page++) {
            $data = self::api('printers', null, ['perPage'=>100, 'page'=>$page]);
            if (!is_array($data['data'] ?? null)) { throw new Problem('printers_response', 'BizPrint gav ingen skrivarlista.', 502); }
            foreach ($data['data'] as $printer) {
                if (!isset($printer['id'], $printer['name'])) { continue; }
                $rows[] = ['id'=>(int) $printer['id'], 'name'=>Snapshot::text($printer['name']), 'station'=>Snapshot::text($printer['station']['name'] ?? '')];
            }
            if (count($data['data']) < 100) { break; }
        }
        Connections::status('bizprint', 'credentials_verified');
        return $rows;
    }
    public static function send(int $id, string $request, bool $test = false): array {
        if (!preg_match('/^[a-zA-Z0-9_-]{8,80}$/D', $request)) { throw new Problem('print_request', 'Ogiltig utskriftsidentitet.', 400); }
        $hash = hash('sha256', $id . ':' . $request);
        return (new Lock())->named('print:' . $hash, static function () use ($id, $hash, $test) {
            global $wpdb; $table = self::table();
            $prior = $wpdb->get_row($wpdb->prepare("SELECT id,job_id,state FROM $table WHERE request_hash=%s", $hash), ARRAY_A);
            if ($prior) {
                if (!in_array($prior['state'], ['accepted','pending','processing','done'], true)) { throw new Problem('print_uncertain', 'Utskriftsförsöket behöver kontrolleras i BizPrint innan en ny kopia beställs.', 409); }
                return ['id'=>(int)$prior['id'], 'job_id'=>(int)$prior['job_id'], 'state'=>$prior['state'], 'replayed'=>true];
            }
            $c = Connections::get('bizprint');
            if (!$c['enabled'] || !$c['printer_id']) { throw new Problem('printer_missing', 'Aktivera BizPrint och välj en skrivare.', 400); }
            $base = rest_url('yookds/v1/print-receipt');
            if (strpos($base, 'https://') !== 0) { throw new Problem('print_https', 'BizPrint behöver en publikt nåbar HTTPS-adress för utskriften.', 400); }
            $row = $test ? null : self::order($id);
            if (!$test && !$row) { throw new Problem('not_found', 'Ordern finns inte i KDS.', 404); }
            $token = bin2hex(random_bytes(32));
            $html = self::receipt($row, $c['paper_width']);
            if (!$wpdb->insert($table, ['request_hash'=>$hash, 'order_id'=>$id, 'token_hash'=>hash('sha256', $token), 'receipt'=>$html,
                'state'=>'submitting', 'created_at'=>time(), 'expires_at'=>time()+DAY_IN_SECONDS])) { throw new Problem('print_queue', 'Utskriftsförsöket kunde inte sparas.', 503); }
            $localId = (int) $wpdb->insert_id;
            try {
                $response = self::api('jobs', ['printerId'=>$c['printer_id'], 'url'=>add_query_arg('token', $token, $base),
                    'description'=>$test ? 'YooKDS provutskrift' : 'YooKDS ' . ($row['provider'] ?? 'WooCommerce') . ' #' . $row['order_number']]);
                if (empty($response['data']['id']) || !is_numeric($response['data']['id'])) { throw new Problem('print_response', 'BizPrint bekräftade inget jobbnummer.', 502); }
                $job = (int) $response['data']['id'];
                if ($wpdb->update($table, ['job_id'=>$job,'state'=>'accepted'], ['id'=>$localId]) === false) { throw new Problem('print_save', 'Jobbet skickades men kvittensen kunde inte sparas. Kontrollera BizPrint.', 503); }
                return ['id'=>$localId,'job_id'=>$job,'state'=>'accepted','replayed'=>false];
            } catch (\Throwable $e) {
                // A network failure does not prove that the cloud rejected the job. Never auto-resubmit.
                $wpdb->update($table, ['state'=>'unknown'], ['id'=>$localId]);
                throw new Problem('print_uncertain', 'Ingen säker utskriftskvittens. Kontrollera BizPrint-kön innan du beställer en ny kopia.', 502);
            }
        });
    }
    public static function jobs(): array {
        global $wpdb; $table = self::table();
        return $wpdb->get_results("SELECT id,order_id,job_id,state,created_at FROM $table ORDER BY id DESC LIMIT 30", ARRAY_A) ?: [];
    }
    public static function refreshJob(int $id): array {
        global $wpdb; $table = self::table();
        $job = $wpdb->get_row($wpdb->prepare("SELECT id,job_id,state FROM $table WHERE id=%d", $id), ARRAY_A);
        if (!$job || !$job['job_id']) { throw new Problem('job_unknown', 'Kontrollera detta utskriftsförsök direkt i BizPrint.', 409); }
        $remote = self::api('jobs/' . (int) $job['job_id']);
        $state = $remote['data']['status'] ?? '';
        if (!in_array($state, ['pending','processing','done','failed','connecting-to-printer','archived'], true)) { throw new Problem('job_status', 'Okänd utskriftsstatus.', 502); }
        $wpdb->update($table, ['state'=>$state], ['id'=>$id]);
        return ['id'=>$id,'state'=>$state];
    }
    public static function receipt(?array $row, int $width): string {
        $e = static fn($v) => esc_html((string) $v);
        $html = '<!doctype html><html lang="sv"><meta charset="utf-8"><meta name="robots" content="noindex,nofollow"><title>Köksbiljett</title><style>@page{size:' . $width . 'mm auto;margin:3mm}body{font:14px/1.4 monospace;width:' . ($width-8) . 'mm;margin:0;color:#000}h1{font-size:28px}h2{font-size:16px}p{white-space:pre-wrap}li{margin:10px 0}small{font-size:12px}</style><body><h2>' . $e(get_bloginfo('name')) . '</h2>';
        if (!$row) { return $html . '<h1>PROVUTSKRIFT</h1><p>YooKDS · BizPrint</p><p>ÅÄÖ åäö · 1234567890</p><p>' . $e($width) . ' mm</p></body></html>'; }
        $html .= '<h2>' . $e(strtoupper($row['provider'] ?? $row['channel'])) . '</h2><h1>#' . $e($row['order_number']) . '</h1>';
        if ($row['origin']) { $html .= '<p>Kassa ' . $e($row['origin']) . '</p>'; }
        if ($row['table']) { $html .= '<p>Bord ' . $e($row['table']) . '</p>'; }
        $html .= '<ul>';
        foreach ($row['items'] as $item) {
            $html .= '<li><strong>' . $e($item['quantity']) . ' × ' . $e($item['name']) . '</strong>';
            foreach ($item['details'] as $d) { $html .= '<p>' . $e($d['label']) . ': ' . $e($d['value']) . '</p>'; }
            foreach ($item['field_warnings'] ?? [] as $warning) { $html .= '<p>OBS: ' . $e($warning) . '</p>'; }
            $html .= '</li>';
        }
        $html .= '</ul>';
        foreach ($row['custom_fields'] ?? [] as $d) { $html .= '<p>' . $e($d['label']) . ': ' . $e($d['value']) . '</p>'; }
        foreach ($row['shipping'] as $shipping) { $html .= '<p>' . $e($shipping['label']) . '</p>'; }
        if ($row['note']) { $html .= '<p><strong>OBS:</strong> ' . $e($row['note']) . '</p>'; }
        return $html . '<hr><small>Köksbiljett · ej betalningskvitto<br>' . $e(wp_date('Y-m-d H:i')) . '</small></body></html>';
    }
    public static function receiptForToken(string $token): string {
        if (!preg_match('/^[a-f0-9]{64}$/D', $token)) { throw new Problem('receipt_missing', 'Utskriften är inte tillgänglig.', 404); }
        global $wpdb; $table = self::table();
        $html = $wpdb->get_var($wpdb->prepare("SELECT receipt FROM $table WHERE token_hash=%s AND expires_at>%d", hash('sha256', $token), time()));
        if (!$html) { throw new Problem('receipt_missing', 'Utskriftslänken har gått ut.', 404); }
        return $html;
    }
    public static function cleanup(): void {
        global $wpdb; $table = self::table();
        $wpdb->query($wpdb->prepare("UPDATE $table SET receipt='' WHERE expires_at<%d AND receipt<>''", time()));
        // Auto-print deduplication markers are retained; otherwise old scheduled jobs could print twice.
    }
}
