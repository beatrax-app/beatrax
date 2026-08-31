<?php

declare(strict_types=1);

return [
    'conflict' => [

        'field' => [
            'amount_minor' => 'összeg',
            'currency' => 'pénznem',
            'description' => 'leírás',
            'counterparty_name' => 'kereskedő neve',
            'default' => 'érték',
        ],
        'heading_cleaner' => 'Egy e-mail-bizonylat tisztább értéket tartalmaz erre: :field',
        'heading_different' => 'Egy e-mail-bizonylat eltérő értéket rögzít erre: :field',
        'title' => 'A bizonylat és a kivonat nem egyezik.',
        'body' => ':heading („:receipt”), szemben a számlakivonattal („:statement”). A Beatrax a jövőbeli ütközéseknél a bizonylatokat részesítse előnyben?',
        'use_receipt' => 'Bizonylat használata',
        'keep_statement' => 'Kivonat megtartása',
    ],
];
