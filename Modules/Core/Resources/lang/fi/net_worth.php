<?php

declare(strict_types=1);

return [
    'aria' => 'Nettovarallisuus',
    'heading' => 'Nettovarallisuus',

    'rate_details' => 'Kurssin tiedot',
    'rate_details_for' => 'Kurssin tiedot kohteelle :name',

    'across' => ':count tilillä|:count tilillä',

    'not_converted' => '· :count tiliä ei muunnettu — kurssia ei saatavilla|· :count tiliä ei muunnettu — kurssia ei saatavilla',
    'no_rate_available' => '· kurssia ei saatavilla',

    'toggle_hide' => 'Piilota',
    'toggle_breakdown' => 'Erittely',
    'card_suffix' => '(kortti)',

    'converted_to' => 'Muunnettu valuuttaan :currency',
    'as_of' => 'päivältä :date',
    'rate_line' => '1 :from = :rate :to',
    'global_rates' => 'kurssit päivältä :date lähteestä :source',

    // i18n-review: fi · stale_bundled, stale_old, stale_offline — the singular arm
    // reads "yli :count päivän vanha" against the plural "yli :count päivää vanha".
    // Only a Finnish reader can say which case "yli" takes beside a numeral here.
    'stale_bundled' => 'Käytössä on mukana toimitettu tilannekurssi, joka on yli :count päivän vanha. Ota verkkopäivitys käyttöön asetuksista, niin saat ajantasaiset kurssit.|Käytössä on mukana toimitettu tilannekurssi, joka on yli :count päivää vanha. Ota verkkopäivitys käyttöön asetuksista, niin saat ajantasaiset kurssit.',
    'stale_old' => 'Tämä kurssi on yli :count päivän vanha. Seuraava verkkopäivitys päivittää sen.|Tämä kurssi on yli :count päivää vanha. Seuraava verkkopäivitys päivittää sen.',
    'stale_offline' => 'Tämä kurssi on yli :count päivän vanha, ja verkkopäivitys on pois käytöstä. Ota se käyttöön asetuksista, niin kurssi päivittyy.|Tämä kurssi on yli :count päivää vanha, ja verkkopäivitys on pois käytöstä. Ota se käyttöön asetuksista, niin kurssi päivittyy.',

    // i18n-review: fi · source_ecb — the value is what this locale's own
    // settings.exchange_rates.online_on already writes, so the card and Settings
    // cannot name the same institution two ways. This language usually
    // abbreviates it EKP, and moving to that means moving both lines.
    'source_ecb' => 'ECB',
    'source_bundled' => 'Mukana toimitettu tilannevedos',
    'source_transaction' => 'Kirjattu kurssi',
    'source_fallback' => 'kurssit',
];
