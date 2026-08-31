<?php

declare(strict_types=1);

return [
    'page_title' => 'Granska kedjor',
    'heading' => 'Granska kedjor',
    'hint' => ':count ledtråd|:count ledtrådar',
    'subtitle' => 'Bekräfta eller avvisa kandidatlänkar som kedjelösaren inte kunde bekräfta automatiskt.',

    'empty_heading' => 'Inget att granska',
    'empty_body' => 'Varje länk som lösaren kunde para ihop är bekräftad eller avvisad. Nya kandidater dyker upp här allteftersom importer kommer in.',

    'auto_confirm_nudge' => 'En bekräftelse till, så bekräftas liknande länkar automatiskt.',

    'confirm' => 'Bekräfta',
    'reject' => 'Avvisa',
    'confirm_aria' => 'Bekräfta kedjelänk :id',
    'reject_aria' => 'Avvisa kedjelänk :id',
    'show_more' => 'Visa fler',

    'kind' => [
        'paypal_funding' => 'PayPal-finansiering',
        'ics_bulk_settle' => 'Samlad iDEAL-avräkning',
    ],

    'errors' => [
        'confirm_hint' => 'Den här kandidaten är en ledtråd — öppna den och koppla den matchande transaktionen innan du bekräftar.',
        'reject_hint' => 'Den här kandidaten är en ledtråd — öppna den och koppla den matchande transaktionen innan du avvisar.',
    ],
];
