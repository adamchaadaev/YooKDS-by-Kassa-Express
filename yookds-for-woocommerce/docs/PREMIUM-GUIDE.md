# YooKDS 1.2.0-beta.1 — installation och anslutningar

En gemensam köksskärm för WooCommerce, Foodora och Wolt. Foodora har rosa kort, Wolt blå kort och båda märks Extern beställning. Uber Eats visas med Under utveckling och kan inte aktiveras.

## Installera

1. Säkerhetskopiera och installera ZIP-filen i en testkopia av butiken: Tillägg → Lägg till nytt → Ladda upp tillägg.
2. WooCommerce 8.2+, WordPress 6.5+, PHP 8.0+ och PHP OpenSSL krävs. Stäng av gamla YooKDS-snippets och dubbla installationer.
3. Öppna WooCommerce → YooKDS. Använd Integrationer för anslutningar. Endast användare med manage_woocommerce kan hantera API-uppgifter. Kökspersonal behöver tilläggets orderbehörighet.
4. Produktion behöver publik HTTPS, fungerande WordPress schemaläggning/Action Scheduler samt undantag från sidcache för KDS och dess REST-rutter. Foodora behöver snygga permalänkar, inte ?rest_route=.
5. Prova en riktig testorder per källa samt en utskrift innan drift. Beta betyder att leverantörernas konton och skrivare ännu inte har verifierats i denna leverans.

## BizPrint

Skaffa ett BizPrint-konto och installera deras skrivarapp på datorn vid skrivaren. Skapa en API-anslutning i BizPrint.

1. Fyll i Public Key och Secret Key. Spara.
2. Hämta skrivare, välj köksskrivare och 58 eller 80 mm.
3. Aktivera, spara och gör en provutskrift.
4. Aktivera automatisk utskrift om nya beställningar ska skrivas ut direkt. Det finns även Skriv ut på orderkorten.

Mottaget av BizPrint är inte bevis på fysisk utskrift. Uppdatera jobbstatus och kontrollera skrivaren. Vid osäker kvittens skickas samma försök inte om automatiskt; kontrollera kön innan en ny kopia beställs. Utskriftslänkar använder slumpmässiga, tidsbegränsade nycklar. Innehållet rensas efter 24 timmar. Biljetten är en köksbiljett, inte ett bokförings- eller betalningskvitto. En gemensam standardskrivare stöds; avdelningsstyrning till flera skrivare ingår inte.

## Foodora — restaurangintegration

Be Foodora/Delivery Hero om Restaurant POS API med **indirekt flöde**. Det är inte Foodoras Quick Commerce/Local Shops API. Foodora måste aktivera och godkänna integrationen för restaurangen.

Ange API-användarnamn, API-lösenord, Secret, restaurangens Remote ID och miljö. Registrera panelens basadress hos Foodora. Middleware skickar POST /order/{remoteId} och PUT /remoteId/{remoteId}/remoteOrder/{remoteOrderId}/posOrderStatus till den basadressen. Autentisering använder signerad HS512-JWT med service=middleware.

Acceptera ordern i Foodoras handlarapp. KDS tar därefter emot produkter, nästlade tillval, instruktioner och hämtningstid. Avbokningar och produktändringar tas emot separat. Upprepad leverans av samma order skapar inget nytt kort. Klar skickar preparation-completed när Foodora har lämnat den callbacken; annars registreras Klar enbart i köket, enligt API-kontraktet. Utlämnad är lokal köksstatus. Direktflöde med acceptans/avvisning i KDS, menyer och lager stöds inte.

Staging: integration-middleware.stg.restaurant-partners.com. Produktion: integration-middleware.eu.restaurant-partners.com. Andra regioner stöds inte i denna version.

## Wolt

Kräver Wolt Marketplace POS-partneråtkomst och slutförd onboarding. Ange Client ID, Client Secret, Refresh token, Venue ID, webhook-hemlighet och miljö. Registrera panelens webhook-adress hos Wolt. Refresh tokens roteras automatiskt och sparas krypterat. Ett utgånget eller återkallat konto behöver återanslutas.

KDS verifierar WOLT-SIGNATURE, hämtar ordern från Wolt och kontrollerar restaurangtillhörigheten. Acceptera i Wolts handlarapp. Klar skickas till Wolt före lokal bekräftelse. Hämtning/avslut synkas från Wolt. Utlämnad i KDS arkiverar köksbiljetten lokalt. Paketordrar som inte kan tolkas fullständigt markeras för kontroll; kontrollera dessa i originalappen.

## Drift och data

WooCommerce-order skrivs via WooCommerce API och fungerar med HPOS respektive äldre orderlagring. Externa beställningar ligger i en separat kökstabell och skapar inga extra WooCommerce-köp, betalningar eller bokföringsposter. Extern kökshistorik sparas i 90 dagar. Namn, telefonnummer och leveransadresser från aggregatorer lagras inte; fria köksanteckningar kan ändå innehålla personuppgifter.

API-hemligheter krypteras med WordPress auth-salt och visas inte igen i inställningarna. Tomt lösenordsfält behåller sparat värde. Byte av WordPress salts kräver att anslutningarnas hemligheter anges igen. Avinstallation behåller inställningar, kökshistorik och WooCommerce-data för att undvika oavsiktlig dataförlust. Inga kunddata eller nycklar ingår i ZIP-filen.

Enstaka felaktiga WooCommerce-order blockerar inte övriga kort; skärmen visar ofullständig synkronisering. Vid anslutningsfel låses berörda externa åtgärder. YooKDS använder polling och kräver nätverk. Övervaka Action Scheduler och integrationsstatus. Ingen offlinekökshantering eller automatisk leverantörscertifiering ingår.

## Officiella API-referenser

- BizPrint: https://getbizprint.com/documentation/developer/
- Foodora/Delivery Hero restaurangflöde: https://developers.deliveryhero.com/documentation/pos.html
- Foodora inkommande order: https://integration-middleware.stg.restaurant-partners.com/apidocs/pos-plugin-api
- Foodora status/API: https://integration-middleware.stg.restaurant-partners.com/apidocs/pos-middleware-api
- Wolt: https://developer.wolt.com/docs/authentication20

Se TEST-RESULTS.md för verifierade tester och kvarvarande acceptansprov.
