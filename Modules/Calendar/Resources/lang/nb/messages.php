<?php

declare(strict_types=1);

return [
    'page' => [
        'title' => 'Kalender',
        'subtitle' => 'Kommende betalinger og forventet daglig saldo.',
    ],

    'summary' => [
        'computing' => 'Prognosen oppdateres…',
        'risk' => 'Saldoen faller under :zero den :date.|Saldoen faller under :zero på :count dager — første: :date.',
    ],

    'toolbar' => [
        'prev_month' => 'Forrige måned',
        'next_month' => 'Neste måned',
        'accounts' => 'Kontoer',
        'popover_aria' => 'Innstillinger for kontovisning',
        'no_accounts' => 'Fant ingen kontoer.',
        'col_account' => 'Konto',
        'col_entries' => 'Poster',
        'col_balance' => 'Saldo',
        'show_entries_aria' => 'Vis poster for :name',
        'count_balance_aria' => 'Regn med :name i saldoen',
    ],

    'empty' => [
        'heading' => 'Ingen kommende betalinger',
        'body' => 'Koble til en konto eller godkjenn en gjentakende serie for å se de forventede betalingene dine i kalenderen.',
        'review' => 'Gjennomgå gjentakende →',
    ],

    'weekdays' => [
        'mon' => 'Man',
        'tue' => 'Tir',
        'wed' => 'Ons',
        'thu' => 'Tor',
        'fri' => 'Fre',
        'sat' => 'Lør',
        'sun' => 'Søn',
    ],

    'grid' => [
        'aria' => 'Kalender for :month',
    ],

    'cell' => [
        'entry' => 'post|poster',
        'aria' => ':date: :count :entries',
        'aria_balance_negative' => ', forventet saldo minus :amount',
        'aria_balance_positive' => ', forventet saldo :amount',
        'overflow' => '+:count til',
        'paid' => 'Betalt',
        'missed' => 'Forventet — ikke funnet',
    ],

    'entry' => [
        'booked_unnamed' => 'Bokført betaling',
    ],

    'panel' => [
        'aria' => 'Panel med dagsdetaljer',
        'close' => 'Lukk dagspanelet',
        'start_of_day' => 'Starten av dagen',
        'no_payments' => 'Ingen betalinger denne dagen.',
        'date_approximate' => '~ omtrentlig dato',
        'series' => '↗ serie',
        'counterparty' => '↗ motpart',
        'transaction' => '↗ transaksjon',
        'end_of_day' => 'Slutten av dagen',
    ],
];
