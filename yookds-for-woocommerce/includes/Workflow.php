<?php
namespace YooKDS;

/**
 * Pure kitchen workflow. No WordPress globals, payment calls or copied orders.
 * Snapshot data always comes from the current WooCommerce order.
 */
final class Workflow {
    public const STATES = ['preparing', 'ready', 'blocked', 'archived'];
    public const ACTIONS = ['ready', 'handover', 'restore', 'acknowledge'];

    public static function hash(array $data): string {
        return hash('sha256', json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    public static function kitchenHash(array $order): string {
        return self::hash([
            $order['items'], $order['note'], $order['shipping'],
            $order['channel'], $order['origin'], $order['table'], $order['refunded_total'],
            $order['custom_fields'] ?? [], $order['mode'] ?? '',
        ]);
    }

    /** Woo status wins: closed orders leave the board; reopened orders return for review. */
    public static function reconcile(?array $old, array $order, array $accepted, int $now): ?array {
        $eligible = in_array($order['woo_status'], $accepted, true);
        $unreadable = (bool) array_filter($order['items'], static fn($item) => !empty($item['field_warnings']));
        if (!$old && !$eligible) {
            return null;
        }
        if ($old && (($old['schema'] ?? null) !== 1 || !in_array($old['state'] ?? '', self::STATES, true))) {
            throw new Problem('workflow_schema', 'Orderns KDS-data har ett okänt format. Kontakta administratören.', 422);
        }
        $flow = $old ?? [
            'schema' => 1, 'state' => 'preparing', 'revision' => 0,
            'started_at' => $now, 'ready_at' => 0, 'closed_at' => 0,
            'changed' => false, 'reason' => '', 'content_hash' => '',
            'snapshot_hash' => '', 'receipts' => [], 'events' => [],
        ];
        $contentHash = self::kitchenHash($order);
        $snapshotHash = self::hash($order);
        if (!$eligible) {
            if ($flow['state'] !== 'archived') {
                $flow['state'] = 'archived';
                $flow['closed_at'] = $now;
                $flow['reason'] = $order['woo_status'] . '_in_woocommerce';
            }
        } elseif (!$order['items'] || $unreadable) {
            $flow['state'] = 'blocked';
            $flow['reason'] = $unreadable ? 'unreadable_fields' : 'empty_order';
            $flow['ready_at'] = 0;
        } elseif ($flow['state'] === 'archived') {
            // Repairs alpha.1's KDS-only archiving and follows an explicit Woo reopening.
            $flow['state'] = 'preparing';
            $flow['ready_at'] = 0;
            $flow['closed_at'] = 0;
            $flow['changed'] = true;
            $flow['reason'] = 'open_in_woocommerce';
            // Old request receipts must not suppress a command in this new working cycle.
            $flow['receipts'] = [];
        } else {
            if (!$order['items']) {
                $flow['state'] = 'blocked';
                $flow['reason'] = !$order['items'] ? 'empty_order' : 'woocommerce_' . $order['woo_status'];
                $flow['ready_at'] = 0;
            } elseif ($flow['state'] === 'blocked') {
                $flow['state'] = 'preparing';
                $flow['changed'] = true;
                $flow['reason'] = 'reopened_in_woocommerce';
            } elseif ($old && $flow['content_hash'] !== $contentHash) {
                $flow['state'] = 'preparing';
                $flow['ready_at'] = 0;
                $flow['changed'] = true;
                $flow['reason'] = 'order_changed';
            }
        }
        $flow['content_hash'] = $contentHash;
        $flow['snapshot_hash'] = $snapshotHash;
        if (!$old || $flow !== $old) {
            $flow['revision']++;
            self::event($flow, $old ? 'sync' : 'received', $now, 0);
        }
        return $flow;
    }

    /** The HTTP service serializes commands per order before calling this. */
    public static function command(array $flow, array $order, array $input, array $accepted, int $now, int $user): array {
        $action = $input['action'] ?? '';
        $request = $input['request_id'] ?? '';
        if (!is_string($action) || !in_array($action, self::ACTIONS, true) ||
            !is_string($request) || !preg_match('/^[a-zA-Z0-9_-]{8,80}$/D', $request)) {
            throw new Problem('bad_command', 'Ogiltig åtgärd eller anropsidentitet.', 400);
        }
        foreach ($flow['receipts'] as $receipt) {
            if ($receipt['id'] === $request) {
                if ($receipt['action'] !== $action || $receipt['user'] !== $user) {
                    throw new Problem('request_reused', 'Anropsidentiteten har redan använts. Uppdatera vyn.');
                }
                return ['flow' => $flow, 'replayed' => true];
            }
        }
        if (!is_int($input['revision'] ?? null) || $input['revision'] !== $flow['revision'] ||
            !is_string($input['token'] ?? null) || !hash_equals($flow['snapshot_hash'], $input['token'])) {
            throw new Problem('stale_order', 'Ordern har ändrats på en annan skärm eller i WooCommerce. Läs den uppdaterade ordern och försök igen.');
        }
        $eligible = in_array($order['woo_status'], $accepted, true) && !empty($order['items']);
        if (!$eligible || array_filter($order['items'], static fn($item) => !empty($item['field_warnings']))) {
            throw new Problem('order_blocked', 'Ordern kan inte hanteras i köket med nuvarande WooCommerce-status. Återöppna den i WooCommerce vid behov.');
        }
        switch ($action) {
            case 'ready':
                if ($flow['state'] !== 'preparing' || $flow['changed']) {
                    throw new Problem('not_preparing', 'Bekräfta eventuella ändringar innan ordern klarmarkeras.');
                }
                $flow['state'] = 'ready';
                $flow['ready_at'] = $now;
                $flow['reason'] = '';
                break;
            case 'handover':
                if ($flow['state'] !== 'ready' || $flow['changed']) {
                    throw new Problem('not_ready', 'Bara en oförändrad, klarmarkerad order kan lämnas ut.');
                }
                $flow['state'] = 'archived';
                $flow['closed_at'] = $now;
                $flow['reason'] = 'handed_over';
                break;
            case 'restore':
                if (!in_array($flow['state'], ['ready', 'archived'], true)) {
                    throw new Problem('cannot_restore', 'Den här ordern kan inte återställas från nuvarande läge.');
                }
                $flow['state'] = 'preparing';
                $flow['ready_at'] = 0;
                $flow['closed_at'] = 0;
                $flow['changed'] = true;
                $flow['reason'] = 'restored';
                break;
            case 'acknowledge':
                if ($flow['state'] !== 'preparing' || !$flow['changed']) {
                    throw new Problem('nothing_to_acknowledge', 'Det finns ingen ändring att bekräfta.');
                }
                $flow['changed'] = false;
                $flow['reason'] = '';
                break;
        }
        $flow['revision']++;
        $flow['receipts'][] = ['id' => $request, 'action' => $action, 'user' => $user];
        $flow['receipts'] = array_slice($flow['receipts'], -20);
        self::event($flow, $action, $now, $user);
        return ['flow' => $flow, 'replayed' => false];
    }

    private static function event(array &$flow, string $type, int $now, int $user): void {
        $flow['events'][] = ['type' => $type, 'at' => $now, 'user' => $user];
        $flow['events'] = array_slice($flow['events'], -40);
    }
}
