<?php

declare(strict_types=1);

return [
    'page_title' => 'Lähetä tiliote',
    'heading' => 'Lähetä tiliote',
    'migrate_prompt' => 'Vaihdatko toisesta budjetointisovelluksesta?',
    'migrate_link' => 'Tuo YNAB- tai Actual-sovelluksesta',
    'subtitle' => 'Pudota pankin, kortin tai PayPalin vienti tai sähköpostikuittitiedosto.',
    'mime_hint' => 'Tuetut tiedostot: pankin CSV, MT940 (.sta / .mt940 / .txt), CAMT.053 XML, korttitiliotteen PDF, sähköpostiviesti (.eml) tai postilaatikkoarkisto (.mbox).',

    'source_label' => 'Lähde',

    'issuer_other_bank' => 'Muu pankki (N26, Revolut, ING…)',
    'issuer_email_file' => 'Sähköpostitiedosto (.eml, .mbox)',

    'format_label' => 'Muoto',
    'file_label' => 'Tiedosto',
    'submit' => 'Lähetä tiliote',

    'formats' => [
        'activity_download' => 'Tapahtumaraportti (CSV)',
        'email_message' => 'Sähköpostiviesti (.eml)',
        'mailbox_archive' => 'Postilaatikkoarkisto (.mbox)',
        'ing_nl' => 'ING Alankomaat (CSV)',
    ],

    'errors' => [
        'file_max' => 'Tiedosto on liian suuri. Pudota tiliotevienti, joka mahtuu valitun muodon kokorajaan.',
        'file_extensions' => 'Tämä tiedosto ei näytä tuetulta tiliotevienniltä. Pudota pankin CSV, MT940 (.sta / .mt940 / .txt), CAMT.053 XML, korttitiliotteen PDF, sähköpostiviesti (.eml) tai postilaatikkoarkisto (.mbox).',
        'issuer_format' => 'Arvo :attribute ei kelpaa lähteelle :source.',
        'process_failed' => 'Tätä tiedostoa ei voitu käsitellä (:class). Koko virhe löytyy polusta /dev/logs.',
    ],
];
