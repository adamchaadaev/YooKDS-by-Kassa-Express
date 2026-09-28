<?php
namespace YooKDS;
if (!defined('ABSPATH')) { exit; }

/** External kitchen tickets are not WooCommerce sales. IDs are negative in the unified feed. */
final class ExternalOrders {
    public static function table(): string { global $wpdb; return $wpdb->prefix . 'yookds_external'; }
    public static function install(): void {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $table = self::table(); $collation = $wpdb->get_charset_collate();
        dbDelta("CREATE TABLE $table (
            id bigint unsigned NOT NULL AUTO_INCREMENT,
            identity_hash char(64) NOT NULL,
            provider varchar(16) NOT NULL,
            venue varchar(128) NOT NULL,
            remote_id varchar(128) NOT NULL,
            environment varchar(16) NOT NULL,
            snapshot longtext NOT NULL,
            flow longtext NOT NULL,
            state varchar(20) NOT NULL DEFAULT 'pending',
            pending tinyint NOT NULL DEFAULT 1,
            attempts int NOT NULL DEFAULT 0,
            error_code varchar(80) NOT NULL DEFAULT '',
            updated_at bigint NOT NULL DEFAULT 0,
            closed_at bigint NOT NULL DEFAULT 0,
            PRIMARY KEY  (id),
            UNIQUE KEY identity_hash (identity_hash),
            KEY active (state, pending),
            KEY closed_at (closed_at)
        ) $collation;");
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table))) !== $table) {
            throw new Problem('schema_failed', 'Tabellen för externa ordrar kunde inte skapas.', 503);
        }
        update_option('yookds_external_schema', 1, false);
    }
    public function init(): void {
        add_filter('cron_schedules', static function ($s) { $s['yookds_five_minutes'] = ['interval'=>300, 'display'=>'YooKDS var femte minut']; return $s; });
        add_action('yookds_fetch_external', [self::class, 'process']);
        add_action('yookds_external_maintenance', [self::class, 'maintenance']);
        if (!wp_next_scheduled('yookds_external_maintenance')) { wp_schedule_event(time() + 60, 'yookds_five_minutes', 'yookds_external_maintenance'); }
    }
    public static function schedule(int $id, int $delay = 0): void {
        if (function_exists('as_schedule_single_action')) {
            as_schedule_single_action(time() + $delay, 'yookds_fetch_external', [$id], 'yookds');
        } elseif (!wp_next_scheduled('yookds_fetch_external', [$id])) {
            wp_schedule_single_event(time() + max(1, $delay), 'yookds_fetch_external', [$id]);
        }
    }
    public static function enqueue(string $provider, string $remoteId): void {
        global $wpdb; $c = Connections::get($provider);
        if (!preg_match('/^[a-zA-Z0-9_-]{1,128}$/D', $remoteId)) { throw new Problem('order_id', 'Ogiltigt order-ID.', 400); }
        $hash = hash('sha256', $provider . ':' . $c['environment'] . ':' . $c['venue_id'] . ':' . $remoteId);
        (new Lock())->named('external-inbox:' . $hash, static function () use ($wpdb, $provider, $remoteId, $c, $hash) {
            $table = self::table();
            $record = $wpdb->get_row($wpdb->prepare("SELECT id, attempts FROM $table WHERE identity_hash=%s", $hash), ARRAY_A);
            if (!$record) {
                if (!$wpdb->insert($table, ['identity_hash'=>$hash, 'provider'=>$provider, 'venue'=>$c['venue_id'], 'environment'=>$c['environment'],
                    'remote_id'=>$remoteId, 'snapshot'=>'{}', 'flow'=>'{}', 'updated_at'=>time()])) {
                    throw new Problem('inbox_failed', 'Orderhändelsen kunde inte sparas.', 503);
                }
                $id = (int) $wpdb->insert_id;
            } else { $id = (int) $record['id']; }
            // Same lock as worker: a notification arriving during a fetch cannot be lost.
            (new Lock())->named('external:' . $id, static function () use ($wpdb, $table, $id) {
                if ($wpdb->update($table, ['pending'=>1, 'attempts'=>0], ['id'=>$id]) === false) { throw new Problem('inbox_failed', 'Orderhändelsen kunde inte köas.', 503); }
            });
            self::schedule($id);
        });
    }
    public static function record(int $id): array {
        global $wpdb; $table = self::table();
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id=%d", abs($id)), ARRAY_A);
        if (!$row) { throw new Problem('not_found', 'Den externa ordern finns inte.', 404); }
        return $row;
    }
    public static function process(int $id): void {
        try {
            (new Lock())->named('external:' . $id, static function () use ($id) {
                global $wpdb; $r = self::record($id);
                if (!(int) $r['pending']) { return; }
                $c = Connections::get($r['provider']);
                if (!$c['enabled'] || $c['environment'] !== $r['environment'] || $c['venue_id'] !== $r['venue']) {
                    throw new Problem('connection_changed', 'Anslutningen är avstängd eller bytt. Kontrollera integrationen.', 409);
                }
                $raw = ProviderApi::order($r['provider'], $r['remote_id']);
                $snapshot = self::normalize($r['provider'], $raw, $id);
                $old = json_decode($r['flow'], true) ?: null;
                if ($old && $old['state'] === 'archived' && ($old['reason'] ?? '') === 'handed_over') {
                    $snapshot['woo_status'] = 'completed'; // A stale remote event cannot reopen a locally handed-over ticket.
                }
                $flow = Workflow::reconcile($old, $snapshot, ['processing'], time());
                if (!$flow) { // Retain first-seen cancellations as history, not active cards.
                    $seed = $snapshot; $seed['woo_status'] = 'processing';
                    $flow = Workflow::reconcile(null, $seed, ['processing'], time());
                    $flow = Workflow::reconcile($flow, $snapshot, ['processing'], time());
                }
                if ($snapshot['remote_ready'] && $flow['state'] === 'preparing' && !$flow['changed']) {
                    $flow['state'] = 'ready'; $flow['ready_at'] = time(); $flow['revision']++;
                }
                self::persist($id, $snapshot, $flow, ['pending'=>0, 'attempts'=>0, 'error_code'=>'', 'updated_at'=>time()]);
                Connections::status($r['provider'], 'order_received');
                BizPrint::maybeAuto(-$id);
            });
        } catch (\Throwable $e) {
            global $wpdb;
            $code = $e instanceof Problem ? $e->slug : 'processing_failed';
            $table = self::table();
            $wpdb->query($wpdb->prepare("UPDATE $table SET attempts=attempts+1,error_code=%s WHERE id=%d", $code, $id));
            $r = self::record($id);
            if ((int) $r['attempts'] < 8) { self::schedule($id, min(900, 15 * (2 ** (int) $r['attempts']))); }
        }
    }
    public static function persist(int $id, array $snapshot, array $flow, array $extra = []): void {
        global $wpdb;
        $data = array_merge(['snapshot'=>wp_json_encode($snapshot), 'flow'=>wp_json_encode($flow), 'state'=>$flow['state'], 'closed_at'=>$flow['closed_at']], $extra);
        if ($wpdb->update(self::table(), $data, ['id'=>abs($id)]) === false) { throw new Problem('external_save', 'Orderns köksstatus kunde inte sparas.', 503); }
    }
    public static function view(array $r): ?array {
        $snapshot = json_decode($r['snapshot'], true); $f = json_decode($r['flow'], true);
        if (empty($snapshot['id']) || empty($f['state'])) { return null; }
        return array_merge($snapshot, ['state'=>$f['state'], 'revision'=>$f['revision'], 'token'=>$f['snapshot_hash'], 'changed'=>$f['changed'],
            'reason'=>$f['reason'], 'started_at'=>$f['started_at'], 'ready_at'=>$f['ready_at'], 'closed_at'=>$f['closed_at'], 'can_restore'=>false,
            'integration_error'=>$r['error_code'] !== '', 'integration_pending'=>(bool) $r['pending']]);
    }
    public static function board(): array {
        global $wpdb; $table = self::table();
        $records = $wpdb->get_results("SELECT * FROM $table WHERE state != 'archived' ORDER BY id ASC LIMIT 501", ARRAY_A);
        if ($wpdb->last_error) { throw new Problem('external_read', 'Externa ordrar kunde inte läsas.', 503); }
        $rows = []; $issues = [];
        foreach ($records as $r) {
            $row = self::view($r);
            if ($row) { $rows[] = $row; }
            if ($r['error_code'] || !$row || $r['pending']) { $issues[] = ['id'=>-(int) $r['id'], 'code'=>$r['error_code'] ?: 'external_pending']; }
        }
        if (count($records) > 500) { $issues[] = ['id'=>0, 'code'=>'external_capacity']; }
        return ['orders'=>$rows, 'issues'=>$issues];
    }
    public static function archive(string $range, string $search): array {
        global $wpdb; $table = self::table(); [$from, $to] = Orders::range($range);
        $records = $wpdb->get_results($wpdb->prepare("SELECT * FROM $table WHERE state='archived' AND closed_at BETWEEN %d AND %d ORDER BY closed_at DESC LIMIT 501", $from, $to), ARRAY_A);
        if ($wpdb->last_error) { throw new Problem('external_read', 'Extern historik kunde inte läsas.', 503); }
        $rows = [];
        foreach ($records as $r) {
            $row = self::view($r);
            if ($row && ($search === '' || stripos(Snapshot::searchText($row), $search) !== false)) { $rows[] = $row; }
        }
        return ['orders'=>array_slice($rows, 0, 500), 'truncated'=>count($records) > 500];
    }
    public static function change(int $id, array $input, int $user): array {
        return (new Lock())->named('external:' . abs($id), static function () use ($id, $input, $user) {
            $r = self::record($id); $row = self::view($r);
            if (!$row || $r['pending'] || $r['error_code']) { throw new Problem('external_unsynced', 'Ordern väntar på synkronisering. Försök igen när anslutningen är återställd.', 409); }
            $c = Connections::get($r['provider']);
            if (!$c['enabled'] || $c['environment'] !== $r['environment'] || $c['venue_id'] !== $r['venue']) { throw new Problem('connection_changed', 'Återanslut rätt restaurang innan ordern ändras.', 409); }
            if (($input['action'] ?? '') === 'restore') { throw new Problem('external_restore', 'Återöppna ordern hos leveranstjänsten.', 409); }
            $snapshot = json_decode($r['snapshot'], true); $flow = json_decode($r['flow'], true);
            $result = Workflow::command($flow, $snapshot, $input, ['processing'], time(), $user);
            if (!$result['replayed'] && $input['action'] === 'ready') {
                if (!$snapshot['accepted']) { throw new Problem('accept_in_provider', 'Acceptera ordern i tjänstens handlarapp innan den klarmarkeras.', 409); }
                ProviderApi::ready($r['provider'], $r['remote_id']);
            }
            if (!$result['replayed'] && $input['action'] === 'handover') {
                $snapshot['woo_status'] = 'completed'; $snapshot['woo_status_label'] = 'Utlämnad i KDS';
                $result['flow']['snapshot_hash'] = Workflow::hash($snapshot);
            }
            self::persist(abs($id), $snapshot, $result['flow']);
            return ['order'=>self::view(self::record($id)), 'replayed'=>$result['replayed']];
        });
    }
    public static function maintenance(): void {
        global $wpdb; $table = self::table();
        foreach ($wpdb->get_col("SELECT id FROM $table WHERE provider='wolt' AND ((pending=1 AND attempts<8) OR (state<>'archived' AND pending=0 AND updated_at<" . (time()-300) . ")) ORDER BY updated_at ASC LIMIT 50") as $id) {
            try { (new Lock())->named('external:' . $id, static function () use ($wpdb, $table, $id) {
                $wpdb->update($table, ['pending'=>1], ['id'=>$id]); self::schedule((int) $id);
            }); } catch (Problem $e) { /* A worker already owns this ticket. Next maintenance retries. */ }
        }
        // Keep only the minimum operational history; no raw API payloads or customer contact information.
        $wpdb->query($wpdb->prepare("DELETE FROM $table WHERE state='archived' AND closed_at>0 AND closed_at<%d", time() - 90 * DAY_IN_SECONDS));
    }
    public static function retry(): int {
        global $wpdb; $table = self::table();
        $ids = $wpdb->get_col("SELECT id FROM $table WHERE provider='wolt' AND (pending=1 OR error_code<>'') LIMIT 50");
        foreach ($ids as $id) { $wpdb->update($table, ['pending'=>1,'attempts'=>0,'error_code'=>''], ['id'=>$id]); self::schedule((int) $id); }
        return count($ids);
    }
    public static function normalize(string $provider, array $data, int $id): array {
        if ($provider === 'foodora') { return FoodoraRestaurant::normalize($data, $id); }
        $wolt = $provider === 'wolt';
        $remoteId = Snapshot::text($wolt ? ($data['id'] ?? '') : ($data['order_id'] ?? ''));
        $status = strtoupper(Snapshot::text($wolt ? ($data['order_status'] ?? '') : ($data['status'] ?? '')));
        $known = $wolt ? ['RECEIVED','FETCHED','ACKNOWLEDGED','PRODUCTION','READY','DELIVERED','REJECTED'] : ['RECEIVED','READY_FOR_PICKUP','DISPATCHED','CANCELLED','CANCELED'];
        if ($remoteId === '' || !in_array($status, $known, true) || !is_array($data['items'] ?? null) || count($data['items']) > 300) {
            throw new Problem('external_schema', 'Orderformatet eller statusen stöds inte. Kontrollera ordern i leveranstjänsten.', 422);
        }
        $items = [];
        foreach ($data['items'] as $index => $item) {
            if (!$wolt && in_array($item['status'] ?? '', ['REMOVED','NOT_FOUND','REPLACED'], true)) { continue; }
            $quantity = $wolt ? ($item['count'] ?? null) : ($item['pricing']['quantity'] ?? null);
            $name = Snapshot::text($item['name'] ?? '');
            if ($name === '' || !is_numeric($quantity) || (float) $quantity <= 0 || (float) $quantity > 10000) { throw new Problem('external_items', 'En orderrad saknar namn eller giltigt antal.', 422); }
            $details = []; $warnings = [];
            if (isset($item['options']) && !is_array($item['options'])) { throw new Problem('external_options', 'Orderns tillval kunde inte läsas.', 422); }
            if (!empty($item['is_bundle_offer'])) { $warnings[] = 'Paketerbjudande: kontrollera delarna i Wolt innan tillagning.'; }
            if (!$wolt && ($item['pricing']['pricing_type'] ?? 'UNIT') !== 'UNIT') { $warnings[] = 'Viktvara: kontrollera mängd och instruktioner i Foodora.'; }
            foreach ($item['options'] ?? [] as $option) {
                if (!is_array($option) || !is_scalar($option['value'] ?? null)) { $warnings[] = 'Ett tillval kunde inte läsas. Kontrollera originalordern.'; continue; }
                $label = Snapshot::text($option['name'] ?? 'Tillval'); $value = Snapshot::text($option['value']);
                $count = $option['count'] ?? 1;
                $details[] = ['label'=>$label, 'value'=>($count != 1 ? Snapshot::text($count) . ' × ' : '') . $value];
            }
            if (!empty($item['instructions'])) { $details[] = ['label'=>'Instruktion', 'value'=>Snapshot::text($item['instructions'])]; }
            $items[] = ['id'=>$index+1,'name'=>$name,'quantity'=>(string) $quantity,'product_id'=>0,'variation_id'=>0,'details'=>$details,'field_warnings'=>$warnings,'refunded_quantity'=>'0'];
        }
        $closed = in_array($status, ['DELIVERED','DISPATCHED','REJECTED','CANCELLED','CANCELED'], true);
        $cancelled = in_array($status, ['REJECTED','CANCELLED','CANCELED'], true);
        $created = strtotime($wolt ? ($data['created_at'] ?? '') : ($data['sys']['created_at'] ?? ''));
        if (!$created) { throw new Problem('external_created_at', 'Orderns skapandetid saknas.', 422); }
        $due = strtotime($wolt ? ($data['pickup_eta'] ?? '') : ($data['promised_for'] ?? ''));
        $delivery = $wolt ? ($data['delivery']['type'] ?? '') : ($data['order_type'] ?? '');
        return [
            'id'=>-abs($id), 'kind'=>'external', 'provider'=>$provider, 'external_id'=>$remoteId,
            'order_number'=>Snapshot::text($wolt ? ($data['order_number'] ?? $remoteId) : ($data['order_code'] ?? $remoteId)),
            'woo_status'=>$closed ? ($cancelled ? 'cancelled' : 'completed') : 'processing', 'woo_status_label'=>$status,
            'order_url'=>'', 'items'=>$items, 'note'=>Snapshot::text($wolt ? ($data['consumer_comment'] ?? '') : ($data['comment'] ?? '')),
            'shipping'=>[['id'=>0, 'label'=>in_array(strtolower($delivery), ['takeaway','pickup'], true) ? 'Hämtas av kund' : 'Extern leverans', 'method'=>'external']],
            'custom_fields'=>[], 'mode'=>'', 'channel'=>$provider, 'origin'=>'', 'table'=>'', 'currency'=>'', 'total'=>'', 'refunded_total'=>'0',
            'payment_recorded'=>false, 'created_at'=>$created ?: time(), 'due_at'=>$due ?: 0,
            'remote_ready'=>in_array($status, ['READY','READY_FOR_PICKUP'], true),
            'accepted'=>!$wolt || in_array($status, ['PRODUCTION','READY'], true),
        ];
    }
}
