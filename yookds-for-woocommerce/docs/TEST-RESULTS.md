# YooKDS 1.1.0-alpha.2 — testrapport

Datum: 2026-09-28. **Utvecklingsversion, inte godkänd för skarp servering.**

## Utfört i den här arbetsmiljön

| Kontroll | Resultat | Testgräns |
|---|---|---|
| PHP-domän, service, NX, nummer och tillvalsparser | 139 godkända kontroller | Explicita WordPress/WooCommerce/databas-testdubblar; inte en installerad butik |
| JavaScript | 35 godkända kontroller | URL, svarskontrakt, fullständiga ID:n, statusfilter, fältkonfiguration och kvittonummer |
| Chromium | 21 godkända kontroller | Riktig webbläsare och produktions-JS/CSS/template, men simulerade REST-svar |
| QR-algoritm | 176 exakta matrisjämförelser samt 4 gräns-/utdatakontroller | Jämförelse med Python qrcode 8.2: ECC M, byte mode, versioner 1–20, mask 0–7, UTF-8 och NX-länk |
| PHP-/JS-syntax | Godkänd | Samtliga PHP-filer och JS-filer i denna källkod |
| Paketgräns | Godkänd | Installationspaketet innehåller pluginet, inte testdubblar, testordrar eller browser-fixtures |

Testloggar och körbara tester finns i källkods-ZIP:ens `tests/`. De ska inte laddas
upp som webbplatsinnehåll eller köras i produktion. Inga kundwebbplatser eller
GitHub-repositories ändrades vid denna korrigering.

## Viktiga verifierade kodfall

En tom datakälla ger noll kort; inget i driftkoden skapar exempelordrar. Öppna
Woo-order-ID:n hämtas direkt utan krav på tidigare KDS-index. Samma sista fyra siffror
på två ordrar ändrar inte deras identitet. Klar ändrar bara köksstatus; Utlämnad
begär WooCommerce `completed` och kräver bekräftad lagring. Misslyckad WC-skrivning
får inte lokalt kvitteras som lyckad utlämning. Opålitligt/kachat/felaktigt ordersvar
avvisas; senaste lästa data markeras inaktuella och knappar låses.

WAPF-parsern har testats med sparad `_wapf_meta` som fält-ID → label/value/raw,
inklusive listor, upprepade val, JSON-representation, tomma val och värdena 0/false.
Okänd representation blir varning/stopp, inte påhittade tillval. Vanliga synliga
Woo-radmetadata läses först; WAPF-fallback dedupliceras mot dessa.
Konfigurerade order-/order­radsfält och produktfältssnapshot har isolerade tester.
Testerna omfattar NX-sessionens byten/utgång/checkout-hookregistrering — inte en
verklig betalningsleverantör eller genomförd browser-checkout.

## INTE verifierat här

Ingen installerad WordPress/WooCommerce-butik, ingen MySQL/MariaDB-server, inga
verkliga HTTP-inloggningscookies, inget installerat WAPF/Pro/YITH och ingen fysisk
QR-skanning/skrivare användes. HPOS på/av, Checkout Block, klassisk checkout och
butikens tillvalsformat måste integrationstestas med butikens faktiska versioner.
Den medföljande `tests/integration.php` är syntaxkontrollerad men **inte körd**.

139 tester betyder därför inte 100 % verifierad synk i användarens butik. Inte heller
är QR-matrisjämförelse bevis för att alla telefoner läser alla utskriftsstorlekar.

## Rapporterade påhittade kort i alpha.1

Den granskade installations-ZIP:en för alpha.1 innehöll ingen generator eller
reservlista för demoordrar. Tidigare förhandsvisningsbilder och separata browsertester
använde uttalade testdata. Utan den aktuella sidans URL, laddade skript och serversvar
är orsaken till de rapporterade korten i butiken inte fastställd.

Alpha.2 tar bort indexberoendet för aktiva ordrar och den avstängda standardkopplingen
vid utlämning. Varje kort visar Woo-ID och en orderlänk; sidfoten visar butikens adress.
Det går därmed att jämföra varje kort mot sin verkliga WooCommerce-order. Testordrar
som faktiskt redan finns i WooCommerce går inte att skilja från andra ordrar utan
specifik märkning och filtreras därför inte bort på chans.
