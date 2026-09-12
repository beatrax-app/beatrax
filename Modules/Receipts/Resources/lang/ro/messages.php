<?php

declare(strict_types=1);

return [
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
        'heading_restated' => 'Un extras de cont ulterior înregistrează o valoare diferită pentru :field',
        'restated_title' => 'Banca a corectat această tranzacție.',
        'restated_body' => ':heading („:incoming”) față de rândul deja salvat („:stored”). Vrei ca Beatrax să prefere valoarea nouă la diferențele viitoare?',
        'use_restated' => 'Folosește valoarea nouă',
        'keep_stored' => 'Păstrează valoarea salvată',
    ],
];
