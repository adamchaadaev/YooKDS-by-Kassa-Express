=== YooKDS for WooCommerce ===
Contributors: adamchaadaev
Tags: woocommerce, kitchen, kds, nx, wapf
Requires at least: 6.5
Requires PHP: 8.0
Stable tag: 1.1.0-alpha.2
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/old-licenses/gpl-2.0.html

Utvecklingsversion. Endast för testmiljö tills butikens integrationstester är godkända.

== Description ==
WooCommerce-baserat KDS. En riktig öppen order = ett kort.
Förbereds, Klara, Historik; obligatorisk completed-status i Woo vid Utlämnad.
Inga exempelordrar eller generatorer i installationspaketet.
Sparade WAPF-/Woo-tillval, konfigurerade custom fields, fyra siffrors visningsnummer,
NX-kanaler/kassa/bord och lokal QR-generator. Inloggning och orderbehörighet krävs.

== Installation ==
Byt ut alpha.1. Kör inte flera YooKDS-versioner eller äldre YooKDS-snippets samtidigt.
Äldre separata nummer-/NX-snippets ersätts också här. Behåll WAPF aktiverat.
Öppna WooCommerce > YooKDS. Full guide: docs/TESTPLAN.md.

== Changelog ==
= 1.1.0-alpha.2 =
* Aktiva ordrar hämtas direkt efter Woo-status, inte från separat KDS-index.
* Utlämnad sätter alltid Woo-order till completed; fel skrivning kvitteras inte lokalt.
* Fyra siffror, fullständiga ID:n och verklig orderlänk på varje kort.
* WAPF-sparade val och custom fields på order/order­rader/nya produktfältssnapshots.
* NX-session och klassisk/Store API checkout-koppling, lokal QR till beställningssida.
* Testdubblar och UI-fixtures är separerade från installationspaketet.

== Limitations ==
Kodvägar för HPOS/legacy finns men är inte verifierade mot installerad Woo här.
WAPF/Pro/YITH/Checkout Block måste testas med butikens riktiga versioner.
Separata köksstationer, Expo, aggregatorer, publik Order Board och BizPrint saknas.
Polling, inget offlinearbete. Högst 500 öppna ordrar innan uttryckligt kapacitetsfel.
Avinstallation raderar inte order- eller KDS-data. Se docs/TEST-RESULTS.md.
