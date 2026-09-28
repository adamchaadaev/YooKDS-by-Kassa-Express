# YooKDS by Kassa Express — alpha.2 development baseline

**Kända blockerande fel är rapporterade: ny Woo-order saknas i KDS och QR-flödet
behöver felsökas. Detta projekt är inte en godkänd release.**

Börja med [START-HERE.md](START-HERE.md), [AGENTS.md](AGENTS.md) och
[den prioriterade felsökningsuppgiften](handoff/TASK.md).

Pluginets källkod finns i `yookds-for-woocommerce/`. Den motsvarar senaste levererade
alpha.2, inte den äldre ZIP som låg på GitHub vid senaste kontrollen. Driftkoden är
oförändrad i den här överlämningen. Nya filer innehåller överlämning, en reproduktion
av ett fel med testdubblar samt ett WP-CLI-diagnosskript utan orderskrivningar.

GitHub-uppladdningen från chatten nekades (403). Inget är pushat där.

## Utveckling

```sh
php tests/compat.php
node tests/client.test.cjs
php handoff/reproduce-board-abort.php
python3 tools/build.py
```

De vanliga testerna använder testdubblar. Full integration kräver en verklig,
isolerad WordPress/WooCommerce-butik och databas. Läs säkerhetsvarningarna i
`tests/integration.php` och `yookds-for-woocommerce/docs/TESTPLAN.md` först.

`tools/diagnose-woo.php` läser en auktoriserad WordPress-installations diagnostik
via WP-CLI. Det kör inte KDS-board och ändrar inte orderdata eller inställningar.
Håll utskriften privat. Det är syntaxkontrollerat, inte kört i kundens butik.

Källkodens tester får aldrig användas som levande köksskärm. Lägg inte upp
WordPress-konfiguration, kundorderexporter, cookies, API-nycklar eller produktionsloggar.

## Bevara

Förbereds/Klara/Historik, Woo-ordern som datakälla, Utlämnad = completed på samma
fullständiga ID, fyrsiffriga visningsnummer, WAPF/custom fields och NX/QR.
Detaljerade acceptanskrav finns i AGENTS.md och handoff/TASK.md.
