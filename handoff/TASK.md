# Prioritet 1: verkliga Woo-ordrar och QR-beställningar

## Kundens rapport — inte verifierad genom åtkomst till butiken

En genomförd beställning visas på NutsExpress-kvittot men inget motsvarande kort
syns i KDS. QR-kodslänkgenereringen rapporteras också ha problem och är central
för produkten. Kvittot i sig bevisar inte Woo-orderns interna status, fullständiga
ID, KDS-inloggningen eller svaret från KDS-API:t. Begär inte kundens hemligheter.

## Baseline och sådant som faktiskt kontrollerats i denna överlämning

- Senaste pluginet är alpha.2. Pluginfilerna matchar installations-ZIP:en (28 filer).
- PHP-kontrollerna kördes om: 139 passerade med uttryckliga WP/WC-testdubblar.
- JavaScript-kontrollerna kördes om: 35 passerade.
- Ett fel på en av två ordrar reproducerades: ogiltig `_yookds_workflow` på en order
  gör att hela `Orders::board()` kastar `invalid_workflow`, HTTP 422. Den andra,
  läsbara ordern returneras inte heller. Se `reproduce-board-abort.php` och resultat.
- Diagnosskriptets PHP-syntax är kontrollerad. Det är inte kört i kundens WordPress.
- Ingen riktig WordPress/WooCommerce, databas, checkout, cookieautentisering eller
  telefonkamera har verifierats i denna överlämning. Inget har installerats i butiken.

## Läs koden före ändring

`includes/Orders.php`: board hämtar öppna Woo-ordrar direkt. Varje rad går genom
sync, som använder lås och kan skriva KDS-arbetsmetadata. Board fångar bara 404 som
ett lokalt radfel; andra fel avbryter svaret. Börja med att återskapa detta i riktig Woo.

`includes/Lock.php`: GET_LOCK/RELEASE_LOCK krävs även under läsningen. Ett upptaget
eller otillgängligt lås ger 503; med nuvarande board kan det dölja alla kort.
Avskaffa inte skrivskyddet utan en genomtänkt lösning för samtidiga åtgärder.

`yookds-for-woocommerce.php`: om vissa legacy-snippet-konstanter är definierade
avbryts init. Kontrollera att sidan verkligen kör denna version och att gamla
footer-snippets inte har tagit över shortcode/meny/skript. Detta är en hypotes
om den aktuella installationen, inte något som har konstaterats där.

`includes/Rest.php` och `assets/js/kds.js`: följ request från riktig inloggad
webbläsare. Kontrollera status/body, nonce, rutt, schema/version/site/read_id,
Javascriptfel och att serverns fel inte presenteras som en tom orderlista.
Kontrollera också kanal-/kassafilter. Lägg inte till en offentlig orderfeed.

`assets/js/kds.js`, `assets/js/qr.js`, `includes/Assets.php`: QR-koden ritas endast
om `window.YooKDSQR` finns; annars kan en URL visas utan QR eller synlig varning.
Kontrollera att skriptet laddas i riktig WordPress, ordningen, optimering/cache,
dialogstöd, färdig URL, export och kopiering. Påstå inte att biblioteket saknas
hos kunden förrän nätverks-/konsoldata visar det.

`includes/NX.php`: spåra nx-parametrar på landningssidan, efter navigering, vid
klassisk checkout och Checkout Block, på den sparade ordern och i KDS-svaret.
Tester måste omfatta bordsbyte, vanlig webbordersession och flera beställningar
från samma expresskassa. Bevara kundens önskade kanal/ursprung utan gammalt bord.

## Acceptans — prioritera före nya funktioner

1. I en riktig isolerad WooCommerce-testbutik: ny öppen order ger exakt ett kort
   vid nästa lyckade uppdatering; det fullständiga Woo-ID:t är detsamma. Kort kräver
   inte ett separat tidigare skapat `kds_ticket`. Tom orderdatabas ger noll kort.
2. Pending, processing och on-hold samt avsedda egna öppna statusar provas. Completed,
   cancelled, refunded, failed och checkout-utkast finns aldrig bland aktiva kort.
3. Klar flyttar till Klara utan completed. Utlämnad sätter samma order till completed,
   ger synk på två skärmar och flyttar från aktiv vy till Historik. Misslyckad
   serverskrivning får inte visas som lyckad. Inga riktiga betalningar i tester.
4. Ett låsfel, en korrupt arbetsstatus eller en felaktig orderrad gömmer inte övriga
   ordrar utan förklaring. Visa specifik och säker varning; ofullständig data får
   aldrig presenteras som fullständigt synkroniserad.
5. Generera QR med kanal, kassa, bord, mode och svenska tecken. Avkoda SVG/raster
   och jämför med exakt avsedd URL. Prova separat utan query, med befintlig query,
   underkatalog/fragment, valfria fält, ändrade fält, saknat QR-skript och ogiltig URL.
6. Följ den avkodade länken i en ny session, lägg testorder, och kontrollera sparade
   `_nx_channel`, `_nx_station`, `_nx_table`, `_nx_mode` och motsvarande KDS-badges.
   Prova riktig classic checkout och Checkout Block, inte bara callbacks med doubles.
7. WAPF-val, fritext, custom fields och leveranssätt stämmer mot sparad Woo-order.
   Alla fyra siffernummer är visning; två fulla ID:n med samma suffix ger två kort.

## Leverans

- Källkod till GitHub på utvecklingsgren, inte bara ännu en zipfil på main.
- Separata commits och en pull request där tillgängligt; ingen automatisk merge.
- En begriplig rapport med reproducerat fel, exakt fix, verkliga testresultat och
  återstående butiksspecifika verifieringar. Inga "100 % synk"-påståenden utan bevis.
- Ett byggpaket kommer från den granskade grenen först efter verifiering.
