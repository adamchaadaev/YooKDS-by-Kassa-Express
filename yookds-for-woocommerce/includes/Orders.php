<?php
namespace YooKDS;
if (!defined('ABSPATH')) { exit; }

/** WC CRUD is the sole order storage interface. Additional meta fields are rebuildable indexes. */
final class Orders {
    public const FLOW = '_yookds_workflow';
    private Lock $lock;
    private array $queued = [];

    public function __construct(?Lock $lock = null) { $this->lock = $lock ?? new Lock(); }

    public function init(): void {
        add_filter('woocommerce_order_data_store_cpt_get_orders_query', [self::class, 'legacyQuery'], 10, 2);
        add_action('woocommerce_after_order_object_save', function ($order) {
            if ($order instanceof \WC_Order && !($order instanceof \WC_Order_Refund)) { $this->queue($order->get_id()); }
        });
        add_action('woocommerce_after_order_item_object_save', function ($item) { $this->queue($item->get_order_id()); });
        add_action('woocommerce_before_delete_order_item', function ($itemId) {
            $this->queue(wc_get_order_id_by_order_item_id($itemId));
        });
        add_action('woocommerce_order_refunded', [$this, 'queue']);
        add_action('shutdown', function () {
            foreach (array_keys($this->queued) as $id) {
                try { $this->sync((int) $id); }
                catch (\Throwable $error) {
                    // Do not break checkout and do not log order content or credentials.
                    wc_get_logger()->warning('Deferred KDS reconciliation failed; active-board reads retry it.', ['source' => 'yookds']);
                }
            }
        }, 20);
    }

    public function queue($id): void { if ((int) $id > 0) { $this->queued[(int) $id] = true; } }

    public static function legacyQuery(array $query, array $vars): array {
        if (isset($vars['yookds_meta_query'])) {
            $query['meta_query'] = $vars['yookds_meta_query'];
        }
        return $query;
    }

    public static function queryArgs(array $meta, array $args = []): array {
        $base = ['type' => 'shop_order', 'status' => array_keys(wc_get_order_statuses()), 'return' => 'ids', 'limit' => 100, 'orderby' => 'date', 'order' => 'ASC'];
        $base = array_replace($base, $args);
        if (\Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled()) {
            $base['meta_query'] = $meta;
        } else {
            $base['yookds_meta_query'] = $meta;
        }
        return $base;
    }

    private function load(int $id): \WC_Order {
        $candidate = wc_get_order($id);
        if (!$candidate || $candidate->get_type() !== 'shop_order' || $candidate instanceof \WC_Order_Refund) {
            throw new Problem('not_found', 'Ordern finns inte längre.', 404);
        }
        $order = new \WC_Order($id);
        $order->read_meta_data(true);
        return $order;
    }

    private function readFlow(\WC_Order $order): ?array {
        $value = $order->get_meta(self::FLOW, true, 'edit');
        if ($value === '') { return null; }
        if (!is_array($value)) { throw new Problem('invalid_workflow', 'Orderns KDS-data behöver kontrolleras.', 422); }
        return $value;
    }

    private function persist(\WC_Order $order, array $flow, array $snapshot): void {
        $indexes = [
            self::FLOW => $flow, '_yookds_state' => $flow['state'],
            '_yookds_closed_at' => $flow['closed_at'], '_yookds_search' => Snapshot::searchText($snapshot),
        ];
        $dirty = false;
        foreach ($indexes as $key => $value) {
            if ($order->get_meta($key, true, 'edit') != $value) {
                $order->update_meta_data($key, $value);
                $dirty = true;
            }
        }
        if (!$dirty) { return; }
        $order->save_meta_data();
        $order->read_meta_data(true);
        foreach ($indexes as $key => $value) {
            if ($order->get_meta($key, true, 'edit') != $value) {
                throw new Problem('save_failed', 'Servern kunde inte bekräfta sparningen. Uppdatera vyn innan du försöker igen.', 503);
            }
        }
    }

    private function view(array $snapshot, array $flow): array {
        return array_merge($snapshot, [
            'state' => $flow['state'], 'revision' => $flow['revision'], 'token' => $flow['snapshot_hash'],
            'changed' => $flow['changed'], 'reason' => $flow['reason'],
            'started_at' => $flow['started_at'], 'ready_at' => $flow['ready_at'], 'closed_at' => $flow['closed_at'],
            'can_restore' => in_array($snapshot['woo_status'], Settings::get()['receive_statuses'], true) && !empty($snapshot['items']),
        ]);
    }

    public function sync(int $id): ?array {
        return $this->lock->run($id, function () use ($id) {
            $order = $this->load($id);
            $snapshot = Snapshot::fromOrder($order);
            $flow = Workflow::reconcile($this->readFlow($order), $snapshot, Settings::get()['receive_statuses'], time());
            if (!$flow) { return null; }
            $this->persist($order, $flow, $snapshot);
            return $this->view($snapshot, $flow);
        });
    }

    public function change(int $id, array $input, int $user): array {
        return $this->lock->run($id, function () use ($id, $input, $user) {
            $settings = Settings::get();
            $order = $this->load($id);
            $snapshot = Snapshot::fromOrder($order);
            $flow = Workflow::reconcile($this->readFlow($order), $snapshot, $settings['receive_statuses'], time());
            if (!$flow) { throw new Problem('not_received', 'Ordern ingår inte i köksflödet.'); }
            // Persist externally changed data even when the client's subsequent command is stale.
            $this->persist($order, $flow, $snapshot);
            $result = Workflow::command($flow, $snapshot, $input, $settings['receive_statuses'], time(), $user);
            $flow = $result['flow'];
            if (!$result['replayed'] && $input['action'] === 'handover') {
                if (!$snapshot['payment_recorded'] && ($input['confirm_unpaid'] ?? false) !== true) {
                    throw new Problem('payment_confirmation_required', 'Betalningen är inte bekräftad i WooCommerce. Bekräfta utlämningen uttryckligen; KDS debiterar inte kunden.', 409);
                }
                // Required side effect: use WC CRUD so normal completion hooks and emails run.
                // Do not persist the planned archived state if WooCommerce rejects the write.
                if (!$order->update_status('completed', 'YooKDS: utlämnad och färdigbehandlad.')) {
                    throw new Problem('completion_failed', 'WooCommerce kunde inte spara Färdigbehandlad. Ordern är inte kvitterad som utlämnad i KDS.', 503);
                }
                $order = $this->load($id);
                if ($order->get_status('edit') !== 'completed') {
                    throw new Problem('completion_not_saved', 'WooCommerce bekräftade inte Färdigbehandlad. Uppdatera vyn och kontrollera ordern.', 503);
                }
                $snapshot = Snapshot::fromOrder($order);
                $flow = Workflow::reconcile($flow, $snapshot, $settings['receive_statuses'], time());
            }
            $this->persist($order, $flow, $snapshot);
            return ['order' => $this->view($snapshot, $flow), 'replayed' => $result['replayed']];
        });
    }

    /** Response identity helps detect a cached API response or a different installation. */
    public static function source(): array {
        return ['source' => 'woocommerce', 'schema' => 2, 'site' => home_url('/'), 'version' => YOOKDS_VERSION];
    }

    /**
     * WooCommerce status is authoritative, not the old _yookds_state index.
     * This also picks up existing open orders and orders previously archived only in alpha.1.
     * No date cutoff, seed data, separate tickets, demo fallback or order creation.
     */
    public function board(): array {
        $settings = Settings::get();
        if (!$settings['receive_statuses']) {
            throw new Problem('no_open_statuses', 'Inga öppna WooCommerce-statusar är konfigurerade. Kontrollera tilläggets statusfilter.', 503);
        }
        $ids = wc_get_orders([
            'type' => 'shop_order', 'status' => $settings['receive_statuses'],
            'return' => 'ids', 'limit' => 501, 'orderby' => 'ID', 'order' => 'ASC',
        ]);
        if (!is_array($ids)) { throw new Problem('orders_query_failed', 'WooCommerce gav inget giltigt ordersvar.', 503); }
        $ids = array_values(array_unique(array_map('intval', $ids)));
        if (count($ids) > 500) {
            throw new Problem('capacity', 'Det finns fler än 500 öppna WooCommerce-ordrar. Vyn är inte komplett; hantera äldre öppna ordrar i WooCommerce först.', 503);
        }
        $rows = []; $issues = [];
        foreach ($ids as $id) {
            try { $row = $this->sync($id); }
            catch (\Throwable $error) {
                if ($error instanceof Problem && $error->status === 404) { continue; }
                $issues[] = ['id'=>$id, 'code'=>$error instanceof Problem ? $error->slug : 'unreadable_order'];
                continue;
            }
            // Recheck after loading: the status may change between query and snapshot.
            if ($row && in_array($row['woo_status'], $settings['receive_statuses'], true) &&
                in_array($row['state'], ['preparing', 'ready', 'blocked'], true)) {
                $rows[] = $row;
            }
        }
        usort($rows, static fn($a, $b) => ($a['created_at'] <=> $b['created_at']) ?: ($a['id'] <=> $b['id']));
        return array_merge(self::source(), [
            'orders' => $rows, 'server_time' => time(), 'poll_seconds' => $settings['poll_seconds'],
            'backfill_remaining' => 0, 'issues'=>$issues, 'complete'=>!$issues,
        ]);
    }

    /** Catch externally closed orders that did not trigger the normal save hooks. */
    private function reconcileClosed(): void {
        $closed = array_values(array_diff(array_map(static fn($s) => preg_replace('/^wc-/', '', $s),
            array_keys(wc_get_order_statuses())), Settings::openStatuses()));
        if (!$closed) { return; }
        $ids = wc_get_orders(self::queryArgs([
            ['key' => '_yookds_state', 'value' => ['preparing', 'ready', 'blocked'], 'compare' => 'IN'],
        ], ['status' => $closed, 'limit' => 500]));
        foreach ($ids as $id) {
            try { $this->sync((int) $id); }
            catch (Problem $error) { if ($error->status !== 404) { throw $error; } }
        }
    }

    /** Range is archive time in the site's timezone, not the first visible page's creation date. */
    public static function range(string $range, ?\DateTimeImmutable $now = null): array {
        $now = $now ?? new \DateTimeImmutable('now', wp_timezone());
        $today = $now->setTime(0, 0);
        switch ($range) {
            case 'today': return [$today->getTimestamp(), $today->modify('+1 day')->getTimestamp() - 1];
            case 'yesterday': return [$today->modify('-1 day')->getTimestamp(), $today->getTimestamp() - 1];
            case 'week': return [$today->modify('-6 days')->getTimestamp(), $now->getTimestamp()];
            case 'month': return [$today->modify('first day of this month')->getTimestamp(), $now->getTimestamp()];
            default: throw new Problem('invalid_range', 'Ogiltig historikperiod.', 400);
        }
    }

    public function archive(string $range, string $q, int $page): array {
        $this->reconcileClosed();
        [$from, $to] = self::range($range);
        $meta = [
            ['key' => '_yookds_state', 'value' => 'archived'],
            ['key' => '_yookds_closed_at', 'value' => [$from, $to], 'compare' => 'BETWEEN', 'type' => 'NUMERIC'],
        ];
        if ($q !== '') { $meta[] = ['key' => '_yookds_search', 'value' => $q, 'compare' => 'LIKE']; }
        $args = ['limit' => 24, 'paged' => $page, 'paginate' => true, 'order' => 'DESC',
            'status' => array_values(array_diff(array_keys(wc_get_order_statuses()), array_map(static fn($s) => 'wc-' . $s, Settings::openStatuses())))];
        $found = wc_get_orders(self::queryArgs($meta, $args));
        $max = max(1, (int) $found->max_num_pages);
        if ($page > $max) {
            $page = $max;
            $found = wc_get_orders(self::queryArgs($meta, array_replace($args, ['paged' => $page])));
        }
        $rows = [];
        foreach ($found->orders as $id) {
            try { $row = $this->sync((int) $id); }
            catch (Problem $error) { if ($error->status === 404) { continue; } throw $error; }
            if ($row && $row['state'] === 'archived') { $rows[] = $row; }
        }
        return array_merge(self::source(), ['orders' => $rows, 'page' => $page, 'max_pages' => $max, 'total' => (int) $found->total, 'server_time' => time()]);
    }
}
