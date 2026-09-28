<?php
// Render the real plugin template with presentation-only test doubles.
define('ABSPATH', __DIR__ . '/');
define('YOOKDS_VERSION','1.1.0-alpha.2');
function home_url($path='/'){return 'https://fixture.test'.$path;}
function esc_attr($s) { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
function esc_html($s) { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
function get_bloginfo($s) { return 'Kassa Express · Testkök'; }
function current_user_can($cap) { return true; }
$theme='light'; $initial='board';
?><!doctype html><html lang="sv"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>YooKDS UI test fixture</title><link rel="stylesheet" href="/yookds-for-woocommerce/assets/css/kds.css"></head><body style="margin:0">
<?php require dirname(__DIR__) . '/yookds-for-woocommerce/templates/board.php'; ?>
<script>window.YooKDSBoot={rest:location.origin+'/wp-json/yookds/v1/',nonce:'fixture-not-a-real-nonce',timezone:'Europe/Stockholm',can_configure:true,version:'1.1.0-alpha.2',site:'https://fixture.test/',source:'woocommerce',schema:2,settings:{receive_statuses:['pending','processing','on-hold'],poll_seconds:3,complete_on_handover:true,four_digit_numbers:true,custom_fields:[],channels:[{id:'table',name:'QR / Bord',color:'#8b5cf6'},{id:'web',name:'Online',color:'#1152ac'},{id:'express',name:'Express',color:'#0e7a0e'}]}};</script>
<script src="/yookds-for-woocommerce/assets/js/kds.js"></script></body></html>
