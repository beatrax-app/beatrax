<?php

declare(strict_types=1);

return [
    'wizard' => [
        'intro' => 'Přetáhni sem e-mailovou zprávu (.eml) nebo archiv schránky (.mbox). Párovač rozpozná účtenky z PayPalu a zobrazí je jako kanonické transakce; nerozpoznaní odesílatelé zůstanou v auditním logu ke třídění.',
    ],

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
