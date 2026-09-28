<?php
namespace YooKDS;
if (!defined('ABSPATH')) { exit; }

/** MySQL/MariaDB connection lock: fail closed rather than accept racing writes. */
final class Lock {
    public function run(int $orderId, callable $callback) {
        global $wpdb;
        $name = 'yookds:' . substr(hash('sha256', DB_NAME . ':' . $wpdb->prefix . ':' . $orderId), 0, 48);
        $got = $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 0)', $name));
        if ((string) $got !== '1') {
            throw new Problem('order_busy', 'Ordern uppdateras just nu, eller servern saknar stöd för orderlås. Försök igen; kontakta administratören om felet kvarstår.', 503);
        }
        try {
            return $callback();
        } finally {
            $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $name));
        }
    }
}
