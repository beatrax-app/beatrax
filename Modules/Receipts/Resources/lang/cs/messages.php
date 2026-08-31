<?php

declare(strict_types=1);

return [
    'conflict' => [

        'field' => [
            'amount_minor' => 'částka',
            'currency' => 'měna',
            'description' => 'popis',
            'counterparty_name' => 'jméno obchodníka',
            'default' => 'hodnota',
        ],
        'heading_cleaner' => 'E-mailová účtenka má čistší hodnotu v poli „:field“',
        'heading_different' => 'E-mailová účtenka uvádí jinou hodnotu v poli „:field“',
        'title' => 'Účtenka a výpis z účtu se neshodují.',
        'body' => ':heading („:receipt“) než výpis z účtu („:statement“). Má Beatrax u budoucích konfliktů upřednostňovat účtenky?',
        'use_receipt' => 'Použít účtenku',
        'keep_statement' => 'Ponechat výpis z účtu',
    ],
];
