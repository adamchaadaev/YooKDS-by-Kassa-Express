<?php
/** Isolated reproduction using existing test doubles. NEVER load in WordPress. */
if (defined('ABSPATH')) { throw new RuntimeException('Do not run this test in WordPress.'); }
ob_start();
require dirname(__DIR__) . '/tests/compat.php';
ob_end_clean();
$one = $fixture;
$one['meta'] = [];
$one['woo_status'] = 'processing';
$one['order_number'] = '910001';
$one['created_at'] = time() - 60;
$two = $one;
$two['order_number'] = '910002';
$two['meta']['_yookds_workflow'] = 'invalid-scalar-for-reproduction-only';
$GLOBALS['store'] = [910001 => $one, 910002 => $two];
$GLOBALS['fail_meta'] = false;
$GLOBALS['fail_status'] = false;
$wpdb->granted = '1';
try {
    $feed = (new YooKDS\Orders())->board();
    echo 'Board returned ' . count($feed['orders']) . " rows. Reassess the documented failure path.\n";
} catch (YooKDS\Problem $error) {
    echo "REPRODUCED with WP/WC doubles, NOT the user's store.\n";
    echo "Two open orders: one readable, one invalid workflow value.\n";
    echo 'Entire board throws ' . $error->slug . ' (HTTP ' . $error->status . ").\n";
    echo "The readable order is not returned to the browser.\n";
    echo "This demonstrates a failure path, not the cause of the reported missing ticket.\n";
}
