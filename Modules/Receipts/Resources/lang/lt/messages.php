<?php

declare(strict_types=1);

return [
    'wizard' => [
        'intro' => 'Įkelk el. laišką (.eml) arba pašto dėžutės archyvą (.mbox). Atitikčių ieškiklis atpažįsta PayPal kvitus ir parodo juos kaip pagrindines operacijas; neatpažinti siuntėjai lieka audito žurnale rūšiavimui.',
    ],

    'conflict' => [

        'field' => [
            'amount_minor' => 'suma',
            'currency' => 'valiuta',
            'description' => 'aprašymas',
            'counterparty_name' => 'prekybininko pavadinimas',
            'default' => 'reikšmė',
        ],
        'heading_cleaner' => 'El. pašto kvite laukas „:field“ tvarkingesnis',
        'heading_different' => 'El. pašto kvite laukas „:field“ nurodytas kitaip',
        'title' => 'Kvitas ir išrašas nesutampa.',
        'body' => ':heading („:receipt“) nei išraše („:statement“). Ar Beatrax turėtų ateityje kilus nesutarimams teikti pirmenybę kvitams?',
        'use_receipt' => 'Naudoti kvitą',
        'keep_statement' => 'Palikti išrašą',
    ],
];
