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
        'heading_restated' => 'Myöhemmässä tiliotteessa on eri :field',
        'restated_title' => 'Pankki korjasi tämän tapahtuman.',
        'restated_body' => ':heading (“:incoming”) kuin jo tallennetulla rivillä (“:stored”). Suositaanko jatkossa uutta arvoa, kun tiedot ovat ristiriidassa?',
        'use_restated' => 'Käytä uutta arvoa',
        'keep_stored' => 'Säilytä tallennettu arvo',
    ],
];
