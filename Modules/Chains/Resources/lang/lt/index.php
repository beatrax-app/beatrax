<?php

declare(strict_types=1);

return [
    'page_title' => 'Grandinės',
    'heading' => 'Grandinės',
    'review_link' => 'Peržiūros eilė →',
    'hints_link' => 'Užuominos →',
    'subtitle' => 'Pirkiniai, sujungti į vieną mokėjimą. Kiekvienoje kortelėje rodomas vienas mokėjimas ir jį sudarę mokėjimai.',

    'empty_heading' => 'Kol kas grandinių nėra',
    'empty_body' => 'Importuok kelis sąskaitų išrašus (banko, PayPal, kortelės) ir sprendiklis čia automatiškai parodys tarp sąskaitų einančias grandines.',

    'no_counterparty' => '(kitos šalies nėra)',
    'leg_count' => ':count mokėjimas|:count mokėjimai|:count mokėjimų',
    'legs_more' => '+ dar :count',
    'state_aria' => 'Būsena: :state',

    'state' => [
        'candidate' => 'Kandidatas',
        'confirmed' => 'Patvirtinta',
        'rejected' => 'Atmesta',
    ],

    'kind' => [
        'paypal_funding' => 'PayPal finansavimas',
        'ics_bulk_settle' => 'Bendras iDEAL atsiskaitymas',
        'funded_by_card_hint' => 'Finansuota kortele (užuomina)',
        'refund_of_hint' => 'Grąžinimas (užuomina)',
    ],
];
