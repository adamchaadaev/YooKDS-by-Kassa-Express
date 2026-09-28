<?php
namespace YooKDS;
if (!defined('ABSPATH')) { exit; }

/** Delivery Hero Restaurant POS API, indirect flow. No Quick Commerce API assumptions. */
final class FoodoraRestaurant {
    public static function signature(string $header, string $secret): bool {
        if (strlen($secret) < 32 || !preg_match('/^Bearer ([A-Za-z0-9_-]+)\.([A-Za-z0-9_-]+)\.([A-Za-z0-9_-]+)$/D', $header, $m)) { return false; }
        $decode = static fn($v)=>base64_decode(strtr($v, '-_', '+/'), true);
        $h = json_decode($decode($m[1]) ?: '', true); $p = json_decode($decode($m[2]) ?: '', true);
        if (($h['alg'] ?? '') !== 'HS512' || ($p['service'] ?? '') !== 'middleware') { return false; }
        if (isset($p['exp']) && (!is_numeric($p['exp']) || $p['exp'] <= time())) { return false; }
        if (isset($p['nbf']) && (!is_numeric($p['nbf']) || $p['nbf'] > time()+30)) { return false; }
        return hash_equals(hash_hmac('sha512', $m[1].'.'.$m[2], $secret, true), $decode($m[3]) ?: '');
    }
    public static function token(): string {
        $cached = get_transient('yookds_token_foodora'); if (is_string($cached) && $cached !== '') { return $cached; }
        return (new Lock())->named('oauth:foodora', static function () {
            $cached = get_transient('yookds_token_foodora'); if (is_string($cached) && $cached !== '') { return $cached; }
            $c = Connections::get('foodora');
            if (!$c['username'] || !$c['password']) { throw new Problem('credentials_missing','Ange Foodoras användarnamn och lösenord.',400); }
            $r = ProviderApi::http(ProviderApi::host('foodora').'/v2/login', ['method'=>'POST','headers'=>['Content-Type'=>'application/json'], 'body'=>wp_json_encode(['username'=>$c['username'],'password'=>$c['password']])]);
            if (!is_string($r['access_token'] ?? null) || empty($r['expires_in'])) { throw new Problem('token_response','Foodora gav ingen giltig åtkomsttoken.',502); }
            set_transient('yookds_token_foodora',$r['access_token'],max(1,(int)$r['expires_in']-60));return $r['access_token'];
        });
    }
    public static function routes(): void {
        $permission = static function ($r) {
            $c=Connections::get('foodora');
            return $c['enabled'] && strlen($r->get_body())<=2097152 && hash_equals($c['venue_id'],(string)$r['remoteId']) && self::signature((string)$r->get_header('authorization'),$c['webhook_secret'])
                ? true : new \WP_Error('foodora_auth','Autentisering misslyckades.',['status'=>401]);
        };
        $callback = static function ($r) {
            try {
                $body=$r->get_json_params();if(!is_array($body)){throw new Problem('foodora_json','Ogiltig order.',400);}
                $data=isset($r['remoteOrderId']) ? self::status((int)$r['remoteOrderId'],$body) : self::receive($body);
                return new \WP_REST_Response($data,200,['Cache-Control'=>'no-store']);
            } catch (Problem $e) { return new \WP_Error($e->slug,$e->getMessage(),['status'=>$e->status]); }
            catch (\Throwable $e) { return new \WP_Error('foodora_failed','Orderhändelsen kunde inte sparas.',['status'=>503]); }
        };
        register_rest_route('yookds/v1','/foodora/order/(?P<remoteId>[A-Za-z0-9_-]+)',['methods'=>'POST','permission_callback'=>$permission,'callback'=>$callback]);
        register_rest_route('yookds/v1','/foodora/remoteId/(?P<remoteId>[A-Za-z0-9_-]+)/remoteOrder/(?P<remoteOrderId>[1-9][0-9]*)/posOrderStatus',['methods'=>'PUT','permission_callback'=>$permission,'callback'=>$callback]);
    }
    public static function receive(array $data): array {
        global $wpdb; $c=Connections::get('foodora');$remote=$data['token']??'';
        if(!is_string($remote)||!preg_match('/^[A-Za-z0-9_-]{1,128}$/D',$remote)){throw new Problem('foodora_token','Ogiltigt order-ID.',422);}
        // Indirect flow must not silently accept a direct-flow dispatch.
        if(!empty($data['callbackUrls']['orderAcceptedUrl'])){throw new Problem('foodora_direct_flow','Konfigurera indirekt flöde hos Foodora. Acceptera ordern i handlarappen.',422);}
        $hash=hash('sha256','foodora:'.$c['environment'].':'.$c['venue_id'].':'.$remote);
        $id=(new Lock())->named('external-inbox:'.$hash,static function()use($wpdb,$c,$remote,$hash,$data){
            $snapshot=self::normalize($data,1);$table=ExternalOrders::table();
            $id=(int)$wpdb->get_var($wpdb->prepare("SELECT id FROM $table WHERE identity_hash=%s",$hash));
            if(!$id){
                if(!$wpdb->insert($table,['identity_hash'=>$hash,'provider'=>'foodora','venue'=>$c['venue_id'],'remote_id'=>$remote,'environment'=>$c['environment'],'snapshot'=>'{}','flow'=>'{}','updated_at'=>time()])){throw new Problem('inbox_failed','Ordern kunde inte sparas.',503);}
                $id=(int)$wpdb->insert_id;
            }
            (new Lock())->named('external:'.$id,static function()use($id,$snapshot){
                $r=ExternalOrders::record($id);$old=json_decode($r['flow'],true)?:null;
                // Dispatch retries cannot regress a later cancellation, pickup or product modification.
                if($old){return;}
                $snapshot['id']=-$id;$flow=Workflow::reconcile(null,$snapshot,['processing'],time());
                ExternalOrders::persist($id,$snapshot,$flow,['pending'=>0,'updated_at'=>time(),'error_code'=>'']);
            });return $id;
        });
        Connections::status('foodora','order_received');
        // Printing happens outside the dispatch response: acknowledgement must stay fast.
        if(function_exists('as_enqueue_async_action')){as_enqueue_async_action('yookds_auto_print',[-$id],'yookds');}
        else{wp_schedule_single_event(time()+1,'yookds_auto_print',[-$id]);}
        return ['remoteResponse'=>['remoteOrderId'=>(string)$id]];
    }
    public static function status(int $id,array $data): array {
        return (new Lock())->named('external:'.$id,static function()use($id,$data){
            $r=ExternalOrders::record($id);$c=Connections::get('foodora');
            if($r['provider']!=='foodora'||$r['venue']!==$c['venue_id']||$r['environment']!==$c['environment']){throw new Problem('venue_mismatch','Fel restaurang.',422);}
            $s=json_decode($r['snapshot'],true);$f=json_decode($r['flow'],true);$status=$data['status']??'';
            if(!$f){throw new Problem('foodora_pending','Ordern har ännu inte sparats.',503);}
            if($f['state']==='archived'){return ['received'=>true];}
            if($status==='ORDER_CANCELLED'||$status==='ORDER_PICKED_UP'){$s['woo_status']=$status==='ORDER_CANCELLED'?'cancelled':'completed';$s['woo_status_label']=$status;}
            elseif($status==='PRODUCT_ORDER_MODIFICATION_SUCCESSFUL'){
                if(!is_array($data['updatedOrder']??null)||($data['updatedOrder']['token']??'')!==$r['remote_id']){throw new Problem('foodora_modification','Ändrade orderrader saknas.',422);}
                $s=self::normalize($data['updatedOrder'],$id);
            }elseif(in_array($status,['COURIER_ARRIVED_AT_VENDOR','SHOW_RIDER_WAITING_WARNING','HIDE_RIDER_WAITING_WARNING','PRODUCT_ORDER_MODIFICATION_FAILED'],true)){
                $s['courier_notice']=$status==='HIDE_RIDER_WAITING_WARNING'?'':($status==='PRODUCT_ORDER_MODIFICATION_FAILED'?'Orderändring misslyckades – kontrollera Foodora.':'Foodoras bud väntar vid restaurangen.');
            }else{return ['received'=>true,'ignored'=>true];}
            $f=Workflow::reconcile($f,$s,['processing'],time());ExternalOrders::persist($id,$s,$f,['updated_at'=>time()]);return ['received'=>true];
        });
    }
    public static function ready(string $token): void {
        global $wpdb;$c=Connections::get('foodora');$table=ExternalOrders::table();
        $raw=$wpdb->get_var($wpdb->prepare("SELECT snapshot FROM $table WHERE provider='foodora' AND remote_id=%s AND venue=%s AND environment=%s",$token,$c['venue_id'],$c['environment']));
        $s=json_decode($raw?:'',true);
        if(!$s){throw new Problem('not_found','Foodora-order saknas.',404);}
        // API explicitly requires skipping this event when the dispatch supplies no prepared callback.
        if(empty($s['foodora_prepared_callback'])){return;}
        ProviderApi::request('foodora','/v2/orders/'.rawurlencode($token).'/preparation-completed','POST');
    }
    private static function toppings(array $rows,array &$details,int $depth=0):void {
        if($depth>8||count($rows)>100){throw new Problem('foodora_toppings','För många nivåer av tillval. Kontrollera originalordern.',422);}
        foreach($rows as $row){
            if(!is_array($row)||empty($row['name'])||!is_numeric($row['quantity']??1)){throw new Problem('foodora_toppings','Ogiltigt tillval.',422);}
            $details[]=['label'=>$depth?'Tillval i tillval':'Tillval','value'=>Snapshot::text($row['quantity']??1).' × '.Snapshot::text($row['name'])];
            if(!empty($row['children'])){if(!is_array($row['children']))throw new Problem('foodora_toppings','Ogiltigt tillval.',422);self::toppings($row['children'],$details,$depth+1);}
        }
    }
    public static function normalize(array $data,int $id):array {
        if(empty($data['token'])||empty($data['products'])||!is_array($data['products'])||count($data['products'])>300||!is_string($data['createdAt']??null)||!($created=strtotime($data['createdAt']))){throw new Problem('external_schema','Foodora-order saknar obligatoriska uppgifter.',422);}
        $items=[];
        foreach($data['products'] as $i=>$p){
            if(!is_array($p)||empty($p['name'])||!is_numeric($p['quantity']??null)||(float)$p['quantity']<=0||(float)$p['quantity']>10000){throw new Problem('external_items','Ogiltig orderrad från Foodora.',422);}
            $details=[];if(!empty($p['comment']))$details[]=['label'=>'Instruktion','value'=>Snapshot::text($p['comment'])];
            if(!empty($p['variation']['name'])&&$p['variation']['name']!==$p['name'])$details[]=['label'=>'Variant','value'=>Snapshot::text($p['variation']['name'])];
            if(isset($p['selectedToppings'])&&!is_array($p['selectedToppings']))throw new Problem('foodora_toppings','Ogiltiga tillval.',422);
            self::toppings($p['selectedToppings']??[],$details);
            $items[]=['id'=>$i+1,'name'=>Snapshot::text($p['name']),'quantity'=>(string)$p['quantity'],'product_id'=>0,'variation_id'=>0,'details'=>$details,'field_warnings'=>[],'refunded_quantity'=>'0'];
        }
        $callback=$data['callbackUrls']['orderPreparedUrl']??$data['callbackUrls']['orderPreparedUpUrl']??'';
        if($callback!==''&&$callback!==ProviderApi::host('foodora').'/v2/orders/'.rawurlencode($data['token']).'/preparation-completed'){throw new Problem('foodora_callback','Foodoras callback matchar inte den valda miljön.',422);}
        $due=$data['delivery']['riderPickupTime']??$data['pickup']['pickupTime']??$data['delivery']['expectedDeliveryTime']??'';
        return ['id'=>-abs($id),'kind'=>'external','provider'=>'foodora','external_id'=>Snapshot::text($data['code']??''),'order_number'=>Snapshot::text($data['code']??$data['shortCode']??$data['token']),
            'woo_status'=>'processing','woo_status_label'=>'Accepterad i Foodora','order_url'=>'','items'=>$items,'note'=>Snapshot::text($data['comments']['customerComment']??''),
            'shipping'=>[['id'=>0,'label'=>($data['expeditionType']??'')==='pickup'?'Hämtas av kund':'Foodora · leverans','method'=>'external']],
            'custom_fields'=>[],'mode'=>'','channel'=>'foodora','origin'=>'','table'=>'','currency'=>'','total'=>'','refunded_total'=>'0','payment_recorded'=>false,'created_at'=>$created,'due_at'=>is_string($due)?(strtotime($due)?:0):0,
            'remote_ready'=>false,'accepted'=>true,'foodora_prepared_callback'=>$callback!=='','courier_notice'=>''];
    }
}
