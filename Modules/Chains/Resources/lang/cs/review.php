<?php

declare(strict_types=1);

return [
    'page_title' => 'Kontrola řetězců',
    'heading' => 'Kontrola řetězců',
    'hint' => ':count náznak|:count náznaky|:count náznaků',
    'subtitle' => 'Potvrď nebo odmítni navržené vazby, které se nepodařilo potvrdit automaticky.',

    'empty_heading' => 'Není co kontrolovat',
    'empty_body' => 'Každá vazba, kterou se resolveru podařilo spárovat, je potvrzená, nebo odmítnutá. Nové návrhy se tu objeví s dalšími importy.',

    'auto_confirm_nudge' => 'Ještě jedno potvrzení a podobné vazby se budou potvrzovat samy.',

    'confirm' => 'Potvrdit',
    'reject' => 'Odmítnout',
    'confirm_aria' => 'Potvrdit vazbu řetězce :id',
    'reject_aria' => 'Odmítnout vazbu řetězce :id',
    'show_more' => 'Zobrazit více',

    'kind' => [
        'paypal_funding' => 'Financování z PayPalu',
        'ics_bulk_settle' => 'Hromadné vypořádání iDEAL',
    ],

    'errors' => [
        'confirm_hint' => 'Tento návrh je jen náznak — otevři ho a připoj odpovídající transakci, než ho potvrdíš.',
        'reject_hint' => 'Tento návrh je jen náznak — otevři ho a připoj odpovídající transakci, než ho odmítneš.',
    ],
];
