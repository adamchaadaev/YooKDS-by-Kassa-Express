<?php
namespace YooKDS;
if (!defined('ABSPATH')) { exit; }

/** NX describes order origin, never authorization, pricing, payment or kitchen routing. */
final class NX {
    public const KEYS=['nx_channel','nx_station','nx_table','nx_mode'];
    public static function merge(array $old, array $query): array {
        $new=[];
        foreach (self::KEYS as $key) {
            $value=$old[$key] ?? '';
            $new[$key]=is_scalar($value) ? sanitize_text_field((string)$value) : '';
        }
        if (isset($query['nx_reset']) && is_scalar($query['nx_reset']) && (string)$query['nx_reset']==='1') { $new=array_fill_keys(self::KEYS,''); }
        $provided=[];
        foreach (self::KEYS as $key) {
            if (array_key_exists($key,$query) && is_scalar($query[$key])) {
                $value=trim(sanitize_text_field(wp_unslash((string)$query[$key])));
                $provided[$key]=in_array($key,['nx_channel','nx_mode'],true) ? substr(sanitize_key($value),0,64) : substr($value,0,100);
            }
        }
        if (isset($provided['nx_channel'])) {
            $aliases=['qr'=>'table','online'=>'web','kiosk'=>'express'];
            $provided['nx_channel']=$aliases[$provided['nx_channel']] ?? $provided['nx_channel'];
            // A new explicitly attributed visit must not inherit the previous table/register.
            if ($provided['nx_channel']!==$new['nx_channel'] || $provided['nx_channel']!=='table') {
                $new['nx_station']=''; $new['nx_table']=''; $new['nx_mode']='';
            }
        }
        if (isset($provided['nx_table']) && $provided['nx_table']!=='' && !array_key_exists('nx_channel',$provided)) {
            $provided['nx_channel']='table';
            if ($provided['nx_table']!==$new['nx_table']) { $new['nx_station']=''; }
        }
        return array_replace($new,$provided);
    }
    private static function session() { return function_exists('WC') && WC() ? WC()->session : null; }
    public static function capture(): void {
        if (is_admin() || (defined('REST_REQUEST') && REST_REQUEST) || (defined('WP_CLI') && WP_CLI)) { return; }
        if (!array_intersect(array_merge(self::KEYS,['nx_reset']),array_keys($_GET))) { return; }
        if (!function_exists('WC') || !WC()) { return; }
        if (!WC()->session && method_exists(WC(),'initialize_session')) { WC()->initialize_session(); }
        $session=self::session(); if (!$session) { return; }
        $old=[]; foreach (self::KEYS as $key) { $old[$key]=$session->get($key,''); }
        $new=self::merge($old,$_GET);
        foreach ($new as $key=>$value) { $session->set($key,$value); }
        $session->set('yookds_nx_captured_at',time());
        $session->set('yookds_nx_revision',wp_generate_uuid4());
        if (!headers_sent() && method_exists($session,'set_customer_session_cookie')) { $session->set_customer_session_cookie(true); }
        if (!defined('DONOTCACHEPAGE')) { define('DONOTCACHEPAGE',true); }
        if (!headers_sent()) { nocache_headers(); }
    }
    public static function attach($order): void {
        if (!($order instanceof \WC_Order)) { return; }
        $session=self::session(); if (!$session) { return; }
        $time=(int)$session->get('yookds_nx_captured_at',0);
        if ($time && time()-$time>12*HOUR_IN_SECONDS) { self::clear(); return; }
        $context=[]; foreach(self::KEYS as $key) { $context[$key]=$session->get($key,''); }
        $context=self::merge([], $context);
        $hasContext=(bool)array_filter($context,static fn($value)=>$value!=='');
        if (!$hasContext) { return; } // Never erase existing POS/API/order-pay attribution with an empty session.
        foreach ($context as $key=>$value) { $order->update_meta_data('_'.$key,$value); }
        $order->update_meta_data('_yookds_nx_revision',(string)$session->get('yookds_nx_revision',''));
    }
    public static function processed($order): void {
        if (!($order instanceof \WC_Order)) { return; }
        $session=self::session(); if (!$session) { return; }
        // Do not clear a newer scan that arrived in another tab while checkout was processing.
        $revision=(string)$order->get_meta('_yookds_nx_revision',true,'edit');
        if ($revision!=='' && $revision===(string)$session->get('yookds_nx_revision','')) { self::clear(); }
    }
    private static function clear(): void {
        $session=self::session(); if (!$session) { return; }
        foreach(array_merge(self::KEYS,['yookds_nx_revision','yookds_nx_captured_at']) as $key) { $session->__unset($key); }
    }
    public function init(): void {
        // Run after WooCommerce frontend initialization; explicitly initialize a missing session.
        add_action('wp_loaded',[self::class,'capture'],30);
        add_action('woocommerce_checkout_create_order',[self::class,'attach'],30,1);
        add_action('woocommerce_store_api_checkout_update_order_from_request',static function($order,$request):void {
            self::attach($order); $order->save();
        },30,2);
        add_action('woocommerce_checkout_order_processed',static function($id,$posted,$order):void { self::processed($order); },100,3);
        add_action('woocommerce_store_api_checkout_order_processed',[self::class,'processed'],100,1);
    }
}
