<?php

declare(strict_types=1);

return [
    'wizard' => [
        'intro' => 'Ievelciet e-pasta ziņojumu (.eml) vai pastkastes arhīvu (.mbox). Sistēma atpazīst PayPal čekus un parāda tos kā pilnvērtīgus darījumus; neatpazītie sūtītāji paliek audita žurnālā šķirošanai.',
    ],

    'conflict' => [

        'field' => [
            'amount_minor' => 'summa',
            'currency' => 'valūta',
            'description' => 'apraksts',
            'counterparty_name' => 'tirgotāja nosaukums',
            'default' => 'vērtība',
        ],
        'heading_cleaner' => 'E-pasta čekā lauks :field ir precīzāks',
        'heading_different' => 'E-pasta čekā lauks :field atšķiras',
        'title' => 'Čeks un konta izraksts nesakrīt.',
        'body' => ':heading — čekā (“:receipt”), konta izrakstā (“:statement”). Vai turpmākajos konfliktos Beatrax dot priekšroku čekiem?',
        'use_receipt' => 'Izmantot čeku',
        'keep_statement' => 'Paturēt konta izrakstu',
    ],
];
