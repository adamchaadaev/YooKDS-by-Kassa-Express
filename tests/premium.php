<?php
/** Local WP/WC/database tests. Provider HTTP is explicitly mocked; no real service calls. */
use YooKDS\{Connections,ProviderApi,ExternalOrders,BizPrint,Integrations,Workflow,Orders,Problem,FoodoraRestaurant};
if (!defined('WP_CLI') || getenv('YOOKDS_INTEGRATION') !== '1' || wp_get_environment_type() !== 'local') { throw new RuntimeException('Disposable local installation and explicit opt-in required.'); }
$GLOBALS['premium_passed']=0;
function premium_check($ok,$name){if(!$ok)throw new RuntimeException('FAIL '.$name);$GLOBALS['premium_passed']++;WP_CLI::log('PASS '.$name);}
function premium_reject($work,$slug){try{$work();}catch(Problem $e){premium_check($e->slug===$slug,$slug);return;}throw new RuntimeException('FAIL no rejection: '.$slug);}
$old=Connections::all();$created=[];$externalIds=[];$jobIds=[];
$http=[];$failReady=false;$failPrint=false;$remoteState='production';$remoteBadVenue=false;
$secret=str_repeat('s',40);
$wolt=['id'=>'fixture-wolt-1','venue'=>['id'=>'fixture-venue'],'order_number'=>'W-1042','order_status'=>'production','created_at'=>gmdate('c'),
    'consumer_comment'=>'Sås separat <script>unsafe</script>','pickup_eta'=>gmdate('c',time()+900),
    'items'=>[['id'=>'item-a','name'=>'TEST · Smashburgare','count'=>2,'options'=>[['name'=>'Bröd','value'=>'Glutenfritt','count'=>1],['name'=>'Tillval','value'=>'Extra ost','count'=>2]]]]];
$foodora=['token'=>'fixture-foodora-1','code'=>'F-2401','createdAt'=>gmdate('c'),'comments'=>['customerComment'=>'TEST · Utan lök'],'expeditionType'=>'delivery',
    'products'=>[['name'=>'TEST · Falafeltallrik','quantity'=>'1','comment'=>'Sås i separat burk','selectedToppings'=>[['name'=>'Extra sås','quantity'=>2,'children'=>[]]]]],
    'callbackUrls'=>['orderPreparedUrl'=>'https://integration-middleware.stg.restaurant-partners.com/v2/orders/fixture-foodora-1/preparation-completed']];
$filter=static function($pre,$args,$url)use(&$http,&$wolt,&$foodora,&$failReady,&$failPrint,&$remoteState,&$remoteBadVenue){
    $http[]=[$url,$args];
    $data=[];
    if(str_contains($url,'oauth2/token'))$data=['access_token'=>'synthetic-access','refresh_token'=>'synthetic-rotated','expires_in'=>3600];
    elseif(str_contains($url,'/v2/login'))$data=['access_token'=>'synthetic-foodora','expires_in'=>7200];
    elseif(str_contains($url,'/preparation-completed')){}
    elseif(str_contains($url,'/v2/orders/')){$data=$wolt;$data['order_status']=$remoteState;if($remoteBadVenue)$data['venue']['id']='other-venue';}
    elseif(str_contains($url,'/v2/chains/')&&($args['method']??'GET')==='GET')$data=$foodora;
    elseif(str_contains($url,'/ready')||(($args['method']??'')==='PUT'&&str_contains($url,'/v2/chains/'))){if($failReady)return new WP_Error('test','synthetic unreachable');}
    elseif(str_contains($url,'/printers?'))$data=['data'=>[['id'=>7,'name'=>'TEST · Kök','station'=>['name'=>'TEST']]]];
    elseif(str_contains($url,'/jobs')&&($args['method']??'')==='POST'){if($failPrint)return new WP_Error('test','synthetic timeout');$data=['data'=>['id'=>42,'status'=>'pending']];}
    elseif(str_contains($url,'/jobs/42'))$data=['data'=>['id'=>42,'status'=>'done']];
    else return new WP_Error('forbidden_test_network','No unmocked network allowed in this test.');
    return ['headers'=>[], 'body'=>wp_json_encode($data),'response'=>['code'=>200,'message'=>'OK']];
};
add_filter('pre_http_request',$filter,10,3);
add_filter('pre_wp_mail','__return_true');
try {
    Connections::save('wolt',['client_id'=>'fixture-client','client_secret'=>'fixture-secret','refresh_token'=>'synthetic-refresh','venue_id'=>'fixture-venue','webhook_secret'=>$secret,'enabled'=>true]);
    Connections::save('foodora',['username'=>'fixture-client','password'=>'fixture-secret','venue_id'=>'fixture-foodora-venue','webhook_secret'=>$secret,'enabled'=>true]);
    premium_check(!str_contains(wp_json_encode(Connections::all()),'fixture-secret'),'stored credentials encrypted');
    $public=Connections::public('wolt');premium_check(!isset($public['client_secret'])&&$public['client_secret_saved'],'public settings contain presence flags only');
    Connections::save('wolt',['client_secret'=>'']);premium_check(Connections::get('wolt')['client_secret']==='fixture-secret','blank password preserves existing secret');
    premium_reject(fn()=>Connections::save('wolt',['environment'=>'http://evil.invalid']),'environment');
    premium_reject(fn()=>Connections::save('foodora',['webhook_secret'=>'weak']),'weak_secret');
    $body='{"type":"order.notification"}';$sig=hash_hmac('sha256',$body,$secret);
    premium_check(Integrations::signature('wolt',$body,$sig,$secret),'Wolt signature accepted for exact raw body');
    premium_check(!Integrations::signature('wolt',$body.' ',$sig,$secret),'tampered webhook rejected');
    premium_check(!Integrations::signature('wolt',$body,'bad',$secret),'invalid webhook rejected');
    $enc=static fn($x)=>rtrim(strtr(base64_encode($x),'+/','-_'),'=');
    $jwt=$enc('{"alg":"HS512","typ":"JWT"}').'.'.$enc('{"service":"middleware","exp":'.(time()+300).'}');
    $jwt.='.'.$enc(hash_hmac('sha512',$jwt,$secret,true));
    premium_check(Integrations::signature('foodora',$body,'Bearer '.$jwt,$secret),'Foodora signed HS512 middleware JWT accepted');
    premium_check(!Integrations::signature('foodora',$body,'Bearer '.$jwt.'x',$secret),'Foodora tampered JWT denied');
    ProviderApi::token('foodora');
    premium_check(!Integrations::signature('foodora',$body,'wrong',$secret),'Foodora invalid token rejected');
    ProviderApi::token('wolt');premium_check(Connections::get('wolt')['refresh_token']==='synthetic-rotated','Wolt refresh token rotates persistently');
    $calls=count($http);ProviderApi::token('wolt');premium_check(count($http)===$calls,'access token reused within lifetime');
    $normalized=ExternalOrders::normalize('wolt',$wolt,11);
    premium_check($normalized['id']===-11&&$normalized['order_number']==='W-1042','provider number is not truncated and ID cannot collide with Woo');
    premium_check($normalized['items'][0]['details'][1]['value']==='2 × Extra ost','Wolt modifier multiplicity preserved');
    premium_check(!str_contains($normalized['note'],'<script>'),'provider text is not executable markup');
    $bad=$wolt;$bad['order_status']='brand-new-state';premium_reject(fn()=>ExternalOrders::normalize('wolt',$bad,11),'external_schema');
    $bad=$wolt;$bad['items'][0]['count']=0;premium_reject(fn()=>ExternalOrders::normalize('wolt',$bad,11),'external_items');
    $bad=$wolt;$bad['items'][0]['options']='lost';premium_reject(fn()=>ExternalOrders::normalize('wolt',$bad,11),'external_options');
    ExternalOrders::enqueue('wolt','fixture-wolt-1');
    global $wpdb;$table=ExternalOrders::table();
    $id=(int)$wpdb->get_var("SELECT id FROM $table WHERE remote_id='fixture-wolt-1'");$externalIds[]=$id;
    ExternalOrders::enqueue('wolt','fixture-wolt-1');
    premium_check((int)$wpdb->get_var("SELECT COUNT(*) FROM $table WHERE remote_id='fixture-wolt-1'")===1,'duplicate webhooks share one inbox record');
    ExternalOrders::process($id);$row=ExternalOrders::view(ExternalOrders::record($id));
    premium_check($row&&$row['state']==='preparing'&&!$row['integration_error'],'queued external order becomes a kitchen card');
    premium_check(count(wc_get_orders(['limit'=>1,'return'=>'ids']))===0,'external order creates no WooCommerce sale');
    ExternalOrders::enqueue('wolt','fixture-wolt-1');ExternalOrders::process($id);$same=ExternalOrders::view(ExternalOrders::record($id));
    premium_check($same['revision']===$row['revision'],'repeat webhook does not revise unchanged card');
    $action=fn($r,$a)=>['action'=>$a,'request_id'=>wp_generate_uuid4(),'revision'=>$r['revision'],'token'=>$r['token']];
    $failReady=true;premium_reject(fn()=>ExternalOrders::change($id,$action($row,'ready'),1),'provider_unreachable');
    premium_check(ExternalOrders::view(ExternalOrders::record($id))['state']==='preparing','failed remote ready never reports ready locally');
    $failReady=false;$command=$action($row,'ready');$ready=ExternalOrders::change($id,$command,1)['order'];
    premium_check($ready['state']==='ready','successful remote ready confirmed locally');
    $calls=count($http);premium_check(ExternalOrders::change($id,$command,1)['replayed']&&count($http)===$calls,'same ready request never sends twice');
    premium_reject(fn()=>ExternalOrders::change($id,$action($row,'ready'),1),'stale_order');
    $done=ExternalOrders::change($id,$action($ready,'handover'),1)['order'];premium_check($done['state']==='archived','external local handover archived');
    ExternalOrders::enqueue('wolt','fixture-wolt-1');ExternalOrders::process($id);premium_check(ExternalOrders::view(ExternalOrders::record($id))['state']==='archived','replayed old remote status does not reopen handed-over card');
    $ack=FoodoraRestaurant::receive($foodora);$fid=(int)$ack['remoteResponse']['remoteOrderId'];$externalIds[]=$fid;
    $f=ExternalOrders::view(ExternalOrders::record($fid));premium_check($f['provider']==='foodora'&&$f['items'][0]['details'][0]['value']==='Sås i separat burk','Foodora restaurant instructions preserved');
    premium_check(FoodoraRestaurant::receive($foodora)===$ack,'Foodora dispatch retries return same POS ID');
    premium_check($f['items'][0]['details'][1]['value']==='2 × Extra sås','Foodora restaurant toppings preserved');
    $changed=$foodora;$changed['products'][0]['quantity']='3';FoodoraRestaurant::status($fid,['status'=>'PRODUCT_ORDER_MODIFICATION_SUCCESSFUL','updatedOrder'=>$changed]);
    premium_check(ExternalOrders::view(ExternalOrders::record($fid))['changed'],'Foodora product changes require acknowledgement');
    FoodoraRestaurant::receive($foodora);premium_check(ExternalOrders::view(ExternalOrders::record($fid))['items'][0]['quantity']==='3','dispatch replay cannot overwrite modified products');
    FoodoraRestaurant::status($fid,['status'=>'ORDER_CANCELLED']);
    premium_check(ExternalOrders::view(ExternalOrders::record($fid))['state']==='archived','Foodora cancellation archives ticket');
    FoodoraRestaurant::receive($foodora);premium_check(ExternalOrders::view(ExternalOrders::record($fid))['state']==='archived','dispatch retry never resurrects cancellation');
    $bad=$foodora;$bad['callbackUrls']['orderAcceptedUrl']='https://test.invalid';premium_reject(fn()=>FoodoraRestaurant::receive($bad),'foodora_direct_flow');
    $bad=$foodora;$bad['callbackUrls']['orderPreparedUrl']='https://evil.invalid';premium_reject(fn()=>FoodoraRestaurant::normalize($bad,1),'foodora_callback');
    $remoteBadVenue=true;ExternalOrders::enqueue('wolt','fixture-wolt-1');ExternalOrders::process($id);
    premium_check(ExternalOrders::record($id)['error_code']==='venue_mismatch','wrong venue fails visibly');$remoteBadVenue=false;
    $p=BizPrint::signedPost(['description'=>'ÅÄÖ / TEST','printerId'=>7],'public','secret',1700000000);
    $d=json_decode($p,true);$hash=$d['hash'];unset($d['hash']);premium_check(hash('sha256',json_encode($d,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES).':secret')===$hash,'BizPrint POST signature matches transmitted JSON');
    $q=BizPrint::signedGet(['page'=>1],'pub key*','secret',1700000000);premium_check(str_contains($q,'publicKey=pub+key*'),'BizPrint GET uses URLSearchParams-compatible encoding');
    Connections::save('bizprint',['public_key'=>'synthetic-public','secret_key'=>'synthetic-secret','printer_id'=>7,'enabled'=>true]);
    premium_check(BizPrint::printers()[0]['id']===7,'printer discovery normalizes documented response');
    // Receipts require public HTTPS. Override only in this local test; HTTP never leaves the mock.
    $restFilter=static fn($url)=>str_replace('http://localhost:8097','https://test.invalid',$url);add_filter('rest_url',$restFilter);
    $job=BizPrint::send(0,'fixture-print-'.wp_generate_uuid4(),true);$jobIds[]=$job['id'];premium_check($job['job_id']===42&&$job['state']==='accepted','job acceptance is not claimed as physical printing');
    $last=end($http);$sent=json_decode($last[1]['body'],true);parse_str(parse_url($sent['url'],PHP_URL_QUERY),$params);
    $token=$params['token'];premium_check(str_contains(BizPrint::receiptForToken($token),'PROVUTSKRIFT'),'signed receipt capability serves correct document');
    premium_reject(fn()=>BizPrint::receiptForToken(str_repeat('a',64)),'receipt_missing');
    premium_check(BizPrint::refreshJob($job['id'])['state']==='done','print completion only follows provider job status');
    $key='fixture-once-'.wp_generate_uuid4();$once=BizPrint::send(0,$key,true);$jobIds[]=$once['id'];$calls=count($http);
    premium_check(BizPrint::send(0,$key,true)['replayed']&&count($http)===$calls,'repeated print key sends only one job');
    $failPrint=true;$key='fixture-timeout-'.wp_generate_uuid4();premium_reject(fn()=>BizPrint::send(0,$key,true),'print_uncertain');$calls=count($http);
    premium_reject(fn()=>BizPrint::send(0,$key,true),'print_uncertain');premium_check(count($http)===$calls,'uncertain submission is never retried blindly');$failPrint=false;
    remove_filter('rest_url',$restFilter);
    // Real Woo partial-feed regression.
    foreach(['healthy','broken'] as $label){$o=wc_create_order(['status'=>'processing','created_via'=>'yookds-premium-test']);$created[]=$o->get_id();$i=new WC_Order_Item_Product();$i->set_name('TEST '.$label);$i->set_quantity(1);$o->add_item($i);if($label==='broken')$o->update_meta_data(Orders::FLOW,'bad-workflow');$o->save();}
    $board=(new Orders())->board();premium_check(count($board['orders'])===1&&count($board['issues'])===1&&!$board['complete'],'one corrupt Woo order cannot hide healthy order');
    // Real REST dispatch enforces staff/manager split.
    wp_set_current_user(0);
    $r=rest_do_request(new WP_REST_Request('GET','/yookds/v1/integrations'));premium_check($r->get_status()===401||$r->get_status()===403,'anonymous cannot read integration settings');
    $request=new WP_REST_Request('POST','/yookds/v1/webhooks/wolt');$request->set_header('content-type','application/json');$request->set_body('{}');
    premium_check(rest_do_request($request)->get_status()===401,'unsigned webhook denied by REST permission callback');
    wp_set_current_user(1);$r=rest_do_request(new WP_REST_Request('GET','/yookds/v1/integrations'));
    premium_check($r->get_status()===200&&!str_contains(wp_json_encode($r->get_data()),'synthetic-secret'),'manager REST response never includes saved secret');
    WP_CLI::success($GLOBALS['premium_passed'].' premium checks passed using real WP/WC/database and mocked provider HTTP.');
} finally {
    remove_filter('pre_http_request',$filter,10);update_option(Connections::KEY,$old,false);
    foreach(['wolt','foodora','bizprint'] as $p){delete_transient('yookds_token_'.$p);delete_option('yookds_connection_check_'.$p);}
    foreach($created as $id){$o=wc_get_order($id);if($o)$o->delete(true);}
    foreach($externalIds as $id){$wpdb->delete(ExternalOrders::table(),['id'=>$id]);if(function_exists('as_unschedule_all_actions'))as_unschedule_all_actions('yookds_fetch_external',[$id],'yookds');}
    // Only synthetic test jobs, in an explicitly disposable local installation.
    foreach($jobIds as $id)$wpdb->delete(BizPrint::table(),['id'=>$id]);
    $wpdb->query('DELETE FROM '.BizPrint::table()." WHERE order_id=0 AND state='unknown'");
}
