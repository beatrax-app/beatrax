<?php

declare(strict_types=1);

return [
    'conflict' => [

        'field' => [
            'amount_minor' => 'beløb',
            'currency' => 'valuta',
            'description' => 'beskrivelse',
            'counterparty_name' => 'forhandlernavn',
            'default' => 'værdi',
        ],
        'heading_cleaner' => 'En e-mailkvittering har renere :field',
        'heading_different' => 'En e-mailkvittering har afvigende :field',
        'title' => 'Kvitteringen og kontoudtoget stemmer ikke overens.',
        'body' => ':heading — kvitteringen angiver »:receipt«, kontoudtoget »:statement«. Skal Beatrax foretrække kvitteringer ved fremtidige konflikter?',
        'use_receipt' => 'Brug kvitteringen',
        'keep_statement' => 'Behold kontoudtoget',
    ],
];
