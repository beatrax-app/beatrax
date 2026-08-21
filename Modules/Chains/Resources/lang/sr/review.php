<?php

declare(strict_types=1);

return [
    'page_title' => 'Pregled lanaca',
    'heading' => 'Pregled lanaca',
    'hint' => ':count savet|:count saveta|:count saveta',
    'subtitle' => 'Potvrdi ili odbij predložene veze koje razrešavač lanaca nije mogao automatski da potvrdi.',

    'empty_heading' => 'Nema ničega za pregled',
    'empty_body' => 'Svaka veza u lancu je potvrđena ili odbijena. Novi kandidati pojaviće se ovde kako budu stizali uvozi.',

    'auto_confirm_nudge' => 'Još jedna potvrda i slične veze potvrđivaće se automatski.',

    'confirm' => 'Potvrdi',
    'reject' => 'Odbij',
    'confirm_aria' => 'Potvrdi vezu u lancu :id',
    'reject_aria' => 'Odbij vezu u lancu :id',
    'show_more' => 'Prikaži više',

    'kind' => [
        'paypal_funding' => 'PayPal finansiranje',
        'ics_bulk_settle' => 'Grupno iDEAL poravnanje',
    ],

    'errors' => [
        'confirm_hint' => 'Ovaj kandidat je savet — otvori ga i priloži odgovarajuću transakciju pre potvrde.',
        'reject_hint' => 'Ovaj kandidat je savet — otvori ga i priloži odgovarajuću transakciju pre odbijanja.',
    ],
];
