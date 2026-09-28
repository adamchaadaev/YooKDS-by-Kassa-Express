# YooKDS 1.1.0-alpha.2 — installation och provkörning

## Uppdatera, inte installera dubbelt

Använd först en testkopia med backup och testbetalning. Byt inte köksprogram mitt
under servering. Inaktivera äldre YooKDS/KDS Unified-snippets (UI, kontrollpanel,
arkiv och ordermotor), samt det gamla pluginet. Inaktivera också de fristående
fyrsiffriga ordernummer- och NX-session-snippets som nu ingår här. **Behåll WAPF och
andra faktiska produkttillvalsplugins aktiverade.** Radera inte Woo-ordrar eller gamla
inställningar för att bli av med dubbla kort.

Ladda upp `yookds-for-woocommerce-1.1.0-alpha.2.zip` som WordPress-tillägg och ersätt
den tidigare versionen i samma mapp. Aktivera endast en YooKDS-version.
Öppna **WooCommerce → YooKDS → Öppna fristående köksskärm**. Den fristående länken är
`?yookds_screen=1`; kortkoden är `[kds_unified]`. Kontrollera att version alpha.2 syns
på skrivbord och att sidfotens butiksadress är rätt. En förhandsvisningsbild eller
lokal HTML-fil är inte en ansluten köksskärm.

Kräver PHP 8.0+, WordPress 6.5+, aktiv WooCommerce 8.2+ och MySQL/MariaDB med
`GET_LOCK`/`RELEASE_LOCK`. HPOS-stöd finns i kodvägen men är inte testat mot databas här.
Använd administratör/butikschef, inte ett kundkonto. Undanta KDS-sida/REST-rutter och
inkommande `nx_`-länkar från sidcache. Töm cache och gör en fullständig omladdning efter
uppdatering. Gamla `kds_ticket` importeras inte; verkliga öppna Woo-ordrar läses direkt.

## Exakt orderregel

En öppen WooCommerce-order ger ett kort i Förbereds eller Klara. Inga exempelordrar
skapas av pluginet. Standardstatusarna pending, processing och on-hold visas, liksom
registrerade egna öppna statusar. Completed, cancelled, refunded, failed, trash och
checkout-utkast visas inte i den aktiva vyn. Ingen datumgräns gömmer äldre öppna ordrar.
Över 500 öppna ordrar ger ett tydligt kapacitetsfel, inte en tyst ofullständig lista.

**Klar:** flyttar kortet till Klara, utan Woo-slutföring.
**Utlämnad:** sätter alltid samma Woo-order till `completed` och flyttar till Historik.
Det är inte längre en valfri, avstängd inställning. WooCommerce-mejl och andra
slutföringskopplingar kan utlösas. KDS debiterar inte kunden; saknad betalningsmarkering
kräver uttrycklig bekräftelse före utlämning.
**Tillbaka:** återför en klar men fortfarande öppen order till Förbereds för kontroll.
En färdigbehandlad order återöppnas först i WooCommerce, inte bara på KDS-skärmen.

## WAPF och custom fields

WAPF-val i sparade orderradfält och vanlig synlig Woo-radmetadata läses automatiskt.
Fritext, flerval och tillval visas som text. Det är inte en ny prisberäkning och det
används inga val från dagens produktformulär i stället för kundens sparade val.
Om ett känt WAPF-datafält har en okänd representation blir kortet stoppat med varning.
Kontrollera då ordern i WooCommerce. Alla Pro-fält, filuppladdningar, speciallayout och
andra tilläggs lagringsformat är inte certifierade av denna testversion.

I **Inställningar → WAPF och anpassade fält**, använd en rad per fält:

```text
order|_leveranstid|Leveranstid
item|_extra_info|Extra information
product|koksinstruktion|Köksinstruktion
```

Detta är syntaxexempel: ersätt nycklarna med dem som verkligen används i butiken.
`order` läser sparad metadata på ordern. `item` läser sparad orderradsmetadata.
`product` kopierar konfigurerad produkt-/variantmetadata till nya checkout-orderrader;
lägg in denna mappning **före** beställningen. Äldre ordrar får inte dagens
produktvärde i efterhand. API-/POS-skapade ordrar utanför checkout behöver motsvarande
snapshot-koppling; detta är inte en automatisk historisk migration.

För WooCommerce Additional Checkout Fields kan den sparade nyckeln exempelvis vara
`_wc_other/namnrymd/falt`. Välj bara fält som köket behöver. Privata ordermetadata
visas inte automatiskt; autentiserings- och betalningsnycklar blockeras även i
fältmappningen. Komplexa ACF-relationer/objekt och externa filformat kräver särskild
adapter. Exportera aldrig en full kundorder med känsliga uppgifter för felsökning.

## Nummer och QR/NX

Fyrsiffrigt visningsnummer är på som standard: 27080 → 7080, 7 → 0007. WooCommerce
order-ID ändras aldrig. Order-ID:t står på kortet och används för varje knapptryck.
Två ordrar kan ha samma fyra sista siffror — de slås inte ihop. Inställningen påverkar
WooCommerce-visningsnummer globalt samt de tre befintliga NutsExpress-kvittoelementen.

**QR-länkar** skapar en meny-/produktlänk med `nx_channel`, `nx_station`, `nx_table`
och `nx_mode`, samt QR som SVG lokalt i webbläsaren. Inga data skickas till en
extern QR-tjänst. Ange den verkliga beställningssidans adress; generatorn skapar
inte en meny eller kassa i sig. Fältet `nx_station` avser kassans ursprung, inte
Grill/Bar som separat köksarbetsstation.

NX-värden sparas via Woo-session till motsvarande `_nx_`-orderfält. Kodvägar för
klassisk checkout och Checkout Block ingår. Kontexten har tolv timmars giltighet
och rensas efter registrerad beställning; ett nytt bords QR måste skannas för nästa
fristående beställning. `?nx_reset=1` rensar en kvarvarande kontext.
Flera samtidiga beställningsflikar delar Woo-session: testa särskilt det arbetsflödet.
NX är inte en behörighet, rabatt, betalningsmarkering eller verifiering av gästens bord.

## Godkänn dessa tester i butiken

1. Jämför listan av öppna Woo-ordrar med korten i KDS. Varje kort ska länka till sin
   Woo-order. Färdigbehandlade och avbrutna ordrar ska inte finnas i aktiva vyn.
2. Lägg en ny testbeställning genom den riktiga checkouten: variation, WAPF-flerval,
   fritext, kundnotering och ett konfigurerat custom field. Alla sparade värden ska
   stämma rad för rad. Öppna två köksskärmar och jämför.
3. Tryck Klar: båda skärmarna ska visa Klara vid nästa lyckade avstämning, standard
   fem sekunder. Woo-ordern ska ännu inte vara färdigbehandlad.
4. Tryck Utlämnad: samma Woo-ID ska få `completed`, kortet ska lämna aktiva vyn och
   finnas i Historik. Kontrollera att inget annat order-ID ändrades.
5. Ändra antal/tillval/notering i Woo när kortet är klart. Det ska återgå till kontroll
   i Förbereds. Avbryt/återbetala en testorder: terminal status ska inte ligga aktiv.
   En delåterbetalning ska varnas om utan att hela ordern godtyckligt försvinner.
6. Skanna QR för bord 18 i en ny session, beställ och verifiera `_nx_table=18` och kanal
   på den sparade ordern samt i KDS. Upprepa via Checkout Block/klassisk checkout som
   faktiskt används. Testa byte från QR till vanlig webblänk utan kvarhängande bord.
7. Bryt nätet: senaste data får vara kvar men tydligt inaktuella, med låsta åtgärder.
   Ingen reservlista eller offlinekö ska dyka upp. Återanslut och jämför serverläget.
8. Prova felsvar, två klarmarkeringar nära varandra, olika roller, ogiltig/utgången
   nonce och cache. En vanlig kund får inte läsa orderdata. Ett timeoutmeddelande
   betyder inte att en skrivning säkert misslyckats; kontrollera serverordern.

## Utvecklartester

`php tests/compat.php`, `node tests/client.test.cjs`, `php tests/render.php >
tests/fixtures/board.html`, `python tests/browser.py` och `python tests/qr.test.py`
körs i källkodspaketet. De vanliga testerna arbetar med uttryckliga testdubblar.

Det riktiga integrationstestet körs ENDAST i en **tom, disponibel** WordPress/Woo-testbutik
med externa anrop, mejl, utskrifter och riktiga betalningar blockerade. Testet kräver
`WP_ENVIRONMENT_TYPE` local/development/staging och avbryter om butiken innehåller
registrerade ordrar. Det skapar/raderar bara egna tydligt märkta testordrar. Kör aldrig
skriptet på kundens driftbutik. HPOS på/av provas i separata tomma installationer.

```sh
YOOKDS_INTEGRATION=1 wp eval-file /sokvag/till/kallkod/tests/integration.php --user=ADMIN_ID
```

## Återställning och kvarstående gränser

Avaktivering/avinstallation tar inte bort Woo-orderdata eller KDS-metadata.
Redan genomförda Woo-statusändringar återställs inte genom att byta pluginversion.
Återgång till alpha.1 kan ge annat arbetsflöde, särskilt kring öppna arkiverade ordrar.

Separat Grill/Bar-klarmarkering, kategoriroutning, Expo, offentlig Order Board,
BizPrint/termisk skrivare och aggregatorintegrationer ingår inte i denna milstolpe.
Orderkort kan skrivas ut genom webbläsarens vanliga utskriftsdialog.
