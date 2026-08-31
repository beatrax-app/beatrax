<?php

declare(strict_types=1);

return [
    'page_title' => 'Pregled lanaca',
    'heading' => 'Pregled lanaca',
    'hint' => ':count savjet|:count savjeta|:count savjeta',
    'subtitle' => 'Potvrdi ili odbij predložene veze koje razrješavač lanaca nije mogao automatski potvrditi.',

    'empty_heading' => 'Nema ničega za pregled',
    'empty_body' => 'Svaka veza koju je razrješivač uspio upariti potvrđena je ili odbijena. Novi kandidati pojavit će se ovdje kako budu stizali uvozi.',

    'auto_confirm_nudge' => 'Još jedna potvrda i slične veze potvrđivat će se automatski.',

    'confirm' => 'Potvrdi',
    'reject' => 'Odbij',
    'confirm_aria' => 'Potvrdi vezu u lancu :id',
    'reject_aria' => 'Odbij vezu u lancu :id',
    'show_more' => 'Prikaži više',

    'kind' => [
        'paypal_funding' => 'PayPal financiranje',
        'ics_bulk_settle' => 'Skupno iDEAL poravnanje',
    ],

    'errors' => [
        'confirm_hint' => 'Ovaj kandidat je savjet — otvori ga i priloži odgovarajuću transakciju prije potvrde.',
        'reject_hint' => 'Ovaj kandidat je savjet — otvori ga i priloži odgovarajuću transakciju prije odbijanja.',
    ],
];
