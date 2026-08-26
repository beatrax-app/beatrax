<?php

declare(strict_types=1);

return [
    'page' => [
        'title' => 'Kalender',
        'subtitle' => 'Kommende betalinger og din forventede daglige saldo.',
    ],

    'summary' => [
        'computing' => 'Prognosen opdateres…',
        'risk' => 'Saldoen falder under :zero den :date.|Saldoen falder under :zero på :count dage — første: :date.',
    ],

    'toolbar' => [
        'prev_month' => 'Forrige måned',
        'next_month' => 'Næste måned',
        'accounts' => 'Konti',
        'popover_aria' => 'Indstillinger for kontovisning',
        'no_accounts' => 'Ingen konti fundet.',
        'col_account' => 'Konto',
        'col_entries' => 'Poster',
        'col_balance' => 'Saldo',
        'show_entries_aria' => 'Vis poster for :name',
        'count_balance_aria' => 'Medregn :name i saldoen',
    ],

    'empty' => [
        'heading' => 'Ingen kommende betalinger',
        'body' => 'Tilslut en konto, eller godkend en tilbagevendende serie for at se dine forventede betalinger i kalenderen.',
        'review' => 'Gennemgå tilbagevendende →',
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
        'overflow' => '+:count mere',
        'paid' => 'Betalt',
        'missed' => 'Forventet — ikke fundet',
    ],

    'entry' => [
        'booked_unnamed' => 'Bogført betaling',
    ],

    'panel' => [
        'aria' => 'Panel med dagsdetaljer',
        'close' => 'Luk dagspanelet',
        'start_of_day' => 'Dagens begyndelse',
        'no_payments' => 'Ingen betalinger denne dag.',
        'date_approximate' => '~ omtrentlig dato',
        'series' => '↗ serie',
        'counterparty' => '↗ modpart',
        'transaction' => '↗ transaktion',
        'end_of_day' => 'Dagens slutning',
    ],
];
