<?php if (!defined('ABSPATH')) { exit; } ?>
<dialog class="kdsu-dialog kdsu-integrations" data-slot="integrations-dialog" aria-labelledby="yookds-integrations-title">
    <header class="kdsu-integrations-head"><div><span class="kdsu-eyebrow">YooKDS · Anslutningar</span><h2 id="yookds-integrations-title">Ett kök. Alla beställningar.</h2><p>Koppla dina leveranstjänster och låt köksbiljetterna hitta rätt skrivare.</p></div><button type="button" data-int="close" aria-label="Stäng integrationer">Stäng</button></header>
    <div data-slot="integration-message" role="status" class="kdsu-integration-message" hidden></div>
    <div class="kdsu-integration-grid" data-slot="integration-cards"></div>
    <section class="kdsu-print-history"><div class="kdsu-integrations-head"><h3>Senaste utskrifter</h3><button type="button" data-int="reload">Uppdatera</button></div><p>Mottaget betyder att BizPrint har tagit emot jobbet. Kontrollera status för att se om det är utskrivet.</p><div data-slot="print-jobs"></div></section>
    <footer class="kdsu-integrations-footer"><p>Externa beställningar hanteras som köksbiljetter. Betalning, bokföring och acceptans ligger kvar hos leveranstjänsten. Klar skickas till tjänsten när dess API stöder händelsen; Utlämnad registrerar överlämningen i KDS.</p><button type="button" data-int="retry">Försök synka väntande ordrar igen</button></footer>
</dialog>
