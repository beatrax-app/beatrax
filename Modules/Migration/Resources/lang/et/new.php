<?php

declare(strict_types=1);

return [
    'page_title' => 'Impordi YNAB-ist / Actualist',

    'eyebrow' => 'Andmete ülekandmine',
    'heading' => 'Impordi YNAB-ist / Actualist',
    'intro' => 'Too oma kategooriapuu, eelarveajalugu ja tehingud üle YNAB4-st, uuest YNAB-ist või Actual Budgetist. Enne kui oled need üle vaadanud ja kinnitanud, ei kirjutata sinu pearaamatusse midagi.',
    'reconcile_context' => 'Kontrollin uuendusi sinu viimase :product impordi suhtes.',

    'source_label' => 'Allikas',
    'file_label' => 'Fail',
    'parse_button' => 'Töötle eksport',

    'hints' => [
        'ynab4' => 'Ekspordi kogu oma eelarve ZIP-failina YNAB4 menüüst File → Export.',
        'nynab' => 'Ekspordi oma eelarve nYNAB-ist menüüst File → Export Budget ja pakenda eksporditud CSV-failid ZIP-i.',
        'actual' => 'Ekspordi oma eelarve ZIP-failina Actual Budgeti menüüst Settings → Export data.',
    ],

    'errors' => [
        'unrecognised' => 'See ei tundu olevat YNAB4, nYNAB ega Actual eksport, mida oskaksime lugeda. Kontrolli faili ja proovi uuesti.',
        'file_too_large' => 'See fail on ülekandmise ekspordi jaoks liiga suur.',
    ],
];
