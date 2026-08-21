<?php

declare(strict_types=1);

return [
    'page_title' => 'Pregled verig',
    'heading' => 'Pregled verig',
    'hint' => ':count namig|:count namiga|:count namigi|:count namigov',
    'subtitle' => 'Potrdi ali zavrni predlagane povezave, ki jih razreševalnik verig ni mogel samodejno potrditi.',

    'empty_heading' => 'Ni ničesar za pregled',
    'empty_body' => 'Vsaka povezava v verigi je potrjena ali zavrnjena. Novi kandidati se bodo pojavili tukaj, ko bodo prispeli uvozi.',

    'auto_confirm_nudge' => 'Še ena potrditev in podobne povezave se bodo potrjevale samodejno.',

    'confirm' => 'Potrdi',
    'reject' => 'Zavrni',
    'confirm_aria' => 'Potrdi povezavo v verigi :id',
    'reject_aria' => 'Zavrni povezavo v verigi :id',
    'show_more' => 'Prikaži več',

    'kind' => [
        'paypal_funding' => 'PayPal financiranje',
        'ics_bulk_settle' => 'Skupinska iDEAL poravnava',
    ],

    'errors' => [
        'confirm_hint' => 'Ta kandidat je namig — odpri ga in pripni ustrezno transakcijo, preden ga potrdiš.',
        'reject_hint' => 'Ta kandidat je namig — odpri ga in pripni ustrezno transakcijo, preden ga zavrneš.',
    ],
];
