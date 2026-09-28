<?php
/** Exercise the actual Orders and Snapshot classes against explicit in-memory WC/WP doubles. */
require __DIR__ . '/run.php';
$serviceStart = $passed;
$GLOBALS['store'] = [];
$GLOBALS['status_writes'] = 0;
$GLOBALS['fail_meta'] = false;
$GLOBALS['test_options'] = [];
$wpdb->granted = '1';
class FakeLine {
    public array $data;
    public function __construct(array $data) { $this->data=$data; }
    public function get_id(){return $this->data['id'];}
    public function get_meta($key,$single=true,$context='view'){return $this->data['meta'][$key]??'';}
    public function meta_exists($key){return array_key_exists($key,$this->data['meta']??[]);}
    public function update_meta_data($key,$value){$this->data['meta'][$key]=$value;}
    public function get_product(){return $GLOBALS['products'][$this->data['variation_id']?:$this->data['product_id']]??false;}
    public function save(){return true;}
    public function get_name(){return $this->data['name'];}
    public function get_quantity(){return $this->data['quantity'];}
    public function get_product_id(){return $this->data['product_id'];}
    public function get_variation_id(){return $this->data['variation_id'];}
    public function get_formatted_meta_data($prefix,$all){return array_map(fn($d)=>(object)['display_key'=>$d['label'],'display_value'=>$d['value']],$this->data['details']);}
    public function get_method_title(){return $this->data['label'];}
    public function get_method_id(){return $this->data['method'];}
}
class WC_Order {
    protected int $id; public array $data; private array $meta;
    public function __construct(int $id){$this->id=$id;$this->data=$GLOBALS['store'][$id];$this->read_meta_data(true);}
    public function get_id(){return $this->id;}
    public function get_type(){return $this->data['type']??'shop_order';}
    public function read_meta_data($force=false){$this->meta=$GLOBALS['store'][$this->id]['meta'];}
    public function get_meta($key,$single=true,$context='view'){return $this->meta[$key]??'';}
    public function update_meta_data($key,$value){$this->meta[$key]=$value;}
    public function meta_exists($key){return array_key_exists($key,$this->meta);}
    public function get_edit_order_url(){return 'https://test.invalid/wp-admin/admin.php?page=wc-orders&action=edit&id='.$this->id;}
    public function save(){ $this->save_meta_data(); return $this->id; }
    public function save_meta_data(){if(!$GLOBALS['fail_meta'])$GLOBALS['store'][$this->id]['meta']=$this->meta;}
    public function get_items($type){return array_map(fn($i)=>new FakeLine($i),$this->data[$type==='shipping'?'shipping':'items']);}
    public function get_order_number(){return $this->data['order_number'];}
    public function get_customer_note($context='view'){return $this->data['note'];}
    public function get_status($context='view'){return $this->data['woo_status'];}
    public function get_currency($context='view'){return $this->data['currency'];}
    public function get_total($context='view'){return $this->data['total'];}
    public function get_total_refunded(){return $this->data['refunded_total'];}
    public function get_qty_refunded_for_item($id){foreach($this->data['items'] as $i)if($i['id']===$id)return -$i['refunded_quantity'];return 0;}
    public function get_date_paid($context='view'){return $this->data['payment_recorded']?new DateTimeImmutable('@100'):null;}
    public function get_date_created($context='view'){return new DateTimeImmutable('@'.$this->data['created_at']);}
    public function update_status($status,$note){if(!empty($GLOBALS['fail_status']))return false;$GLOBALS['status_writes']++;$this->data['woo_status']=$status;$GLOBALS['store'][$this->id]['woo_status']=$status;return true;}
}
class WC_Order_Refund extends WC_Order {}
function wc_get_order($id){return isset($GLOBALS['store'][$id])?new WC_Order($id):false;}
function wc_get_orders($args) {
    $GLOBALS['last_query']=$args;
    $matches=[];$meta=$args['meta_query']??$args['yookds_meta_query']??[];
    foreach($GLOBALS['store'] as $id=>$d){
        if(($d['type']??'shop_order')!==($args['type']??'shop_order'))continue;
        if(!in_array($d['woo_status'],array_map(fn($s)=>str_replace('wc-','',$s),$args['status']),true))continue;
        $ok=true;
        foreach($meta as $c){
            $value=$d['meta'][$c['key']]??null;$cmp=$c['compare']??'=';$wanted=$c['value']??null;
            $ok=$ok&&match($cmp){
                'NOT EXISTS'=>$value===null,
                'IN'=>in_array($value,$wanted,true),
                'BETWEEN'=>$value!==null&&$value>=$wanted[0]&&$value<=$wanted[1],
                'LIKE'=>$value!==null&&stripos($value,$wanted)!==false,
                default=>$value==$wanted,
            };
        }
        if($ok)$matches[]=$id;
    }
    usort($matches,fn($a,$b)=>($GLOBALS['store'][$a]['created_at']<=>$GLOBALS['store'][$b]['created_at'])*($args['order']==='DESC'?-1:1));
    $total=count($matches);$limit=$args['limit'];$page=$args['paged']??1;
    $slice=array_slice($matches,($page-1)*$limit,$limit);
    return !empty($args['paginate'])?(object)['orders'=>$slice,'total'=>$total,'max_num_pages'=>(int)ceil($total/$limit)]:$slice;
}
function actionFor(array $row,string $action,string $id):array{return ['action'=>$action,'revision'=>$row['revision'],'token'=>$row['token'],'request_id'=>$id];}
$fixture=$order;$fixture['created_at']=time()-60;$fixture['meta']=['_nx_channel'=>'table','_nx_table'=>'18','unrelated_private_value'=>'never expose'];
$GLOBALS['store'][123]=$fixture;
$service=new YooKDS\Orders();
$board=$service->board();$first=$board['orders'][0];
check(count($board['orders'])===1 && $first['state']==='preparing','service backfills Woo order');
check($first['channel']==='table' && $first['table']==='18' && !isset($first['meta']),'snapshot reads recorded origin without leaking raw order metadata');
$input=actionFor($first,'ready','svc-ready-123');$ready=$service->change(123,$input,1)['order'];
check($ready['state']==='ready' && $GLOBALS['status_writes']===0,'service ready writes kitchen metadata only');
rejects(fn()=>$service->change(123,actionFor($first,'ready','stale-svc-123'),1),'stale_order','service rejects second-screen stale command');
check($service->change(123,$input,1)['replayed']===true,'service retries same request without duplicate status changes');
$GLOBALS['store'][123]['items'][0]['details'][0]['value']='Ingen nötolja';
$changed=$service->board()['orders'][0];
check($changed['changed'] && $changed['state']==='preparing' && $changed['items'][0]['details'][0]['value']==='Ingen nötolja','service reconciles current Woo item metadata');
$acked=$service->change(123,actionFor($changed,'acknowledge','svc-ack-123'),1)['order'];
$ready=$service->change(123,actionFor($acked,'ready','svc-ready-again'),1)['order'];
$archived=$service->change(123,actionFor($ready,'handover','svc-handover-123'),1)['order'];
check($archived['state']==='archived' && $GLOBALS['store'][123]['woo_status']==='completed','handover always completes Woo order');
check($service->board()['orders']===[],'completed order leaves active board immediately');
check($service->archive('today','nötolja',1)['total']===1,'archive searches item details on server');
check($service->archive('today','missing-word',1)['total']===0,'archive excludes nonmatching orders');
rejects(fn()=>$service->change(123,actionFor($archived,'restore','svc-restore-123'),1),'order_blocked','cannot restore while Woo is completed');
$GLOBALS['store'][123]['woo_status']='processing';
$restored=$service->sync(123);
check(count($GLOBALS['store'])===1 && $restored['changed'],'service restore never creates duplicate Woo order');
$acked=$service->change(123,actionFor($restored,'acknowledge','svc-restored-ack'),1)['order'];
$ready=$service->change(123,actionFor($acked,'ready','svc-final-ready'),1)['order'];
$GLOBALS['test_options'][YooKDS\Settings::KEY]=['complete_on_handover'=>true];
$GLOBALS['store'][123]['payment_recorded']=false;
$ready=$service->sync(123);
rejects(fn()=>$service->change(123,actionFor($ready,'handover','svc-unpaid-hand'),1),'payment_confirmation_required','unpaid handover requires explicit confirmation');
check($service->sync(123)['state']==='ready','failed completion does not archive order');
$GLOBALS['store'][123]['payment_recorded']=true;$ready=$service->sync(123);
$input=actionFor($ready,'handover','svc-paid-handover');$done=$service->change(123,$input,1);
check($done['order']['state']==='archived' && $GLOBALS['store'][123]['woo_status']==='completed' && $GLOBALS['status_writes']===2,'second work cycle invokes Woo completion once');
check($service->change(123,$input,1)['replayed'] && $GLOBALS['status_writes']===2,'completed handover retry does not send completion twice');
$GLOBALS['store'][999]=$fixture;$GLOBALS['store'][999]['id']=999;
$GLOBALS['fail_meta']=true;
rejects(fn()=>$service->sync(999),'save_failed','failed metadata storage is not reported as successful');
$GLOBALS['fail_meta']=false;$GLOBALS['test_options']=[];
$tracked=$service->sync(999);$GLOBALS['store'][999]['woo_status']='cancelled';
$blocked=$service->sync(999);
check($blocked['state']==='archived','cancellation removes active card using Woo status');
check($GLOBALS['store'][123]['meta']['unrelated_private_value']==='never expose','foreign order metadata preserved');
for($i=200;$i<226;$i++){
    $GLOBALS['store'][$i]=$fixture;$GLOBALS['store'][$i]['id']=$i;$GLOBALS['store'][$i]['order_number']='KE-'.$i;
    $GLOBALS['store'][$i]['created_at']=time()-$i;
    $f=$service->sync($i);$r=$service->change($i,actionFor($f,'ready','bulk-ready-'.$i),1)['order'];
    $service->change($i,actionFor($r,'handover','bulk-hand-'.$i),1);
}
$GLOBALS['store'][225]['items'][0]['name']='Saffranspannkaka';$service->sync(225);
$found=$service->archive('today','Saffranspannkaka',1);
check($found['total']===1 && $found['orders'][0]['id']===225,'search reaches order beyond first unfiltered 24-row page');
check($service->archive('today','',999)['page']===2,'history clamps out-of-range page after deletion/restore');
echo "\n".($passed-$serviceStart)." service checks passed with in-memory WC/WP doubles; $passed PHP checks in total.\n";
