<?php

declare(strict_types=1);

return [
    'conflict' => [

        'field' => [
            'amount_minor' => 'summa',
            'currency' => 'valuutta',
            'description' => 'kuvaus',
            'counterparty_name' => 'kauppiaan nimi',
            'default' => 'arvo',
        ],
        'heading_cleaner' => 'Sähköpostikuitissa on selkeämpi :field',
        'heading_different' => 'Sähköpostikuitissa on eri :field',
        'title' => 'Kuitti ja tiliote eivät täsmää.',
        'body' => ':heading (“:receipt”) kuin tiliotteessa (“:statement”). Suositaanko jatkossa kuittia, kun tiedot ovat ristiriidassa?',
        'use_receipt' => 'Käytä kuittia',
        'keep_statement' => 'Säilytä tiliote',
    ],
];
