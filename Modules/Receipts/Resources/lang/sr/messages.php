<?php

declare(strict_types=1);

return [
    'wizard' => [
        'intro' => 'Prevuci poruku e-pošte (.eml) ili arhivu poštanskog sandučeta (.mbox). Podudarivač prepoznaje PayPal potvrde i prikazuje ih kao redovne transakcije; nepodudareni pošiljaoci ostaju u revizionom logu za trijažu.',
    ],

    'conflict' => [

        'field' => [
            'amount_minor' => 'iznos',
            'currency' => 'valutu',
            'description' => 'opis',
            'counterparty_name' => 'naziv trgovca',
            'default' => 'vrednost',
        ],
        'heading_cleaner' => 'Potvrda iz e-pošte ima čitljiviji :field',
        'heading_different' => 'Potvrda iz e-pošte beleži drugačiji :field',
        'title' => 'Potvrda i izvod se ne slažu.',
        'body' => ':heading („:receipt”) nego izvod („:statement”). Treba li Beatrax kod budućih neslaganja da daje prednost potvrdama?',
        'use_receipt' => 'Koristi potvrdu',
        'keep_statement' => 'Zadrži izvod',
    ],
];
