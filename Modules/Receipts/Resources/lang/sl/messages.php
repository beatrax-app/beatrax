<?php

declare(strict_types=1);

return [
    'wizard' => [
        'intro' => 'Spusti sporočilo e-pošte (.eml) ali arhiv nabiralnika (.mbox). Ujemalnik prepozna potrdila PayPal in jih pokaže kot običajne transakcije; neujemajoči se pošiljatelji ostanejo v revizijskem dnevniku za triažo.',
    ],

    'conflict' => [

        'field' => [
            'amount_minor' => 'znesek',
            'currency' => 'valuto',
            'description' => 'opis',
            'counterparty_name' => 'ime trgovca',
            'default' => 'vrednost',
        ],
        'heading_cleaner' => 'Potrdilo iz e-pošte ima jasnejši :field',
        'heading_different' => 'Potrdilo iz e-pošte beleži drugačen :field',
        'title' => 'Potrdilo in izpisek se ne ujemata.',
        'body' => ':heading („:receipt“) kot izpisek („:statement“). Ali naj Beatrax pri prihodnjih neujemanjih daje prednost potrdilom?',
        'use_receipt' => 'Uporabi potrdilo',
        'keep_statement' => 'Obdrži izpisek',
    ],
];
