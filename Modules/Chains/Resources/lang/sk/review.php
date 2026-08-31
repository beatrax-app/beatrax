<?php

declare(strict_types=1);

return [
    'page_title' => 'Kontrola reťazcov',
    'heading' => 'Kontrola reťazcov',
    'hint' => ':count indícia|:count indície|:count indícií',
    'subtitle' => 'Potvrď alebo zamietni kandidátske prepojenia, ktoré sa nepodarilo potvrdiť automaticky.',

    'empty_heading' => 'Nie je čo kontrolovať',
    'empty_body' => 'Každé prepojenie, ktoré resolver dokázal spárovať, je potvrdené alebo zamietnuté. Noví kandidáti sa tu objavia, keď dorazia ďalšie importy.',

    'auto_confirm_nudge' => 'Ešte jedno potvrdenie a podobné prepojenia sa budú potvrdzovať automaticky.',

    'confirm' => 'Potvrdiť',
    'reject' => 'Zamietnuť',
    'confirm_aria' => 'Potvrdiť prepojenie reťazca :id',
    'reject_aria' => 'Zamietnuť prepojenie reťazca :id',
    'show_more' => 'Zobraziť viac',

    'kind' => [
        'paypal_funding' => 'Financovanie cez PayPal',
        'ics_bulk_settle' => 'Hromadné zúčtovanie iDEAL',
    ],

    'errors' => [
        'confirm_hint' => 'Tento kandidát je indícia — otvor ho a priraď zodpovedajúcu transakciu, až potom ho potvrď.',
        'reject_hint' => 'Tento kandidát je indícia — otvor ho a priraď zodpovedajúcu transakciu, až potom ho zamietni.',
    ],
];
