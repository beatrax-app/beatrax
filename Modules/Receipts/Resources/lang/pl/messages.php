<?php

declare(strict_types=1);

return [
    'conflict' => [

        'field' => [
            'amount_minor' => 'kwota',
            'currency' => 'waluta',
            'description' => 'opis',
            'counterparty_name' => 'nazwa sprzedawcy',
            'default' => 'wartość',
        ],
        'heading_cleaner' => 'Paragon e-mail ma czytelniejszą wartość w polu „:field”',
        'heading_different' => 'Paragon e-mail zapisuje inną wartość w polu „:field”',
        'title' => 'Paragon i wyciąg się nie zgadzają.',
        'body' => ':heading („:receipt”) niż wyciąg („:statement”). Czy Beatrax ma przy przyszłych konfliktach preferować paragony?',
        'use_receipt' => 'Użyj paragonu',
        'keep_statement' => 'Zachowaj wyciąg',
    ],
];
