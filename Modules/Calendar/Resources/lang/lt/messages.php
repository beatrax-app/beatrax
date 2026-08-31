<?php

declare(strict_types=1);

return [
    'page' => [
        'title' => 'Kalendorius',
        'subtitle' => 'Artėjantys mokėjimai ir prognozuojamas dienos likutis.',
    ],

    'summary' => [
        'computing' => 'Prognozė atnaujinama…',
        'risk' => 'Likutis nukrenta žemiau :zero :count dieną — pirmoji: :date.|Likutis nukrenta žemiau :zero :count dienas — pirmoji: :date.|Likutis nukrenta žemiau :zero :count dienų — pirmoji: :date.',
    ],

    'toolbar' => [
        'prev_month' => 'Ankstesnis mėnuo',
        'next_month' => 'Kitas mėnuo',
        'accounts' => 'Sąskaitos',
        'popover_aria' => 'Sąskaitų rodymo nustatymai',
        'no_accounts' => 'Sąskaitų nerasta.',
        'col_account' => 'Sąskaita',
        'col_entries' => 'Įrašai',
        'col_balance' => 'Likutis',
        'show_entries_aria' => 'Rodyti sąskaitos :name įrašus',
        'count_balance_aria' => 'Įskaičiuoti :name į likutį',
    ],

    'empty' => [
        'heading' => 'Artėjančių mokėjimų nėra',
        'body' => 'Prijunk sąskaitą arba patvirtink pasikartojančių mokėjimų seriją, kad kalendoriuje matytum prognozuojamus mokėjimus.',
        'review' => 'Peržiūrėti pasikartojančius →',
    ],

    'weekdays' => [
        'mon' => 'Pr',
        'tue' => 'An',
        'wed' => 'Tr',
        'thu' => 'Kt',
        'fri' => 'Pn',
        'sat' => 'Št',
        'sun' => 'Sk',
    ],

    'grid' => [
        'aria' => ':month kalendorius',
    ],

    'cell' => [
        'entry' => 'įrašas|įrašai|įrašų',
        'aria' => ':date: :count :entries',
        'aria_balance_negative' => ', prognozuojamas likutis minus :amount',
        'aria_balance_positive' => ', prognozuojamas likutis :amount',
        'overflow' => 'dar :count',
        'paid' => 'Apmokėta',
        'missed' => 'Tikėtasi — nerasta',
    ],

    'entry' => [
        'booked_unnamed' => 'Užregistruotas mokėjimas',
    ],

    'balance' => [
        'not_counted' => '· :list neįskaičiuota — ten atlikti mokėjimai nekeičia likučio',
    ],

    'panel' => [
        'aria' => 'Dienos informacijos skydelis',
        'close' => 'Uždaryti dienos skydelį',
        'close_caption' => 'Uždaryti',
        'start_of_day' => 'Dienos pradžia',
        'no_payments' => 'Šią dieną mokėjimų nėra.',
        'date_approximate' => '~ data apytikslė',
        'series' => '↗ serija',
        'counterparty' => '↗ kita šalis',
        'transaction' => '↗ operacija',
        'end_of_day' => 'Dienos pabaiga',
    ],
];
