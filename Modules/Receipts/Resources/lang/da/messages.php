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
        'heading_restated' => 'Et senere kontoudtog har afvigende :field',
        'restated_title' => 'Banken har korrigeret denne postering.',
        'restated_body' => ':heading — det senere kontoudtog angiver »:incoming«, den gemte postering »:stored«. Skal Beatrax foretrække den nye værdi ved fremtidige afvigelser?',
        'use_restated' => 'Brug den nye værdi',
        'keep_stored' => 'Behold den gemte værdi',
    ],
];
