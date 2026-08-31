<?php

declare(strict_types=1);

return [
    'page' => [
        'title' => 'Kalendar',
        'subtitle' => 'Nadolazeća plaćanja i tvoje predviđeno dnevno stanje.',
    ],

    'summary' => [
        'computing' => 'Ažuriranje prognoze…',
        'risk' => 'Stanje pada ispod :zero tijekom :count dana — prvi: :date.|Stanje pada ispod :zero tijekom :count dana — prvi: :date.|Stanje pada ispod :zero tijekom :count dana — prvi: :date.',
    ],

    'toolbar' => [
        'prev_month' => 'Prethodni mjesec',
        'next_month' => 'Sljedeći mjesec',
        'accounts' => 'Računi',
        'popover_aria' => 'Postavke prikaza računa',
        'no_accounts' => 'Nije pronađen nijedan račun.',
        'col_account' => 'Račun',
        'col_entries' => 'Stavke',
        'col_balance' => 'Stanje',
        'show_entries_aria' => 'Prikaži stavke za :name',
        'count_balance_aria' => 'Uračunaj :name u stanje',
    ],

    'empty' => [
        'heading' => 'Nema nadolazećih plaćanja',
        'body' => 'Poveži račun ili odobri ponavljajuću seriju da u kalendaru vidiš svoja predviđena plaćanja.',
        'review' => 'Pregledaj ponavljajuća plaćanja →',
    ],

    'weekdays' => [
        'mon' => 'Pon',
        'tue' => 'Uto',
        'wed' => 'Sri',
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
        'not_counted' => '· :list se ne računa — tamošnja plaćanja ne mijenjaju stanje',
    ],

    'panel' => [
        'aria' => 'Ploča s detaljima dana',
        'close' => 'Zatvori ploču dana',
        'close_caption' => 'Zatvori',
        'start_of_day' => 'Početak dana',
        'no_payments' => 'Nema plaćanja na ovaj dan.',
        'date_approximate' => '~ datum približan',
        'series' => '↗ serija',
        'counterparty' => '↗ protustranka',
        'transaction' => '↗ transakcija',
        'end_of_day' => 'Kraj dana',
    ],
];
