<?php

declare(strict_types=1);

return [
    'page' => [
        'title' => 'Calendar',
        'subtitle' => 'Plățile viitoare și soldul tău zilnic estimat.',
    ],

    'summary' => [
        'computing' => 'Se actualizează proiecția…',
        'risk' => 'Soldul scade sub :zero pe :date.|Soldul scade sub :zero în :count zile — prima: :date.|Soldul scade sub :zero în :count de zile — prima: :date.',
    ],

    'toolbar' => [
        'prev_month' => 'Luna anterioară',
        'next_month' => 'Luna următoare',
        'accounts' => 'Conturi',
        'popover_aria' => 'Setări de afișare a conturilor',
        'no_accounts' => 'Nu s-a găsit niciun cont.',
        'col_account' => 'Cont',
        'col_entries' => 'Intrări',
        'col_balance' => 'Sold',
        'show_entries_aria' => 'Arată intrările pentru :name',
        'count_balance_aria' => 'Numără :name în sold',
    ],

    'empty' => [
        'heading' => 'Nicio plată viitoare',
        'body' => 'Conectează un cont sau aprobă o serie recurentă pentru a vedea plățile estimate în calendar.',
        'review' => 'Verifică recurentele →',
    ],

    'weekdays' => [
        'mon' => 'Lu',
        'tue' => 'Ma',
        'wed' => 'Mi',
        'thu' => 'Jo',
        'fri' => 'Vi',
        'sat' => 'Sâ',
        'sun' => 'Du',
    ],

    'grid' => [
        'aria' => 'Calendar :month',
    ],

    'cell' => [
        'entry' => 'intrare|intrări|de intrări',
        'aria' => ':date: :count :entries',
        'aria_balance_negative' => ', sold estimat minus :amount',
        'aria_balance_positive' => ', sold estimat :amount',
        'overflow' => '+:count în plus',
        'paid' => 'Plătit',
        'missed' => 'Așteptat — negăsit',
    ],

    'entry' => [
        'booked_unnamed' => 'Plată înregistrată',
    ],

    'panel' => [
        'aria' => 'Panou cu detaliile zilei',
        'close' => 'Închide panoul zilei',
        'start_of_day' => 'Începutul zilei',
        'no_payments' => 'Nicio plată în această zi.',
        'date_approximate' => '~ dată aproximativă',
        'series' => '↗ serie',
        'counterparty' => '↗ contraparte',
        'transaction' => '↗ tranzacție',
        'end_of_day' => 'Sfârșitul zilei',
    ],
];
