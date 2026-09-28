<?php
if(!defined('WP_CLI')||wp_get_environment_type()!=='local')exit;
global $wpdb;$wpdb->query("DELETE FROM ".YooKDS\ExternalOrders::table()." WHERE remote_id IN ('visual-foodora','visual-wolt')");
foreach(wc_get_orders(['limit'=>-1,'created_via'=>'yookds-visual-test']) as $o){if($o->get_created_via()==='yookds-visual-test')$o->delete(true);}
