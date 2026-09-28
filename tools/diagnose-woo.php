<?php
/**
 * YooKDS diagnostic: WP-CLI only; no order, metadata or option writes.
 * wp eval-file tools/diagnose-woo.php --user=<administrator>
 * Optional: YOOKDS_ORDER_ID=<full Woo ID> (NOT the four-digit display number).
 * Do not publish the output; it contains operational installation details.
 */
if (!defined('WP_CLI') || !WP_CLI || !defined('ABSPATH')) {
    fwrite(STDERR, "Run inside WordPress through WP-CLI.\n");
    exit(1);
}
if (!current_user_can('manage_woocommerce')) {
    WP_CLI::error('Run as a WooCommerce manager or administrator.');
}
$report = [
    'diagnostic_version' => 1,
    'utc' => gmdate('c'),
    'environment' => wp_get_environment_type(),
    'wordpress_version' => get_bloginfo('version'),
    'woocommerce_version' => defined('WC_VERSION') ? WC_VERSION : null,
    'yookds_version' => defined('YOOKDS_VERSION') ? YOOKDS_VERSION : null,
    'php_version' => PHP_VERSION,
    'legacy_markers' => [],
    'legacy_functions' => [],
    'capabilities' => [],
    'routes' => [],
];
foreach (['YOOKDS_SNIPPET_LOADED', 'YOOKDS_ALL_IN_ONE_V32', 'YOOKDS_ARCHIVE_PATCH_V1', 'YOOKDS_HARD_BRIDGE_33'] as $name) {
    $report['legacy_markers'][$name] = defined($name);
}
foreach (['kdsu_sync_ticket_from_order', 'kdsu_unified_del2_logic', 'yookds_controls_bridge_v32'] as $name) {
    $report['legacy_functions'][$name] = function_exists($name);
}
foreach (['manage_woocommerce', 'edit_shop_orders', 'edit_others_shop_orders'] as $cap) {
    $report['capabilities'][$cap] = current_user_can($cap);
}
if (function_exists('rest_get_server')) {
    foreach (array_keys(rest_get_server()->get_routes()) as $route) {
        if (strpos($route, '/yookds/v1') === 0) { $report['routes'][] = $route; }
    }
}
$util = '\\Automattic\\WooCommerce\\Utilities\\OrderUtil';
$report['hpos'] = class_exists($util) ? $util::custom_orders_table_usage_is_enabled() : null;
if (function_exists('wc_get_order_statuses') && function_exists('wc_get_orders')) {
    $report['registered_status_counts'] = [];
    foreach (array_keys(wc_get_order_statuses()) as $status) {
        try {
            $result = wc_get_orders(['type' => 'shop_order', 'status' => [$status], 'limit' => 1, 'return' => 'ids', 'paginate' => true]);
            $report['registered_status_counts'][$status] = is_object($result) && isset($result->total) ? (int) $result->total : 'unexpected_result';
        } catch (Throwable $e) {
            $report['registered_status_counts'][$status] = ['error_class' => get_class($e)];
        }
    }
    if (class_exists('YooKDS\\Settings')) {
        $report['kds_receive_statuses'] = \YooKDS\Settings::get()['receive_statuses'];
    }
    $id = getenv('YOOKDS_ORDER_ID');
    if ($id !== false && $id !== '') {
        if (!preg_match('/^[1-9][0-9]*$/D', $id)) { WP_CLI::error('YOOKDS_ORDER_ID must be the full positive WooCommerce ID.'); }
        $order = wc_get_order((int) $id);
        $report['selected_order'] = ['requested_full_id' => (int) $id, 'exists' => (bool) $order];
        if ($order && $order instanceof WC_Order) {
            $flow = $order->get_meta('_yookds_workflow', true, 'edit');
            $report['selected_order'] += [
                'woo_status' => $order->get_status('edit'),
                'line_item_count' => count($order->get_items('line_item')),
                'kds_workflow_type' => gettype($flow),
                'kds_workflow_present' => $flow !== '',
                'nx_channel_present' => $order->get_meta('_nx_channel', true, 'edit') !== '',
                'nx_table_present' => $order->get_meta('_nx_table', true, 'edit') !== '',
            ];
        }
    }
}
// Test only a random, short-lived connection lock; never lock a customer order.
global $wpdb;
if (isset($wpdb)) {
    $name = 'yookds-diag:' . wp_generate_uuid4();
    $old = $wpdb->suppress_errors(true);
    $acquired = false;
    try {
        $result = $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 0)', $name));
        $acquired = (string) $result === '1';
        $report['database_lock_available'] = $acquired;
    } finally {
        if ($acquired) { $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $name)); }
        $wpdb->suppress_errors($old);
    }
}
WP_CLI::line(wp_json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
