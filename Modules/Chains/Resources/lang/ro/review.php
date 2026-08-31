<?php

declare(strict_types=1);

return [
    'page_title' => 'Verifică lanțurile',
    'heading' => 'Verifică lanțurile',
    'hint' => ':count sugestie|:count sugestii|:count de sugestii',
    'subtitle' => 'Confirmă sau respinge legăturile candidate pe care rezolvatorul de lanțuri nu le-a putut confirma automat.',

    'empty_heading' => 'Nimic de verificat',
    'empty_body' => 'Fiecare legătură pe care rezolvatorul a putut-o împerechea este confirmată sau respinsă. Candidații noi vor apărea aici pe măsură ce sosesc importuri.',

    'auto_confirm_nudge' => 'Încă o confirmare și legăturile similare se confirmă automat.',

    'confirm' => 'Confirmă',
    'reject' => 'Respinge',
    'confirm_aria' => 'Confirmă legătura de lanț :id',
    'reject_aria' => 'Respinge legătura de lanț :id',
    'show_more' => 'Arată mai multe',

    'kind' => [
        'paypal_funding' => 'Finanțare PayPal',
        'ics_bulk_settle' => 'Decontare iDEAL în masă',
    ],

    'errors' => [
        'confirm_hint' => 'Acest candidat este o sugestie — deschide-l pentru a atașa tranzacția corespunzătoare înainte de confirmare.',
        'reject_hint' => 'Acest candidat este o sugestie — deschide-l pentru a atașa tranzacția corespunzătoare înainte de respingere.',
    ],
];
