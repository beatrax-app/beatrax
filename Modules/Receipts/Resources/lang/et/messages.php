<?php

declare(strict_types=1);

return [
    'conflict' => [

        'field' => [
            'amount_minor' => 'summa',
            'currency' => 'valuuta',
            'description' => 'kirjeldus',
            'counterparty_name' => 'kaupmehe nimi',
            'default' => 'väärtus',
        ],
        'heading_cleaner' => 'E-posti kviitungil on selgem :field',
        'heading_different' => 'E-posti kviitungil on teistsugune :field',
        'title' => 'Kviitung ja väljavõte on eri meelt.',
        'body' => ':heading („:receipt“) kui väljavõttel („:statement“). Kas Beatrax peaks edaspidiste vastuolude puhul eelistama kviitungeid?',
        'use_receipt' => 'Kasuta kviitungit',
        'keep_statement' => 'Jäta väljavõte',
        'heading_restated' => 'Hilisemal väljavõttel on teistsugune :field',
        'restated_title' => 'Pank parandas selle tehingu.',
        'restated_body' => ':heading („:incoming“) kui juba salvestatud real („:stored“). Kas Beatrax peaks edaspidiste vastuolude puhul eelistama uut väärtust?',
        'use_restated' => 'Kasuta uut väärtust',
        'keep_stored' => 'Jäta salvestatud väärtus',
    ],
];
