# Testresultat — 1.2.0-beta.1

Verifierat 2026-09-29 i en isolerad lokal Docker-installation. Ingen driftbutik ändrades.

- WordPress 7.1.2, WooCommerce 11.1.2, PHP 8.2, MariaDB 11.
- PHP-syntax: samtliga 24 PHP-filer i pluginet passerade.
- 139 PHP-kontroller med uttryckliga WordPress/WooCommerce-testdubblar passerade.
- 38 JavaScript-kontroller passerade, inklusive negativa externa ID:n, kollisioner, egna mottagningsstatusar och URL-/QR-länklogik.
- 17 verkliga WooCommerce-/databaskontroller passerade med HPOS av, och 17 med HPOS på. Mottagning, tillval, statusändringar, stale revisions, idempotens, utlämning och historik ingår. Ett HPOS-omprov fick tillfällig låskonflikt med den samtidigt pollande webbläsaren; sluttestet kördes med pollningen pausad. Låsfelet rapporterades, inte som framgång.
- 55 premiumkontroller passerade med verklig WordPress/WooCommerce/databas och **simulerade externa HTTP-svar**. Kryptering, behörighet, Wolt-signatur och tokenrotation, Foodora HS512-JWT, restaurangorder/tillval/ändringar/avbokningar, dubbletter, misslyckad Klar, BizPrint-signering, skrivare, kvittens, receptlänk, jobbstatus och osäker utskrift ingår.
- Inloggad lokal webbläsare hämtade den riktiga REST-orderlistan. Woo-, Foodora- och Wolt-kort samt anslutningsformulär inspekterades. Förhandsbilden använder endast syntetiska lokala testordrar; dessa rensades efter granskning. Ingen heltäckande automatiserad webbläsarsvit eller fysisk pekskärmsprovning påstås.

## Inte verifierat i denna leverans

Riktiga Foodora/Wolt-partnerkonton, leverantörsgodkännande, skarpa webhook-leveranser, fysisk BizPrint-skrivare, verkliga betalningar, butikens WAPF/Pro/YITH-versioner, komplett Checkout Block/QR-till-order-flöde, installationer på samtliga äldre minimiversioner eller full belastnings-/tillgänglighetsrevision.

Kör partnernas onboarding och acceptansprov i testmiljö före drift. Denna release benämns därför beta. Tidigare alpha.2-resultat under projektets tests/ och handoff/ är historisk dokumentation, inte ersättning för dessa prov.
