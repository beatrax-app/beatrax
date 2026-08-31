<?php

declare(strict_types=1);

return [
    'conflict' => [

        'field' => [
            'amount_minor' => 'iznos',
            'currency' => 'valutu',
            'description' => 'opis',
            'counterparty_name' => 'naziv trgovca',
            'default' => 'vrijednost',
        ],
        'heading_cleaner' => 'Potvrda iz e-pošte ima čitljiviji :field',
        'heading_different' => 'Potvrda iz e-pošte bilježi drugačiji :field',
        'title' => 'Potvrda i izvod se ne slažu.',
        'body' => ':heading („:receipt”) nego izvod („:statement”). Treba li Beatrax kod budućih neslaganja davati prednost potvrdama?',
        'use_receipt' => 'Koristi potvrdu',
        'keep_statement' => 'Zadrži izvod',
    ],
];
