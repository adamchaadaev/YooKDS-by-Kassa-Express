<?php
namespace YooKDS;
if (!defined('ABSPATH')) { exit; }

final class Plugin {
    public function init(): void {
        if ((int) get_option('yookds_integrations_schema', 0) !== 1) { Integrations::install(); }
        (new Integrations())->init();
        (new ExternalOrders())->init();
        (new BizPrint())->init();
        (new Numbering())->init();
        (new NX())->init();
        (new Fields())->init();
        $orders = new Orders();
        $orders->init();
        (new Rest($orders))->init();
        (new Assets())->init();
        (new Shortcodes())->init();
        (new Admin())->init();
    }
}
