=== YooKDS for WooCommerce ===
Contributors: adamchaadaev
Tags: woocommerce, kitchen, kds, nx, wapf
Requires at least: 6.5
Requires PHP: 8.0
Stable tag: 1.2.0-beta.1
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
Uppgradera tidigare YooKDS i en testmiljö först. Kör inte flera YooKDS-versioner eller äldre YooKDS-snippets samtidigt.
Äldre separata nummer-/NX-snippets ersätts också här. Behåll WAPF aktiverat.
Öppna WooCommerce > YooKDS. Full guide: docs/PREMIUM-GUIDE.md.

== Changelog ==
= 1.2.0-beta.1 =
* BizPrint API, skrivarval, provutskrift, automatisk utskrift och jobbstatus.
* Foodora Restaurant POS (indirekt flöde) och Wolt Marketplace POS.
* Märkta externa kort, separata köksbiljetter och Uber Eats Under utveckling.
* Krypterade nycklar, signerade webhooks, skydd mot dubbletter.
* Partiellt orderfel döljer inte längre friska WooCommerce-order.

= 1.1.0-alpha.2 =
* Aktiva ordrar hämtas direkt efter Woo-status, inte från separat KDS-index.
* Utlämnad sätter alltid Woo-order till completed; fel skrivning kvitteras inte lokalt.
* Fyra siffror, fullständiga ID:n och verklig orderlänk på varje kort.
* WAPF-sparade val och custom fields på order/order­rader/nya produktfältssnapshots.
* NX-session och klassisk/Store API checkout-koppling, lokal QR till beställningssida.
* Testdubblar och UI-fixtures är separerade från installationspaketet.

== Limitations ==
Beta: lokalt testad med WordPress 7.1.2, WooCommerce 11.1.2, HPOS på/av.
Externa API-anrop har testats med simulerade leverantörssvar. Riktiga partnerkonton,
Foodora/Wolt-godkännande och fysisk BizPrint-utskrift behöver verifieras före drift.
Ingen meny-/lagersynk, automatisk acceptans, Uber Eats, offline eller flera skrivarrutter.
WAPF/Pro/YITH/Checkout Block måste testas med butikens riktiga versioner.
Avinstallation behåller data. Se docs/PREMIUM-GUIDE.md och docs/TEST-RESULTS.md.
