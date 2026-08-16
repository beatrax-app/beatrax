<?php

declare(strict_types=1);

return [
    'wizard' => [
        'intro' => 'Släpp ett e-postmeddelande (.eml) eller ett brevlådearkiv (.mbox). Matchningen känner igen PayPal-kvitton och visar dem som kanoniska transaktioner; avsändare utan träff stannar i granskningsloggen för sortering.',
    ],

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
    ],
];
