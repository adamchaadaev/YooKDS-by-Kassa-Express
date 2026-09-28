<?php
namespace YooKDS;
if (!defined('ABSPATH')) { exit; }

final class Plugin {
    public function init(): void {
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
