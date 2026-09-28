# YooKDS 1.1.0-alpha.2 — arkitektur och integrationsgräns

## Datamodell

WooCommerce är enda orderregister. `Orders::board()` frågar `wc_get_orders()` efter
verkliga öppna shop_order-ID:n; varje rad laddas med WC_Order/metadata via WC CRUD.
Det finns inga testordrar, statiska kundkort, genererade biljetter eller demo-fallback
på denna kodväg. Skärmlistor lever bara i minnet och ersätts av serverbekräftade svar.

`_yookds_workflow` innehåller köksstate, revision, innehållshash, tider, varningsflagga
och en begränsad lista av kommandokvitton. `_yookds_state`, `_yookds_closed_at` och
`_yookds_search` är historikindex, INTE en separat källa för aktiva orders innehåll.
Historikens metadatafråga använder HPOS eller den särskilda legacy query-adaptern.
Historiken gäller ordrar som KDS observerat, inte alla historiska Woo-ordrar före installation.

Extern Woo-slutföring/annullering tar bort från aktiva; extern återöppning ger kontroll
före klarmarkering. Gamla alpha.1-arkiveringar av fortfarande öppna Woo-ordrar återkommer
för kontroll. Ingen automatisk återöppning av completed i Woo sker från KDS.

## Kommandon och felsvar

Alla kommando-ID:n är fullständiga Woo-order-ID:n. Visningsnumret är aldrig primärnyckel.
REST kräver inloggning, orderbehörigheter och WordPress normala cookie/REST-noncekontroll.
KDS-kommandon låses per order med anslutningsbundna MySQL/MariaDB-lås. Kontroll av revision,
snapshot-token och idempotens motverkar upprepade eller inaktuella KDS-kommandon.
Låset omfattar KDS-anrop, inte andra pluginers samtidiga skrivningar; Woo-transaktioner
med externa aktörer är ingen bevisad atomisk operation. Testa dessa racevillkor i integration.

Handover skriver Woo `completed` via update_status och läser tillbaka status innan
lokal kvittering som arkiverad. Om senare metadata-sparning misslyckas kan Woo-statusen
redan vara ändrad; nästa serveravstämning ska reparera KDS-index. Klienten säger inte
att timeout är bevis på rollback. Woo-mejl/andra hooks kan utlösas; KDS gör ingen betalning.

GET-svar har källa, versionsschema, butiksadress och en unik read_id som klienten
kontrollerar tillsammans med radernas struktur, fullständiga ID:n och öppna status.
No-store på klient/server. Detta är ett skydd mot fel/cachade svar, inte kryptografisk
attestering av datakällan. Det ersätter inte WordPress-inloggning eller serverbehörigheter.
Skärmen pollar, standard fem sekunder; det är inte en WebSocket/pushgaranti.
Över 500 öppna ordrar stoppas uttryckligen hellre än att ge en tyst delmängd.

## Tillval och egna fält

Fields läser WooCommerce get_formatted_meta_data för normala synliga orderradsfält.
Känd WAPF-container `_wapf_meta` kompletterar läsningen. Endast sparad label/value läses;
raw option-ID:n används inte som påhittade etiketter och formulärformler körs inte.
Dubbletter mot normal Woo-visning konsumeras per förekomst, inte globalt med en set.
Saknad adapter för WAPF-format ger varning och stopp; generisk okänd privat metadata
visas inte på chans. Ändrad läsbar tillvalsdata ingår i kökshash och återför klar order
till kontroll. Vissa format måste provas med riktiga WAPF-versioner innan drift.

Custom fields konfigureras med order/item/product-scope och faktisk metanyckel.
Produkt-/variantfält kopieras till orderraden vid checkout, bara en gång, med fallback
till förälder för ett saknat variantfält. Befintliga orderrader läser aldrig dagens
produktvärde som ett historiskt beställt val. Några komplexa ACF-objekt/filfält kräver
ny adapter. Alla etiketter och värden går till textContent, inte exekverbar HTML.
`yookds_item_details` och `yookds_order_details` erbjuder utvecklaradaptrar.

## NX och nummer

NX-fångst sker på frontend vid wp_loaded, med explicit session-init vid behov och
sanering. Sparade fält är _nx_channel/_nx_station/_nx_table/_nx_mode.
Hooks för klassisk checkout och Store API checkout ingår. Revision + tolv timmars TTL
minskar kvarhängande sessionstaggar, men olika samtidiga flikar delar Woo-session.
En fullständigt cachad sida som inte kör WordPress kan kringgå serverfångst: cacheundantag
är ett distributionskrav. NX är ursprungsinformation, ingen authorization eller prisregel.

Numbering använder Woo order_number-filtret och en separat liten kvittovisningsadapter.
Fyra sista siffror med inledande nollor är valbart men på som standard; kollisioner är
möjliga och påverkar varken fullständiga ID:n eller orderlänkar. QR byte-mode ECC-M
skapas lokalt i JS, utan externa nätanrop. Licensuppgifter finns i THIRD-PARTY-NOTICES.txt.

## Verifiering

Se TEST-RESULTS.md: isolerade kontroller är körda; verklig WordPress/Woo/HPOS,
WAPF/Pro, checkout, databaser och webbläsarcookies återstår att integrationstesta.
