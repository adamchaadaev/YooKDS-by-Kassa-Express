<?php
/** Regression tests for alpha.2. Explicit test doubles, never real customer orders. */
require __DIR__.'/service.php';
use YooKDS\Settings; use YooKDS\Fields; use YooKDS\NX; use YooKDS\Numbering;
$start=$passed;
foreach (['#27080'=>'7080',7=>'0007',10000=>'0000','ABC'=>'ABC','0001'=>'0001'] as $n=>$want) {
    check((string)Numbering::format($n)===$want,'four-digit display '.$n);
}
rejects(fn()=>Settings::validate(['four_digit_numbers'=>'true']),'settings_type','display-number setting is a real boolean');
rejects(fn()=>Settings::validate(['custom_fields'=>[['scope'=>'order','key'=>'_stripe_token','label'=>'Unsafe']]]),'settings_fields','payment token cannot be exposed as custom field');
rejects(fn()=>Settings::validate(['custom_fields'=>[['scope'=>'other','key'=>'x','label'=>'x']]]),'settings_fields','unknown custom field scope rejected');
check(Fields::value(false)==='Nej' && Fields::value(0)==='0','false and zero remain visible');
check(Fields::value(['x','y'])==="x\ny",'multiselect values remain distinct');
check(Fields::value(['secret'=>'must not leak','Sauce'=>'Garlic'])==='Sauce: Garlic','sensitive nested keys suppressed');
$wf=['f1'=>['id'=>'f1','label'=>'Sås','value'=>'Vitlök (+10 kr)','raw'=>'a7'], 'f2'=>['label'=>'Lök','value'=>false], 'f3'=>['label'=>'Styrka','value'=>0]];
$parsed=Fields::wapf($wf);
check(count($parsed['details'])===3 && !$parsed['warnings'],'WAPF saved label/value selections parsed');
check(!str_contains(json_encode($parsed),'a7'),'WAPF internal option ID not used as display label');
check(Fields::wapf(json_encode($wf))===$parsed,'JSON-encoded saved WAPF selection container parsed');
check(Fields::wapf(['broken'=>'something'])['warnings']!==[],'unreadable WAPF data produces a warning instead of disappearing');
check(count(Fields::mergeFallback([$parsed['details'][0]],$parsed['details']))===3,'standard metadata and WAPF fallback deduplicate');
check(count(Fields::mergeFallback([$parsed['details'][0]],[$parsed['details'][0],$parsed['details'][0]]))===2,'repeated identical WAPF fields are retained');
$GLOBALS['test_options'][Settings::KEY]=['custom_fields'=>[
    ['scope'=>'order','key'=>'_delivery_slot','label'=>'Leveranstid'],
    ['scope'=>'order','key'=>'_wc_other/cafe/cutlery','label'=>'Bestick'],
    ['scope'=>'item','key'=>'_kitchen_extra','label'=>'Till köket'],
    ['scope'=>'product','key'=>'kitchen_prep','label'=>'Tillagning'],
]];
$GLOBALS['store']=[];
$GLOBALS['store'][27080]=$fixture;
$GLOBALS['store'][27080]['id']=27080;$GLOBALS['store'][27080]['order_number']='27080';
$GLOBALS['store'][27080]['meta']['_delivery_slot']='18:30';
$GLOBALS['store'][27080]['meta']['_wc_other/cafe/cutlery']='0';
$GLOBALS['store'][27080]['items'][0]['details']=[['label'=>'Sås','value'=>'Vitlök (+10 kr)']];
$GLOBALS['store'][27080]['items'][0]['meta']=['_wapf_meta'=>$wf,'_kitchen_extra'=>"Separat låda\nIngen nötolja",'_secret'=>'never expose'];
$live=$service->board()['orders'][0];
check($live['id']===27080 && $live['order_number']==='7080','snapshot retains full ID with short display number');
check(count($live['items'][0]['details'])===4,'snapshot merges WAPF and mapped private item field');
check(count($live['custom_fields'])===2 && $live['custom_fields'][1]['value']==='0','order and Checkout Block custom meta supported');
check(!str_contains(json_encode($live),'never expose'),'unmapped private metadata not included in API');
$ready=$service->change(27080,actionFor($live,'ready','compat-ready-1'),1)['order'];
$GLOBALS['store'][27080]['meta']['_delivery_slot']='19:00';
$changed=$service->sync(27080);
check($changed['changed'] && $changed['state']==='preparing','custom order field edit resets ready for review');
$live=$service->change(27080,actionFor($changed,'acknowledge','compat-ack-1'),1)['order'];
$ready=$service->change(27080,actionFor($live,'ready','compat-ready-2'),1)['order'];
$GLOBALS['store'][27080]['items'][0]['meta']['_wapf_meta']['f3']['value']=1;
$changed=$service->sync(27080);
check($changed['changed'] && $changed['state']==='preparing','saved WAPF edit detected between kitchen screens');
$GLOBALS['store'][27080]['items'][0]['meta']['_wapf_meta']=['unreadable'=>new stdClass()];
$blocked=$service->sync(27080);
check($blocked['state']==='blocked' && $blocked['reason']==='unreadable_fields','unknown WAPF fields block unsafe kitchen completion');
rejects(fn()=>$service->change(27080,actionFor($blocked,'ready','cannot-drop-addons'),1),'order_blocked','cannot silently fulfil unreadable add-ons');
$GLOBALS['store'][27080]['items'][0]['meta']['_wapf_meta']=$wf;
$back=$service->sync(27080);
check($back['changed'] && $back['state']==='preparing','repair of WAPF values returns for review');
class FakeProduct {
    public function __construct(public array $meta, public int $parent=0){}
    public function get_meta($key,$single=true,$context='view'){return $this->meta[$key]??'';}
    public function meta_exists($key){return array_key_exists($key,$this->meta);}
    public function get_parent_id(){return $this->parent;}
}
function wc_get_product($id){return $GLOBALS['products'][$id]??false;}
$GLOBALS['products'][4]=new FakeProduct(['kitchen_prep'=>'Grilla 3 minuter']);
$GLOBALS['products'][7]=new FakeProduct([],4);
$line=new FakeLine($fixture['items'][0]);
Fields::snapshotProduct($line,'cart-key',[],new WC_Order(27080));
check($line->get_meta(Fields::PRODUCT_SNAPSHOT)['kitchen_prep']['value']==='Grilla 3 minuter','variation falls back to parent product custom field at order creation');
$GLOBALS['products'][4]->meta['kitchen_prep']='Grilla 7 minuter';
Fields::snapshotProduct($line,'cart-key',[],new WC_Order(27080));
check($line->get_meta(Fields::PRODUCT_SNAPSHOT)['kitchen_prep']['value']==='Grilla 3 minuter','later product edits do not rewrite historical snapshot');
check(!str_contains(json_encode(Fields::item(new FakeLine($fixture['items'][0]),new WC_Order(27080))),'Grilla'),'old orders never display an invented historical product field');
// Core synchronization regressions; fresh store and no plugin-level status opt-in.
$GLOBALS['test_options']=[];$GLOBALS['store']=[];
check($service->board()['orders']===[],'empty Woo store gives zero KDS cards, no demo fallback');
foreach(['pending','processing','on-hold','accepted','completed','cancelled','refunded','failed','checkout-draft','draft','trash'] as $i=>$status){
    $id=1000+$i;$GLOBALS['store'][$id]=$fixture;$GLOBALS['store'][$id]['id']=$id;$GLOBALS['store'][$id]['woo_status']=$status;
}
$feed=$service->board();
check(array_column($feed['orders'],'woo_status')===['pending','processing','on-hold','accepted'],'only submitted unfinished Woo orders appear');
check(!isset($GLOBALS['last_query']['meta_query']) && !isset($GLOBALS['last_query']['yookds_meta_query']),'active feed does not depend on a KDS metadata index');
check(count($GLOBALS['store'])===11,'reading the board creates no additional orders');
$GLOBALS['store'][1001]['woo_status']='completed';
check(!in_array(1001,array_column($service->board()['orders'],'id'),true),'external Woo completion removes even previously preparing card');
$GLOBALS['store']=[];
foreach([17080,27080] as $id){$GLOBALS['store'][$id]=$fixture;$GLOBALS['store'][$id]['id']=$id;$GLOBALS['store'][$id]['order_number']=(string)$id;}
$feed=$service->board();
check(array_column($feed['orders'],'order_number')===['7080','7080'],'identical four-digit numbers remain two separate cards');
$first=$service->sync(17080);$ready=$service->change(17080,actionFor($first,'ready','collision-ready'),1)['order'];
$GLOBALS['fail_status']=true;
rejects(fn()=>$service->change(17080,actionFor($ready,'handover','failed-completion'),1),'completion_failed','Woo status write failure does not report delivered');
check($service->sync(17080)['state']==='ready' && $GLOBALS['store'][17080]['woo_status']==='processing','failed Woo completion leaves order active');
$GLOBALS['fail_status']=false;
$service->change(17080,actionFor($ready,'handover','collision-handed'),1);
check($GLOBALS['store'][17080]['woo_status']==='completed' && $GLOBALS['store'][27080]['woo_status']==='processing','short-number collision cannot complete the wrong order');
$GLOBALS['store'][27080]['payment_recorded']=false;
$first=$service->sync(27080);$ready=$service->change(27080,actionFor($first,'ready','unpaid-ready-2'),1)['order'];
$in=actionFor($ready,'handover','confirm-unpaid');$in['confirm_unpaid']=true;
$done=$service->change(27080,$in,1);
check($done['order']['woo_status']==='completed' && !$GLOBALS['store'][27080]['payment_recorded'],'explicit unpaid confirmation completes without inventing payment');
// Session/context behavior with no network, cookies, actual checkout or Woo install.
check(NX::merge([],['nx_table'=>'18'])['nx_channel']==='table','table-only QR implies table channel');
check(NX::merge(['nx_channel'=>'table','nx_table'=>'18'],['nx_channel'=>'express','nx_station'=>'2'])['nx_table']==='','express visit clears a previous table');
check(NX::merge(['nx_channel'=>'table','nx_table'=>'18','nx_station'=>'1'],['nx_table'=>'19'])['nx_station']==='','new table QR clears previous register');
check(NX::merge([],['nx_channel'=>['malicious'],'nx_table'=>['malicious']])['nx_channel']==='','array query parameters rejected');
check(NX::merge([],['nx_channel'=>'QR'])['nx_channel']==='table','QR channel alias normalized');
check(!array_filter(NX::merge(['nx_channel'=>'table','nx_table'=>'18'],['nx_reset'=>'1'])),'explicit reset clears old attribution');
class TestSession {
    public array $data=[];
    public function get($key,$default=null){return $this->data[$key]??$default;}
    public function set($key,$value){$this->data[$key]=$value;}
    public function __unset($key){unset($this->data[$key]);}
}
$GLOBALS['wc']=(object)['session'=>new TestSession()];
function WC(){return $GLOBALS['wc'];}
$GLOBALS['wc']->session->data=['nx_channel'=>'table','nx_table'=>'22','nx_station'=>'2','nx_mode'=>'kiosk','yookds_nx_revision'=>'scan-1','yookds_nx_captured_at'=>time()];
$o=new WC_Order(27080);NX::attach($o);$o->save_meta_data();
check($o->get_meta('_nx_table')==='22' && $o->get_meta('_nx_mode')==='kiosk','checkout attaches NX channel, table, register and mode');
$GLOBALS['wc']->session->set('yookds_nx_revision','scan-2');NX::processed($o);
check($GLOBALS['wc']->session->get('nx_table')==='22','checkout completion does not clear a newer scan revision');
$GLOBALS['wc']->session->set('yookds_nx_revision','scan-1');NX::processed($o);
check($GLOBALS['wc']->session->get('nx_table')===null,'processed checkout clears its own attribution for next customer');
NX::attach($o);check($o->get_meta('_nx_table')==='22','empty session does not erase existing POS/API order context');
$GLOBALS['wc']->session->data=['nx_channel'=>'table','nx_table'=>'999','yookds_nx_revision'=>'old-scan','yookds_nx_captured_at'=>time()-13*3600];
NX::attach($o);check($o->get_meta('_nx_table')==='22' && !$GLOBALS['wc']->session->data,'expired context cannot leak to later checkout');
function add_action($name,$callback,$priority=10,$args=1){$GLOBALS['hooks'][$name][]=$callback;}
function add_filter($name,$callback,$priority=10,$args=1){$GLOBALS['hooks'][$name][]=$callback;}
(new NX())->init();(new Fields())->init();(new Numbering())->init();
check(isset($GLOBALS['hooks']['woocommerce_checkout_create_order']) && isset($GLOBALS['hooks']['woocommerce_store_api_checkout_update_order_from_request']),'both classic and Store API checkout handlers registered');
check(isset($GLOBALS['hooks']['woocommerce_store_api_checkout_order_processed']),'Blocks processing clears NX session');
check(isset($GLOBALS['hooks']['woocommerce_checkout_create_order_line_item']),'product field snapshot hook registered');
check(isset($GLOBALS['hooks']['woocommerce_order_number']),'global Woo display number filter registered');
echo "\n".($passed-$start)." alpha.2 compatibility checks; $passed total PHP checks with explicit WP/WC doubles.\n";
