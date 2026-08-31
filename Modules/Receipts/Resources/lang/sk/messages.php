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
    ],
];
