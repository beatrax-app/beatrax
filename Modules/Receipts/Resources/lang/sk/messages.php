<?php

declare(strict_types=1);

return [
    'conflict' => [

        'field' => [
            'amount_minor' => 'suma',
            'currency' => 'mena',
            'description' => 'popis',
            'counterparty_name' => 'meno obchodníka',
            'default' => 'hodnota',
        ],
        'heading_cleaner' => 'E-mailová účtenka má čistejšiu hodnotu poľa :field',
        'heading_different' => 'E-mailová účtenka zaznamenáva inú hodnotu poľa :field',
        'title' => 'Účtenka a výpis z účtu sa nezhodujú.',
        'body' => ':heading („:receipt“) než výpis z účtu („:statement“). Má Beatrax pri ďalších konfliktoch uprednostňovať účtenky?',
        'use_receipt' => 'Použiť účtenku',
        'keep_statement' => 'Ponechať výpis z účtu',
        'heading_restated' => 'Neskorší výpis z účtu zaznamenáva inú hodnotu poľa :field',
        'restated_title' => 'Banka túto transakciu opravila.',
        'restated_body' => ':heading („:incoming“) než už uložený riadok („:stored“). Má Beatrax pri ďalších rozdieloch uprednostňovať novú hodnotu?',
        'use_restated' => 'Použiť novú hodnotu',
        'keep_stored' => 'Ponechať uloženú hodnotu',
    ],
];
