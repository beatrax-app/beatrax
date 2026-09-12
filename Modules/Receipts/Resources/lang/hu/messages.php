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
        'heading_restated' => 'Egy későbbi kivonat eltérő értéket rögzít erre: :field',
        'restated_title' => 'A bank újraszámolta ezt a tranzakciót.',
        'restated_body' => ':heading („:incoming”), szemben a már mentett sorral („:stored”). A Beatrax a jövőbeli eltéréseknél az új értéket részesítse előnyben?',
        'use_restated' => 'Új érték használata',
        'keep_stored' => 'Mentett érték megtartása',
    ],
];
