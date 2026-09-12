<?php

declare(strict_types=1);

return [
    'conflict' => [

        'field' => [
            'amount_minor' => 'belopp',
            'currency' => 'valuta',
            'description' => 'beskrivning',
            'counterparty_name' => 'handlarnamn',
            'default' => 'värde',
        ],
        'heading_cleaner' => 'Ett e-postkvitto har renare :field',
        'heading_different' => 'Ett e-postkvitto har avvikande :field',
        'title' => 'Kvittot och kontoutdraget stämmer inte överens.',
        'body' => ':heading — kvittot anger ”:receipt”, kontoutdraget ”:statement”. Ska Beatrax föredra kvitton vid framtida konflikter?',
        'use_receipt' => 'Använd kvittot',
        'keep_statement' => 'Behåll kontoutdraget',
        'heading_restated' => 'Ett senare kontoutdrag har avvikande :field',
        'restated_title' => 'Banken har korrigerat den här transaktionen.',
        'restated_body' => ':heading — det senare kontoutdraget anger ”:incoming”, den sparade raden ”:stored”. Ska Beatrax föredra det nya värdet vid framtida avvikelser?',
        'use_restated' => 'Använd det nya värdet',
        'keep_stored' => 'Behåll det sparade värdet',
    ],
];
