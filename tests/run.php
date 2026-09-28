<?php
/** Isolated tests. Test doubles below are NOT an installed WordPress/WooCommerce environment. */
declare(strict_types=1);
define('ABSPATH', __DIR__ . '/');
define('DB_NAME', 'yookds_test');
define('YOOKDS_VERSION', '1.1.0-alpha.2');
define('HOUR_IN_SECONDS',3600);
$base = dirname(__DIR__) . '/yookds-for-woocommerce/includes/';
foreach (['Problem', 'Workflow', 'Settings', 'Lock', 'Snapshot', 'Orders', 'Rest', 'Fields', 'Numbering', 'NX'] as $class) require $base . $class . '.php';
$passed = 0;
function check(bool $ok, string $name): void { global $passed; if (!$ok) { throw new RuntimeException('FAIL: ' . $name); } $passed++; echo "PASS $name\n"; }
function rejects(callable $callback, string $code, string $name): void {
    try { $callback(); } catch (YooKDS\Problem $e) { check($e->slug === $code, $name); return; }
    throw new RuntimeException('FAIL (no exception): ' . $name);
}
function get_option($key, $default = []) { return $GLOBALS['test_options'][$key] ?? $default; }
function wp_timezone() { return new DateTimeZone('Europe/Stockholm'); }
function wc_get_order_statuses() { return ['wc-pending'=>'Pending','wc-processing'=>'Processing','wc-on-hold'=>'On hold','wc-completed'=>'Completed','wc-cancelled'=>'Cancelled','wc-refunded'=>'Refunded','wc-failed'=>'Failed','wc-accepted'=>'Accepted']; }
function home_url($path='/') { return 'https://test.invalid'.$path; }
function wc_get_order_status_name($status) { return wc_get_order_statuses()['wc-'.$status]??$status; }
function esc_url_raw($url) { return $url; }
function sanitize_text_field($s) {return trim(strip_tags($s));}
function sanitize_key($s) {return preg_replace('/[^a-z0-9_\-]/','',strtolower($s));}
function wp_unslash($s) {return stripslashes($s);}
function wp_strip_all_tags($value) { return strip_tags($value); }
function apply_filters($name, $value, ...$args) { return isset($GLOBALS['test_filters'][$name]) ? $GLOBALS['test_filters'][$name]($value, ...$args) : $value; }
function is_user_logged_in() { return $GLOBALS['logged_in'] ?? false; }
function current_user_can($cap) { return in_array($cap, $GLOBALS['caps'] ?? [], true); }
eval('namespace Automattic\\WooCommerce\\Utilities; class OrderUtil { public static bool $hpos = true; public static function custom_orders_table_usage_is_enabled() { return self::$hpos; } }');
use YooKDS\Workflow as W;
use YooKDS\Orders;
use YooKDS\Settings;
use YooKDS\Snapshot;

$order = [
    'id'=>123, 'order_number'=>'KE-123', 'woo_status'=>'processing',
    'items'=>[['id'=>99, 'name'=>'Kebabtallrik', 'quantity'=>'2', 'product_id'=>4, 'variation_id'=>7, 'details'=>[['label'=>'Lök','value'=>'Utan lök']], 'refunded_quantity'=>'0']],
    'note'=>'Ring inte', 'shipping'=>[['id'=>12,'label'=>'Hämtning','method'=>'local_pickup']],
    'channel'=>'table', 'origin'=>'1', 'table'=>'18', 'currency'=>'SEK','total'=>'200.00','refunded_total'=>'0', 'payment_recorded'=>true, 'created_at'=>100,
];
function cmd(array $flow, array $order, string $action, string $id, int $now = 110): array {
    return W::command($flow, $order, ['action'=>$action,'request_id'=>$id,'revision'=>$flow['revision'],'token'=>$flow['snapshot_hash']], ['processing'], $now, 1);
}
$f = W::reconcile(null,$order,['processing'],100);
check($f['state']==='preparing' && $f['revision']===1,'enrol processing order once');
check(W::reconcile($f,$order,['processing'],101)===$f,'unchanged poll does not change revision or timestamps');
check(W::reconcile(null,array_replace($order,['woo_status'=>'pending']),['processing'],101)===null,'unpaid pending order not enrolled');
$empty = W::reconcile(null,array_replace($order,['items'=>[]]),['processing'],101);
check($empty['state']==='blocked','empty processing order blocked instead of starving backfill');
$r = cmd($f,$order,'ready','request-ready-1')['flow'];
check($r['state']==='ready' && $r['ready_at']===110,'ready transition records time');
check($order['woo_status']==='processing' && $order['total']==='200.00','workflow leaves commercial order untouched');
$replay = W::command($r,$order,['action'=>'ready','request_id'=>'request-ready-1','revision'=>1,'token'=>$f['snapshot_hash']],['processing'],999,1);
check($replay['replayed'] && $replay['flow']===$r,'same idempotency key replays without side effects');
rejects(fn()=>W::command($r,$order,['action'=>'handover','request_id'=>'request-ready-1'],['processing'],111,1),'request_reused','key cannot be reused for another action');
rejects(fn()=>W::command($r,$order,['action'=>'ready','request_id'=>'request-ready-1'],['processing'],111,2),'request_reused','key cannot be reused by another user');
rejects(fn()=>W::command($r,$order,['action'=>'ready','request_id'=>'second-screen','revision'=>1,'token'=>$f['snapshot_hash']],['processing'],111,1),'stale_order','second screen stale revision rejected');
rejects(fn()=>W::command($r,$order,['action'=>'handover','request_id'=>'wrong-token-1','revision'=>$r['revision'],'token'=>'wrong'],['processing'],111,1),'stale_order','mismatched content token rejected');
rejects(fn()=>W::command($f,$order,['action'=>'ready','request_id'=>'invalid#id'],['processing'],111,1),'bad_command','invalid request id rejected');
rejects(fn()=>W::command($f,$order,['action'=>'delete','request_id'=>'request-delete'],['processing'],111,1),'bad_command','arbitrary command rejected');
$mutated=$order; $mutated['items'][0]['quantity']='3';
$changed=W::reconcile($r,$mutated,['processing'],120);
check($changed['state']==='preparing' && $changed['changed'] && !$changed['ready_at'],'quantity change resets ready with acknowledgement');
rejects(fn()=>cmd($changed,$mutated,'ready','changed-ready'),'not_preparing','cannot cook from unacknowledged changes');
$ack=cmd($changed,$mutated,'acknowledge','acknowledge-1')['flow'];
check(!$ack['changed'] && cmd($ack,$mutated,'ready','ready-after-ack')['flow']['state']==='ready','acknowledgement permits readiness');
$note=$order; $note['note']='Allergi: nötter';
check(W::reconcile($r,$note,['processing'],120)['changed'],'customer note change flagged');
$addon=$order; $addon['items'][0]['details'][0]['value']='Med lök';
check(W::reconcile($r,$addon,['processing'],120)['state']==='preparing','add-on change resets readiness');
$partial=$order; $partial['refunded_total']='10.00'; $partial['items'][0]['refunded_quantity']='1';
$pf=W::reconcile($r,$partial,['processing'],120);
check($pf['state']==='preparing' && $pf['changed'] && $partial['items'][0]['quantity']==='2','partial refund requests review, not full cancellation or rewritten quantity');
$price=$order; $price['total']='180.00';
$pr=W::reconcile($r,$price,['processing'],120);
check($pr['state']==='ready' && !$pr['changed'] && $pr['revision']>$r['revision'],'price-only edit changes token but not kitchen readiness');
foreach (['cancelled','failed','refunded','pending','on-hold'] as $status) {
    $blockedOrder=array_replace($order,['woo_status'=>$status]); $blocked=W::reconcile($r,$blockedOrder,['processing'],130);
    check($blocked['state']==='archived',"$status excluded by accepted statuses closes kitchen work");
    rejects(fn()=>cmd($blocked,$blockedOrder,'ready','blocked-ready'),'order_blocked',"$status cannot be readied");
}
$cancel=array_replace($order,['woo_status'=>'cancelled']);$blocked=W::reconcile($r,$cancel,['processing'],130);
rejects(fn()=>cmd($blocked,$cancel,'dismiss','dismiss-cancel'),'bad_command','old dismiss command removed; Woo status controls visibility');
$completed=array_replace($order,['woo_status'=>'completed']);$closed=W::reconcile($r,$completed,['processing'],140);
check($closed['state']==='archived' && $closed['closed_at']===140,'Woo completion archives kitchen work');
rejects(fn()=>cmd($closed,$completed,'restore','restore-completed'),'order_blocked','completed order must be reopened in Woo before restore');
$h=cmd($r,$order,'handover','handover-1',150)['flow'];
check($h['state']==='archived' && $h['closed_at']===150,'handover archives without payment mutation');
$restored=cmd($h,$order,'restore','restore-order',160)['flow'];
check($restored['state']==='preparing' && !$restored['closed_at'] && $restored['changed'],'restore clears archive timestamp and requires review');
check(W::reconcile($h,$addon,['processing'],170)['state']==='preparing','open Woo order reappears even when alpha1 archived it only in KDS');
check(W::reconcile($blocked,$order,['processing'],170)['changed'],'Woo reopen of stopped work requires review');
rejects(fn()=>W::reconcile(['schema'=>999,'state'=>'ready'],$order,['processing'],100),'workflow_schema','unknown workflow schema fails closed');
for ($i=0;$i<55;$i++) { $o=$order; $o['total']=(string)$i; $f=W::reconcile($f,$o,['processing'],200+$i); }
check(count($f['events'])===40,'event journal is bounded');
check(Settings::get()['complete_on_handover']===true,'handover must complete Woo by default');
check(Settings::validate(['receive_statuses'=>['on-hold']])['receive_statuses']===['pending','processing','on-hold','accepted'],'old selection does not hide open orders');
foreach ([[],['completed'],[['processing']]] as $bad) check(!in_array('completed',Settings::validate(['receive_statuses'=>$bad])['receive_statuses'],true),'closed status cannot be enabled through legacy options');
check(Settings::validate(['complete_on_handover'=>false])['complete_on_handover']===true,'saved alpha1 false cannot disable required WC completion');
rejects(fn()=>Settings::validate(['poll_seconds'=>0]),'settings_type','unsafe polling interval rejected');
check(Snapshot::text('A<br>B &amp; C')==="A\nB & C",'plain-text metadata preserves breaks and entities');
check(Snapshot::text(['private'=>'data'])==='','nested raw metadata is not leaked');
$mq=[['key'=>'_yookds_state','value'=>'ready']];
check(isset(Orders::queryArgs($mq)['meta_query']),'HPOS uses supported metadata query');
\Automattic\WooCommerce\Utilities\OrderUtil::$hpos=false;
check(isset(Orders::queryArgs($mq)['yookds_meta_query']),'legacy uses explicit metadata query adapter');
check(Orders::legacyQuery([],['yookds_meta_query'=>$mq])['meta_query']===$mq,'legacy adapter translates metadata condition');
check(Orders::legacyQuery(['x'=>1],[])===['x'=>1],'unrelated Woo queries left unchanged');
foreach ([['2026-03-29 12:00:00',23],['2026-10-25 12:00:00',25]] as [$date,$hours]) {
    [$from,$to]=Orders::range('today',new DateTimeImmutable($date,new DateTimeZone('Europe/Stockholm')));
    check($to-$from+1===$hours*3600,"archive range handles $hours-hour daylight-saving day");
}
rejects(fn()=>Orders::range('never'),'invalid_range','invalid history range rejected');
class TestDB {
    public string $prefix='wp_'; public string $granted='1'; public int $releases=0;
    public function prepare($sql,...$args){return $sql;}
    public function get_var($sql){if(str_contains($sql,'RELEASE_LOCK')){$this->releases++;return 1;}return $this->granted;}
}
$wpdb=new TestDB();$lock=new YooKDS\Lock();
check($lock->run(1,fn()=>42)===42 && $wpdb->releases===1,'lock released after successful operation');
try{$lock->run(1,fn()=>throw new RuntimeException('test'));}catch(RuntimeException $e){}
check($wpdb->releases===2,'lock released after exception');
$wpdb->granted='0';$ran=false;
rejects(function()use($lock,&$ran){$lock->run(1,function()use(&$ran){$ran=true;});},'order_busy','busy order rejected');
check(!$ran,'busy-lock callback does not run');
check(!YooKDS\Rest::allowed(),'anonymous kitchen access rejected');
$GLOBALS['logged_in']=true;$GLOBALS['caps']=['read'];check(!YooKDS\Rest::allowed(),'ordinary customer cannot access kitchen');
$GLOBALS['caps']=['edit_shop_orders'];check(!YooKDS\Rest::allowed(),'limited own-order permission insufficient for whole-board access');
$GLOBALS['caps']=['edit_shop_orders','edit_others_shop_orders'];check(YooKDS\Rest::allowed(),'full order-management role accepted');
$GLOBALS['caps']=['manage_woocommerce'];check(YooKDS\Rest::allowed(),'Woo manager accepted');
echo "\n$passed isolated PHP checks passed. WordPress/WooCommerce integration was not executed by this runner.\n";
