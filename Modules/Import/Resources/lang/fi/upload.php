<?php

declare(strict_types=1);

return [
    'page_title' => 'Lähetä tiliote',
    'heading' => 'Lähetä tiliote',
    'migrate_prompt' => 'Vaihdatko toisesta budjetointisovelluksesta?',
    'migrate_link' => 'Tuo YNAB- tai Actual-sovelluksesta',
    'subtitle' => 'Pudota tiliote CSV-, CAMT.053-, MT940- tai PDF-muodossa tai sähköpostikuittitiedosto.',
    'mime_hint' => 'Tuetut tiedostot: pankin CSV, MT940 (.sta / .mt940 / .txt), CAMT.053 XML, korttitiliotteen PDF, sähköpostiviesti (.eml) tai postilaatikkoarkisto (.mbox).',

    'type_label' => 'Tuonnin tyyppi',

    'types' => [
        'csv' => 'CSV-tiedosto',
        'camt053' => 'CAMT.053-tiliote (XML)',
        'mt940' => 'MT940-tiliote',
        'pdf' => 'Korttitiliote (PDF)',
        'email' => 'Sähköpostikuittitiedosto',
    ],

    'format_label' => 'Muoto',

    'format_from_file' => 'Muodoksi asetettiin :format, jotta se vastaa valitsemaasi tiedostoa. Vaihda se, jos se ei pidä paikkaansa.',
    'file_label' => 'Tiedosto',
    'submit' => 'Lähetä tiliote',

    'formats' => [
        'activity_download' => 'Tapahtumaraportti (CSV)',
        'email_message' => 'Sähköpostiviesti (.eml)',
        'mailbox_archive' => 'Postilaatikkoarkisto (.mbox)',
    ],

    'errors' => [
        'file_max' => 'Tiedosto on liian suuri. Pudota tiliotevienti, joka mahtuu valitun muodon kokorajaan.',
        'file_extensions' => 'Tämä tiedosto ei näytä tuetulta tiliotevienniltä. Pudota pankin CSV, MT940 (.sta / .mt940 / .txt), CAMT.053 XML, korttitiliotteen PDF, sähköpostiviesti (.eml) tai postilaatikkoarkisto (.mbox).',
        'type_format' => 'Arvo :attribute ei kelpaa tuontityypille :type.',
        'process_failed' => 'Tätä tiedostoa ei voitu käsitellä (:class). Koko virhe löytyy polusta /dev/logs.',
    ],
];
