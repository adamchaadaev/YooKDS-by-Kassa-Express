<?php
namespace YooKDS;
if (!defined('ABSPATH')) { exit; }

final class Shortcodes {
    private static bool $rendered = false;
    public function init(): void {
        add_shortcode('kds_unified', [self::class, 'render']);
        add_action('init', [self::class, 'route']);
        add_filter('query_vars', static function ($vars) { $vars[] = 'yookds_screen'; return $vars; });
        add_action('template_redirect', [self::class, 'fullscreen']);
    }
    public static function route(): void { add_rewrite_rule('^kds/?$', 'index.php?yookds_screen=1', 'top'); }
    public static function url(): string { return add_query_arg('yookds_screen', '1', home_url('/')); }

    public static function render($atts = []): string {
        if (!Rest::allowed()) { return '<p>Logga in med behörighet att hantera butikens ordrar för att visa YooKDS.</p>'; }
        if (self::$rendered) { return '<p>En YooKDS-skärm per sida stöds i denna testversion.</p>'; }
        self::$rendered = true;
        if (!defined('DONOTCACHEPAGE')) { define('DONOTCACHEPAGE', true); }
        if (!headers_sent()) { nocache_headers(); }
        $atts = shortcode_atts(['theme' => 'light', 'initial' => 'board'], $atts, 'kds_unified');
        $theme = $atts['theme'] === 'dark' ? 'dark' : 'light';
        $initial = $atts['initial'] === 'archive' ? 'archive' : 'board';
        Assets::enqueue();
        ob_start();
        // Shortcodes and admin callbacks may render after the document head.
        if (did_action('wp_head') || did_action('admin_head')) { wp_print_styles('yookds-kds'); }
        require YOOKDS_PLUGIN_DIR . 'templates/board.php';
        return ob_get_clean();
    }

    public static function fullscreen(): void {
        if ((int) get_query_var('yookds_screen') !== 1) { return; }
        if (!is_user_logged_in()) { auth_redirect(); exit; }
        if (!Rest::allowed()) { wp_die('Du saknar behörighet till YooKDS.', '', ['response' => 403]); }
        $content = self::render();
        status_header(200);
        nocache_headers();
        ?><!doctype html><html <?php language_attributes(); ?>>
        <head><meta charset="<?php bloginfo('charset'); ?>"><meta name="viewport" content="width=device-width,initial-scale=1">
        <meta name="robots" content="noindex,nofollow"><title><?php echo esc_html(get_bloginfo('name')); ?> — YooKDS</title>
        <?php wp_head(); ?></head><body class="yookds-standalone"><?php echo $content; // Escaped in template. ?>
        <?php wp_footer(); ?></body></html><?php
        exit;
    }
}
