<?php

declare(strict_types=1);

return [
    'page' => [
        'title' => 'Kalendar',
        'subtitle' => 'Predstojeća plaćanja i tvoje predviđeno dnevno stanje.',
    ],

    'summary' => [
        'computing' => 'Ažuriranje prognoze…',
        'risk' => 'Stanje pada ispod :zero tokom :count dana — prvi: :date.|Stanje pada ispod :zero tokom :count dana — prvi: :date.|Stanje pada ispod :zero tokom :count dana — prvi: :date.',
    ],

    'toolbar' => [
        'prev_month' => 'Prethodni mesec',
        'next_month' => 'Sledeći mesec',
        'accounts' => 'Računi',
        'popover_aria' => 'Podešavanja prikaza računa',
        'no_accounts' => 'Nije pronađen nijedan račun.',
        'col_account' => 'Račun',
        'col_entries' => 'Stavke',
        'col_balance' => 'Stanje',
        'show_entries_aria' => 'Prikaži stavke za :name',
        'count_balance_aria' => 'Uračunaj :name u stanje',
    ],

    'empty' => [
        'heading' => 'Nema predstojećih plaćanja',
        'body' => 'Poveži račun ili odobri ponavljajuću seriju kako bi u kalendaru video svoja predviđena plaćanja.',
        'review' => 'Pregledaj ponavljajuća plaćanja →',
    ],

    'weekdays' => [
        'mon' => 'Pon',
        'tue' => 'Uto',
        'wed' => 'Sre',
        'thu' => 'Čet',
        'fri' => 'Pet',
        'sat' => 'Sub',
        'sun' => 'Ned',
    ],

    'grid' => [
        'aria' => 'Kalendar za :month',
    ],

    'cell' => [
        'entry' => 'stavka|stavke|stavki',
        'aria' => ':date: :count :entries',
        'aria_balance_negative' => ', predviđeno stanje minus :amount',
        'aria_balance_positive' => ', predviđeno stanje :amount',
        'overflow' => '+:count više',
        'paid' => 'Plaćeno',
        'missed' => 'Očekivano — nije pronađeno',
    ],

    'entry' => [
        'booked_unnamed' => 'Proknjiženo plaćanje',
    ],

    'balance' => [
        'not_counted' => '· :list se ne računa — tamošnja plaćanja ne menjaju stanje',
    ],

    'panel' => [
        'aria' => 'Panel sa detaljima dana',
        'close' => 'Zatvori panel dana',
        'close_caption' => 'Zatvori',
        'start_of_day' => 'Početak dana',
        'no_payments' => 'Nema plaćanja na ovaj dan.',
        'date_approximate' => '~ datum približan',
        'series' => '↗ serija',
        'counterparty' => '↗ druga strana',
        'transaction' => '↗ transakcija',
        'end_of_day' => 'Kraj dana',
    ],
];
