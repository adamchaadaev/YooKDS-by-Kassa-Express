<?php
if (!defined('ABSPATH')) { exit; }
?>
<section id="kdsu-root" class="kdsu-root" data-theme="<?php echo esc_attr($theme); ?>" data-initial="<?php echo esc_attr($initial); ?>" aria-label="YooKDS köksskärm">
    <header class="kdsu-header">
        <div class="kdsu-brand"><span class="kdsu-mark">Yoo</span><div><h1><?php echo esc_html(get_bloginfo('name')); ?></h1><p>Köksskärm · Kassa Express <span class="kdsu-alpha"><?php echo esc_html(YOOKDS_VERSION); ?></span></p></div></div>
        <nav class="kdsu-toolbar" aria-label="Skärmkontroller">
            <button type="button" data-ui="view">Historik</button>
            <button type="button" data-ui="refresh">Uppdatera</button>
            <button type="button" data-ui="theme" aria-label="Växla ljust och mörkt tema">◐ Tema</button>
            <button type="button" data-ui="sound" aria-pressed="false">Ljud av</button>
            <?php if (current_user_can('manage_woocommerce')) : ?><button type="button" data-ui="integrations">Integrationer</button><button type="button" data-ui="links">QR-länkar</button><button type="button" data-ui="settings">Inställningar</button><?php endif; ?>
            <button type="button" data-ui="fullscreen" aria-label="Fullskärm">⛶</button>
        </nav>
    </header>
    <div class="kdsu-syncbar"><span data-slot="connection" role="status">Ansluter till WooCommerce…</span><span data-slot="last-sync"></span></div>
    <div class="kdsu-error" data-slot="error" role="alert" hidden></div>
    <div class="kdsu-warning" data-slot="backfill" role="status" hidden></div>
    <div class="kdsu-feedback" data-slot="feedback" role="status" hidden></div>
    <div class="kdsu-stats" data-slot="stats"></div>
    <nav class="kdsu-filters" data-slot="filters" aria-label="Filtrera beställningskanal"></nav>
    <nav class="kdsu-filters" data-slot="origins" aria-label="Filtrera kassa"></nav>
    <div data-view="board" class="kdsu-content">
        <section class="kdsu-column" aria-label="Förbereds"><header><h2>Förbereds <span data-slot="prep-count">0</span></h2><small>Äldst först</small></header><div class="kdsu-list" data-slot="preparing"></div></section>
        <section class="kdsu-column" aria-label="Klara"><header><h2>Klara <span data-slot="ready-count">0</span></h2><small>Väntar på utlämning</small></header><div class="kdsu-list" data-slot="ready"></div></section>
    </div>
    <section data-view="archive" hidden aria-label="Historik">
        <div class="kdsu-archive-head"><div><h2>Historik</h2><small>Period avser arkivering. Nyast skapade order först.</small></div>
            <form data-ui="search"><label>Period <select name="range"><option value="today">Idag</option><option value="yesterday">Igår</option><option value="week">Senaste 7 dagarna</option><option value="month">Denna månad</option></select></label>
            <label>Sök <input name="q" type="search" maxlength="100" placeholder="Order, bord, vara eller notering"></label><button type="submit">Sök</button></form>
        </div>
        <div class="kdsu-archive-list" data-slot="archive"></div><h3 class="kdsu-external-archive-title">Externa beställningar</h3><div class="kdsu-archive-list" data-slot="external-archive"></div>
        <nav class="kdsu-pager" aria-label="Historiksidor"><button type="button" data-ui="prev">Föregående</button><span data-slot="page">Sida 1 av 1</span><button type="button" data-ui="next">Nästa</button></nav>
    </section>
    <footer class="kdsu-foot">WooCommerce + externa beställningar · Ett gemensamt köksflöde · <?php echo esc_html(home_url('/')); ?></footer>
    <?php if (current_user_can('manage_woocommerce')) : ?>
    <?php include YOOKDS_PLUGIN_DIR . 'templates/integrations.php'; ?>
    <dialog class="kdsu-dialog" data-slot="settings-dialog" aria-labelledby="yookds-settings-title">
        <form data-ui="settings-form"><h2 id="yookds-settings-title">Inställningar</h2>
        <p>En gemensam köksvy med NX-kanaler, kassa och bord. Separat klarmarkering per köksstation ingår inte ännu.</p>
        <p>Öppna WooCommerce-ordrar visas automatiskt. Färdigbehandlade, avbrutna, misslyckade, återbetalade ordrar och utkast visas inte bland aktiva kort.</p>
        <label class="kdsu-field">Uppdatera var <input type="number" name="poll_seconds" min="3" max="60" required> sekund</label>
        <p class="kdsu-warning"><strong>Utlämnad sätter alltid samma WooCommerce-order till Färdigbehandlad.</strong> Det kan utlösa WooCommerce-mejl och andra tillägg. Klar ändrar bara köksläget. KDS debiterar inte kunden.</p>
        <label class="kdsu-field"><input type="checkbox" name="four_digit_numbers"> Visa exakt fyra siffror i ordernummer och NutsExpress-kvitto</label>
        <small>Visningsnummer är inte unika. Alla åtgärder använder WooCommerce-orderns fullständiga ID.</small>
        <details><summary>WAPF och anpassade fält</summary>
            <p>Synliga orderradstillval och sparade WAPF-val läses automatiskt. Lägg till övriga fält med en rad per fält:</p>
            <code>order|_leveranstid|Leveranstid</code><br><code>item|_extra_info|Extra information</code><br><code>product|koksinstruktion|Köksinstruktion</code>
            <label class="kdsu-field">Källa | metanyckel | etikett<textarea name="custom_fields" rows="5" spellcheck="false" placeholder="order|_leveranstid|Leveranstid"></textarea></label>
            <small><strong>order</strong>: orderfält. <strong>item</strong>: orderradsfält. <strong>product</strong>: produkt/variantfält som kopieras till nya checkout-ordrar. Äldre ordrar får inte dagens produktvärde i efterhand. För Checkout Block-fält kan metanyckeln vara <code>_wc_other/namnrymd/falt</code>. Interna betalnings- och säkerhetsfält blockeras.</small>
        </details>
        <details><summary>Kanaler: namn och färger</summary>
            <label class="kdsu-field">Fast ID | namn | färg<textarea name="channels" rows="4" spellcheck="false"></textarea></label>
            <small>Exempel: <code>table|QR / Bord|#8b5cf6</code>. Ändra namnet utan att byta ID. Okända kanaler visas också — inga ordrar filtreras bort automatiskt.</small>
        </details>
        <p data-slot="settings-error" role="alert" hidden></p>
        <div class="kdsu-dialog-actions"><button type="button" data-ui="close-settings">Avbryt</button><button type="submit" class="primary">Spara</button></div>
        </form>
    </dialog>
    <dialog class="kdsu-dialog" data-slot="links-dialog" aria-labelledby="yookds-links-title">
        <form data-ui="links-form"><h2 id="yookds-links-title">QR-länk till beställning</h2>
            <p>Länken öppnar din meny eller produkt och sparar kanal, kassa och bord på beställningen. QR-koden skapas lokalt, utan extern QR-tjänst.</p>
            <label class="kdsu-field">Meny- eller produktadress<input name="base" type="url" required></label>
            <label class="kdsu-field">Kanal<select name="channel"><option value="table">QR / Bord</option><option value="express">Express</option><option value="web">Online</option></select></label>
            <label class="kdsu-field">Kassa / ursprung<input name="station" type="text" maxlength="100" placeholder="t.ex. 1"></label>
            <label class="kdsu-field">Bord<input name="table" type="text" maxlength="100" placeholder="t.ex. 18"></label>
            <label class="kdsu-field">Läge<select name="mode"><option value="">Standard</option><option value="kiosk">Kiosk</option><option value="web">Webb</option></select></label>
            <label class="kdsu-field">Färdig länk<input name="result" type="url" readonly></label>
            <p data-slot="link-error" role="alert"></p><div data-slot="qr" class="kdsu-qr"></div>
            <div class="kdsu-dialog-actions"><button type="button" data-ui="close-links">Stäng</button><button type="button" data-ui="copy-link">Kopiera</button><a class="kdsu-qr-save" data-ui="save-qr" hidden>Spara QR (SVG)</a></div>
            <small>Skanna ett annat bords QR-kod för att byta bord. Utan sidladdning till WordPress kan sidcache hindra registreringen; undanta nx_-länkar från cache. QR-parametrar är inte en inloggning eller säkerhetskontroll.</small>
        </form>
    </dialog>
    <?php endif; ?>
</section>
