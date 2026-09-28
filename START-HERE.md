# Fortsätt i Codex — YooKDS

Detta är ett **källkodsprojekt**, inte en ny utlovad fix eller en färdig release.
De 28 filerna under `yookds-for-woocommerce/` är byte för byte samma som i den
senast levererade installationsfilen `yookds-for-woocommerce-1.1.0-alpha.2.zip`.
Vi bevarar alltså felrapporterna och en reproducerbar utgångspunkt i stället för
att ersätta den med en ny visuell prototyp.

## GitHub

Mål: `https://github.com/adamchaadaev/YooKDS-by-Kassa-Express`

Vid den senaste läsningen innehöll main fortfarande LICENSE, README.md och den
äldre `yookds-for-woocommerce.zip`. Försöket att skapa grenen
`fix/woo-sync-qr-alpha2` stoppades med 403, "Resource not accessible by integration".
Inget har pushats eller ändrats i repositoryt från den här chatten.

## Så börjar du

1. Packa upp projektet till en mapp på din Mac.
2. Välj Codex i skrivbordsappen och öppna denna lokala projektmapp.
3. Klistra in uppgiften nedan. GitHub-inloggning kan behövas för push.

> Läs AGENTS.md och handoff/TASK.md. Detta är senaste YooKDS-källkoden, inte den
> gamla ZIP som ligger på main. Klona adamchaadaev/YooKDS-by-Kassa-Express till en
> separat arbetsmapp, kontrollera befintliga ändringar och importera denna källkod
> på en ny utvecklingsgren utan att ändra main. Behåll repositoryts LICENSE och
> befintliga historik; byt inte licens. Gör källkodsimporten till en egen commit.
> Publicera grenen med min vanliga GitHub-auktorisering. Skriv ut länken först
> när push faktiskt har lyckats. Börja därefter reproducera de två prioriterade
> felen: ny WooCommerce-order saknas i KDS och QR-länken/koden fungerar inte.
> Använd en riktig lokal WordPress/WooCommerce-testinstallation. Ändra inte
> driftbutiken. Behåll design, WAPF, custom fields, NX och fyrsiffriga nummer.
> Lägg regressionstester till varje verifierat fel och redovisa vad som är
> testat mot verklig WooCommerce respektive bara testdubblar.

Kan inte GitHub-auktoriseringen slutföras ska Codex rapportera det och lämna
main orörd. Det går fortfarande att läsa och felsöka den lokala projektmappen.

## Diagnos i rätt WordPress-installation

För en utvecklare med WP-CLI-åtkomst finns `tools/diagnose-woo.php`. Det skriver
inte orderdata eller inställningar och anropar inte den muterande KDS-board-metoden.
Det visar versioner, roller, gamla snippet-markörer, registrerade rutter,
statusantal och om databasen accepterar ett kortlivat diagnostiklås.

```sh
wp eval-file /absolut/sokvag/till/projekt/tools/diagnose-woo.php --user=ADMIN_ID
```

Ett valfritt `YOOKDS_ORDER_ID` ska vara **hela WooCommerce-ID:t**, inte kvittots
fyra siffror. Håll diagnostikrapporten privat. Den visar inga kundnamn, adresser,
nonce-värden, tillvalsdata eller betalningshemligheter.

Övriga integrationstester SKAPAR testordrar och får bara köras i en separat,
tom testinstallation enligt testplanen — aldrig på driftbutiken.
