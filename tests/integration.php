<?php
/**
 * REAL WordPress/WooCommerce smoke test. NOT run by the isolated test suite.
 * Run ONLY in a disposable staging store with outbound integrations disabled:
 * YOOKDS_INTEGRATION=1 wp eval-file tests/integration.php --user=<administrator-id>
 * Repeat in separate HPOS-on and legacy test stores. No storage-mode switching here.
 */
if (!defined('WP_CLI') || !WP_CLI || getenv('YOOKDS_INTEGRATION') !== '1') {
    throw new RuntimeException('Explicit WP-CLI test opt-in required.');
}
if (!in_array(wp_get_environment_type(), ['local','development','staging'], true)) {
    throw new RuntimeException('Refusing to run in a production environment.');
}
if (!class_exists('YooKDS\\Orders') || !YooKDS\Rest::allowed()) {
    throw new RuntimeException('Activate YooKDS and run as an administrator or WooCommerce manager.');
}
// A board read legitimately adds KDS metadata to open orders. Require an EMPTY,
// disposable shop so this fixture never mutates any pre-existing order.
if (wc_get_orders(['type'=>'shop_order','status'=>array_keys(wc_get_order_statuses()),'limit'=>1,'return'=>'ids'])) {
    throw new RuntimeException('Use an empty disposable WooCommerce test installation, not your working staging store.');
}
$oldSettings = get_option(YooKDS\Settings::KEY, '__not_set__');
$created = [];
$passed = 0;
$check = static function ($ok, $message) use (&$passed) {
    if (!$ok) { throw new RuntimeException($message); }
    $passed++; WP_CLI::log('PASS ' . $message);
};
$input = static fn($row, $action) => ['action'=>$action,'revision'=>$row['revision'],'token'=>$row['token'],'request_id'=>wp_generate_uuid4()];
// Never email a customer from this test fixture.
add_filter('woocommerce_email_enabled_customer_completed_order', '__return_false');
add_filter('woocommerce_email_enabled_customer_processing_order', '__return_false');
add_filter('woocommerce_email_enabled_new_order', '__return_false');
try {
    update_option(YooKDS\Settings::KEY, YooKDS\Settings::defaults(), false);
    $order = wc_create_order(['status'=>'processing','created_via'=>'yookds-integration-test']);
    if (is_wp_error($order)) { throw new RuntimeException('Cannot create test order.'); }
    $created[] = $order->get_id();
    $item = new WC_Order_Item_Product();
    $item->set_name('YOOKDS TEST — do not fulfil');
    $item->set_quantity(2); $item->set_subtotal('100.00'); $item->set_total('100.00');
    $item->add_meta_data('Tillval', 'Utan lök', true);
    $order->add_item($item); $order->set_currency('SEK'); $order->set_total('100.00');
    $order->set_customer_note('Automated staging fixture — no real customer.');
    $order->set_date_paid(time()); // Test record only: no gateway call and no real payment.
    $item->update_meta_data('_wapf_meta',['field1'=>['label'=>'Sås','value'=>'Vitlök','raw'=>'garlic']]);
    $item->save();
    $order->update_meta_data('_fixture_delivery','18:30');
    $settings=YooKDS\Settings::defaults();
    $settings['custom_fields']=[['scope'=>'order','key'=>'_fixture_delivery','label'=>'Leveranstid']];
    update_option(YooKDS\Settings::KEY,$settings,false);
    $order->update_meta_data('_nx_channel', 'table'); $order->update_meta_data('_nx_table', 'T-TEST'); $order->save();
    $service = new YooKDS\Orders();
    $board=$service->board();
    $check(count($board['orders'])===1 && $board['orders'][0]['id']===$order->get_id(), 'direct query receives existing open order without prebuilt KDS index');
    $first=$board['orders'][0];
    $check($first['order_number']===str_pad(substr((string)$order->get_id(),-4),4,'0',STR_PAD_LEFT), 'four-digit display number retains full order ID');
    $check($first['custom_fields'][0]['value']==='18:30','configured saved order metadata shown');
    $check(str_contains(json_encode($first['items']), 'Vitl'), 'saved WAPF label/value selection read');
    $check($first['state']==='preparing' && $first['table']==='T-TEST', 'receive real WC order with recorded table');
    $check($first['items'][0]['quantity']==='2' && str_contains(json_encode($first['items']), 'Utan'), 'preserve quantity and visible item metadata');
    $request = $input($first, 'ready');
    $ready = $service->change($order->get_id(), $request, get_current_user_id())['order'];
    $check($ready['state']==='ready' && (new WC_Order($order->get_id()))->get_status()==='processing', 'ready is not WC completed');
    $check($service->change($order->get_id(), $request, get_current_user_id())['replayed'], 'idempotent repeated command');
    $stale = false;
    try { $service->change($order->get_id(), $input($first,'ready'), get_current_user_id()); }
    catch (YooKDS\Problem $e) { $stale = $e->slug === 'stale_order'; }
    $check($stale, 'reject outdated second-screen version');
    $edit = new WC_Order($order->get_id()); $edit->set_customer_note('Updated staging note'); $edit->save();
    $changed = $service->sync($order->get_id());
    $check($changed['state']==='preparing' && $changed['changed'], 'external WC change resets readiness');
    $ack = $service->change($order->get_id(), $input($changed,'acknowledge'), get_current_user_id())['order'];
    $ready = $service->change($order->get_id(), $input($ack,'ready'), get_current_user_id())['order'];
    $history = $service->change($order->get_id(), $input($ready,'handover'), get_current_user_id())['order'];
    $check($history['state']==='archived' && (new WC_Order($order->get_id()))->get_status()==='completed', 'handover always completes the same WooCommerce order');
    $check($service->board()['orders']===[], 'completed order not active and no synthetic fallback rows');
    $found = $service->archive('today','T-TEST',1);
    $check(in_array($order->get_id(), array_column($found['orders'],'id'),true), 'history metadata query works with configured storage backend');
    $rejected=false;
    try { $service->change($order->get_id(),$input($history,'restore'),get_current_user_id()); }
    catch (YooKDS\Problem $e) { $rejected=true; }
    $check($rejected,'completed order cannot be restored only in KDS');
    $edit=new WC_Order($order->get_id()); $edit->update_status('processing');
    $restore=$service->sync($order->get_id());
    $check($restore['state']==='preparing' && $restore['changed'],'external Woo reopening returns original order for review');
    $edit = new WC_Order($order->get_id()); $edit->update_status('cancelled');
    $check($service->sync($order->get_id())['state']==='archived', 'cancellation closes kitchen work');
    $check($service->board()['orders']===[], 'cancelled order not active');
    WP_CLI::log('WP ' . get_bloginfo('version') . '; WC ' . WC_VERSION . '; HPOS ' . (Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled()?'ON':'OFF'));
    WP_CLI::success($passed . ' real integration smoke checks passed.');
} finally {
    // Delete only fixture orders created by this invocation; never touch pre-existing orders.
    foreach ($created as $id) { $fixture = wc_get_order($id); if ($fixture) { $fixture->delete(true); } }
    if ($oldSettings === '__not_set__') { delete_option(YooKDS\Settings::KEY); }
    else { update_option(YooKDS\Settings::KEY, $oldSettings, false); }
}
