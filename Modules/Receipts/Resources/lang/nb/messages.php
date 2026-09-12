<?php

declare(strict_types=1);

return [
    'conflict' => [

        'field' => [
            'amount_minor' => 'beløp',
            'currency' => 'valuta',
            'description' => 'beskrivelse',
            'counterparty_name' => 'forhandlernavn',
            'default' => 'verdi',
        ],
        'heading_cleaner' => 'En e-postkvittering har renere :field',
        'heading_different' => 'En e-postkvittering har avvikende :field',
        'title' => 'Kvitteringen og kontoutskriften stemmer ikke overens.',
        'body' => ':heading — kvitteringen angir «:receipt», kontoutskriften «:statement». Skal Beatrax foretrekke kvitteringer ved framtidige konflikter?',
        'use_receipt' => 'Bruk kvitteringen',
        'keep_statement' => 'Behold kontoutskriften',
        'heading_restated' => 'En senere kontoutskrift har avvikende :field',
        'restated_title' => 'Banken har korrigert denne posteringen.',
        'restated_body' => ':heading — den senere kontoutskriften angir «:incoming», den lagrede posteringen «:stored». Skal Beatrax foretrekke den nye verdien ved framtidige avvik?',
        'use_restated' => 'Bruk den nye verdien',
        'keep_stored' => 'Behold den lagrede verdien',
    ],
];
