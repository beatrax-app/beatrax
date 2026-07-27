<p align="center">
  <img src="../../../resources/brand/logo.svg" width="200" alt="beatrax">
</p>

<p align="center">
  <em>Een lokaal persoonlijk-financieel dashboard voor het totaalbeeld van je geld over je rekeningen heen.</em>
</p>

<p align="center">
  <img alt="Licentie: Hippocratic 3.0" src="https://img.shields.io/badge/license-Hippocratic--3.0-blue.svg">
  <img alt="PHP 8.4+" src="https://img.shields.io/badge/php-8.4%2B-777bb4.svg">
  <img alt="Laravel 13" src="https://img.shields.io/badge/laravel-13.x-ff2d20.svg">
  <img alt="Status: pre-1.0" src="https://img.shields.io/badge/status-v0.x-orange.svg">
</p>

<p align="center">
  <a href="../../../README.md">🇬🇧 English</a> · <strong>🇳🇱 Nederlands</strong>
</p>

## Wat is beatrax?

beatrax is een persoonlijk-financieel dashboard dat volledig lokaal
draait en transacties uit ASN Bank, ICS Cards, PayPal en Google Play
samenbrengt tot één rustige "deze maand in één oogopslag"-weergave. Het
herleidt de routes tussen die rekeningen (PayPal → ASN of ICS, ICS →
ASN via verzamelde iDEAL-afrekening) zodat vaste maandlasten, de
werkelijke financieringsbron en je verwachte cashflow op één plek
zichtbaar zijn in plaats van verspreid over allerlei afschriften.

Alles draait op je eigen computer. Geen telemetrie. Geen cloudsync.
Geen extern account. De SQLite-database, de OAuth-tokens en de
gecachte e-mailbonnetjes staan op je schijf en gaan daar niet vanaf,
tenzij je ze zelf exporteert.

Het product is **source-available**, niet open-source in OSI-zin. De
volledige broncode staat hier om te lezen, draaien en aanpassen; de
licentie voegt daar ethische-gebruiksclausules aan toe. Zie
[NOTICE.md](../../../NOTICE.md) voor de uitgebreidere uitleg.

## Voor wie is dit?

beatrax is gebouwd voor één persoon — of een huishouden van twee —
die zijn of haar geldzaken bijhoudt over meerdere Nederlandse bank-,
creditcard- en betaaldienst-rekeningen en die de maandelijkse stand
van zaken in één overzicht wil zien, in plaats van elke cyclus
afschriften te moeten lijmen. Het gaat ervan uit dat je technisch
genoeg onderlegd bent om een desktop-applicatie te installeren, om
OAuth-rechten te geven aan je Gmail- of Microsoft Graph-inbox (als je
e-mailbonnen wilt laten scannen), en om een CSV of PDF te lezen
wanneer dat nodig is.

Als je uitsluitend bij één bank zit die je al een goede app levert,
heb je beatrax waarschijnlijk niet nodig. Als je je uitgaven verspreid
over ASN + ICS + PayPal + Google Play-abonnementen en je het bijhouden
met de hand hebt opgegeven: dan is dit voor jou.

## Dankwoord

### Bedankt, mam

Ik wil beginnen met het bedanken van mijn moeder (Bea — voor wie zich
afvroeg waar de naam vandaan komt), die de inspiratie is geweest om
dit te maken.

### Get Shit Done (GSD) / Claude Code

Met dit project wilde ik een paar dingen uitproberen, en één daarvan
was alles via prompten doen. De kwaliteit van wat er met GSD en Claude
te leveren valt is verbluffend. Bekijk GSD hier:
https://github.com/gsd-build/get-shit-done

### Laravel / NativePHP

Naast veel andere geweldige packages (en bekijk vooral ook de
composer.json), wil ik Laravel en NativePHP uitlichten — het is
indrukwekkend hoe ver de PHP-taal de afgelopen jaren is gekomen, en
wat je er nu mee kunt.

## Installatie

beatrax levert installers voor macOS, Windows en Linux. Kies degene
die past bij jouw besturingssysteem.

### Installeren op macOS

beatrax is een onafhankelijke app. macOS waarschuwt je de eerste keer
dat je hem opent — dat is verwacht gedrag.

1. Open de gedownloade **beatrax.dmg** en sleep beatrax naar je
   map Programma's.
2. Klik met de rechtermuisknop op **beatrax** in Programma's en kies
   **Open**.
3. Wanneer macOS vraagt "weet je het zeker?", klik op **Open**.
4. Vanaf nu start beatrax normaal door erop te dubbelklikken.

**Alternatief (Terminal-oneliner):**

```sh
xattr -d com.apple.quarantine /Applications/beatrax.app
```

> Net als de meeste onafhankelijke macOS-apps is beatrax niet
> ondertekend met een Apple Developer ID — we betalen Apple geen
> $99 per jaar puur om die eerste dialoog te vermijden.
> [Waarom we deze keuze hebben gemaakt →](../../legal/license-rationale.md#no-paid-signing)

### Installeren op Windows

beatrax is een onafhankelijke app. Windows SmartScreen waarschuwt je
de eerste keer dat je hem opent — dat is verwacht gedrag.

1. Voer de gedownloade **beatrax-setup.exe** uit.
2. Wanneer je "Windows heeft je pc beveiligd" ziet, klik op
   **Meer informatie**.
3. Klik op **Toch uitvoeren**.
4. Vanaf nu start beatrax normaal vanaf het Startmenu.

> De SmartScreen-reputatie wordt opgebouwd naarmate meer mensen
> beatrax openen. Na een paar weken verdwijnt de waarschuwing
> automatisch voor nieuwe gebruikers.
> [Waarom we deze keuze hebben gemaakt →](../../legal/license-rationale.md#no-paid-signing)

### Installeren op Linux

beatrax wordt geleverd als zowel AppImage (portable) als .deb
(Debian / Ubuntu native).

**AppImage:**

```sh
chmod +x beatrax-*.AppImage
./beatrax-*.AppImage
```

**.deb (Debian / Ubuntu / Mint):**

```sh
sudo dpkg -i beatrax-*.deb
```

### De download verifiëren

Elke release publiceert SHA-256-checksums en een met Ed25519
ondertekend manifest. Als je de integriteit wilt verifiëren:

```sh
sha256sum beatrax-{versie}-{platform}.{ext}
```

Vergelijk de uitvoer met het checksum-bestand dat bij de release wordt
gepubliceerd. Voor de diepere "is dit manifest authentiek?"-check,
zie [de verificatie-runbook →](../../runbooks/verify-release.md).

## Schermafbeeldingen

Een volledige rondleiding langs elk scherm — de setup-wizard van begin
tot eind, het dashboard, transacties, envelop-budgetten, de
kasstroomprognose, tegenpartijen en betaalketens, drift- en
anomalie-alerts, belasting, doelen en potjes — staat op de website,
samen met korte opnames van de meerstapsflows:

**[beatrax.app](https://beatrax.app)**

Wie zelf nieuwe schermafbeeldingen maakt, kan eerst een representatieve
demodataset opzetten met `php artisan demo:seed --reset`.

## Project-status

**v1.3.0 "Local & in sync"** is de huidige release (14 juni 2026) —
valuta-omrekening, spaardoelen en potjes, een installeerbare PWA,
app-vergrendeling met pincode en biometrie, de rekeningenkalender,
belastinglabels met jaarexport, full-tekst zoeken en anomalie-alerts.

**v2.0** is in actieve ontwikkeling en is wat deze repository vandaag
bevat: local-first end-to-end-versleutelde peer-to-peer apparaatsynchronisatie,
een proactieve notificatie-inbox, een optionele open-banking-koppeling,
envelop-budgettering (zero-based), gesplitste transacties, rekening-reconciliatie,
een algemene regels-engine, migratie-importers voor YNAB en Actual, en een
eigen rapportbouwer. De mobiele client als volwaardige sync-peer en
distributie via de app stores zijn de resterende onderdelen.

Zie [CHANGELOG.md](../../../CHANGELOG.md) voor de volledige geschiedenis en
de releases-pagina op GitHub voor de laatste download.

## Bijdragen

Zie [CONTRIBUTING.md](../../../CONTRIBUTING.md).

## Licentie + ethiek

beatrax valt onder de [Hippocratic License 3.0](../../../LICENSE). De
broncode is beschikbaar maar niet OSI-goedgekeurd — zie
[NOTICE.md](../../../NOTICE.md) voor de onderbouwing.

## Beveiliging

Meld kwetsbaarheden via het [Security Policy](../../../SECURITY.md).
