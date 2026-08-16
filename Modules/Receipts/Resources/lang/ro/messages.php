<?php

declare(strict_types=1);

return [
    'wizard' => [
        'intro' => 'Trage aici un mesaj de e-mail (.eml) sau o arhivă de căsuță poștală (.mbox). Motorul de potrivire recunoaște bonurile PayPal și le afișează drept tranzacții canonice; expeditorii nepotriviți rămân în jurnalul de audit pentru triaj.',
    ],

    'conflict' => [

        'field' => [
            'amount_minor' => 'sumă',
            'currency' => 'monedă',
            'description' => 'descriere',
            'counterparty_name' => 'nume comerciant',
            'default' => 'valoare',
        ],
        'heading_cleaner' => 'Un bon din e-mail are o valoare mai clară pentru :field',
        'heading_different' => 'Un bon din e-mail înregistrează o valoare diferită pentru :field',
        'title' => 'Bonul și extrasul de cont nu concordă.',
        'body' => ':heading („:receipt”) față de extrasul de cont („:statement”). Vrei ca Beatrax să prefere bonurile la conflictele viitoare?',
        'use_receipt' => 'Folosește bonul',
        'keep_statement' => 'Păstrează extrasul',
    ],
];
