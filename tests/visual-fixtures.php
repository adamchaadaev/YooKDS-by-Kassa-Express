<?php
if(!defined('WP_CLI')||wp_get_environment_type()!=='local')exit;
use YooKDS\{ExternalOrders,FoodoraRestaurant,Workflow};
global $wpdb;
foreach(['foodora','wolt'] as $p){
$d=$p==='foodora'?['token'=>'visual-foodora','code'=>'F-2401','createdAt'=>gmdate('c',time()-240),'products'=>[['name'=>'Falafeltallrik','quantity'=>2,'comment'=>'Sås separat','selectedToppings'=>[['name'=>'Extra hummus','quantity'=>1]]]],'comments'=>['customerComment'=>'Utan lök'],'delivery'=>['riderPickupTime'=>gmdate('c',time()+720)]]:['id'=>'visual-wolt','order_number'=>'W-1042','order_status'=>'production','created_at'=>gmdate('c',time()-360),'pickup_eta'=>gmdate('c',time()+480),'items'=>[['name'=>'Smashburgare','count'=>2,'options'=>[['name'=>'Bröd','value'=>'Glutenfritt','count'=>1],['name'=>'Tillval','value'=>'Extra ost','count'=>2]]]],'consumer_comment'=>'Ingen salt på pommes'];
$wpdb->insert(ExternalOrders::table(),['identity_hash'=>hash('sha256','visual-'.$p),'provider'=>$p,'venue'=>'visual','remote_id'=>'visual-'.$p,'environment'=>'sandbox','snapshot'=>'{}','flow'=>'{}','updated_at'=>time()]);$id=$wpdb->insert_id;
$s=ExternalOrders::normalize($p,$d,$id);$f=Workflow::reconcile(null,$s,['processing'],time());ExternalOrders::persist($id,$s,$f,['pending'=>0]);
}
$o=wc_create_order(['status'=>'processing','created_via'=>'yookds-visual-test']);$i=new WC_Order_Item_Product();$i->set_name('Margherita');$i->set_quantity(1);$i->add_meta_data('Tillval','Extra mozzarella');$o->add_item($i);$o->set_customer_note('Skär i 8 bitar');$o->update_meta_data('_nx_channel','Webb');$o->save();WP_CLI::success('Local visual fixtures only.');
