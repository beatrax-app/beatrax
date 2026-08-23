<?php

declare(strict_types=1);

return [
    'page' => [
        'title' => 'Kalender',
        'subtitle' => 'Kommande betalningar och ditt prognostiserade dagliga saldo.',
    ],

    'summary' => [
        'computing' => 'Prognosen uppdateras…',
        'risk' => 'Saldot går under €0 den :date.|Saldot går under €0 :count dagar — först: :date.',
    ],

    'toolbar' => [
        'prev_month' => 'Föregående månad',
        'next_month' => 'Nästa månad',
        'accounts' => 'Konton',
        'popover_aria' => 'Inställningar för kontovisning',
        'no_accounts' => 'Inga konton hittades.',
        'col_account' => 'Konto',
        'col_entries' => 'Poster',
        'col_balance' => 'Saldo',
        'show_entries_aria' => 'Visa poster för :name',
        'count_balance_aria' => 'Räkna med :name i saldot',
    ],

    'empty' => [
        'heading' => 'Inga kommande betalningar',
        'body' => 'Anslut ett konto eller godkänn en återkommande serie för att se dina prognostiserade betalningar i kalendern.',
        'review' => 'Granska återkommande →',
    ],

    'weekdays' => [
        'mon' => 'Mån',
        'tue' => 'Tis',
        'wed' => 'Ons',
        'thu' => 'Tor',
        'fri' => 'Fre',
        'sat' => 'Lör',
        'sun' => 'Sön',
    ],

    'grid' => [
        'aria' => 'Kalender för :month',
    ],

    'cell' => [
        'entry' => 'post|poster',
        'aria' => ':date: :count :entries',
        'aria_balance_negative' => ', prognostiserat saldo minus :amount',
        'aria_balance_positive' => ', prognostiserat saldo :amount',
        'overflow' => '+:count till',
        'paid' => 'Betald',
        'missed' => 'Förväntad — hittades inte',
    ],

    'panel' => [
        'aria' => 'Panel med dagsdetaljer',
        'close' => 'Stäng dagspanelen',
        'start_of_day' => 'Dagens början',
        'no_payments' => 'Inga betalningar den här dagen.',
        'date_approximate' => '~ ungefärligt datum',
        'series' => '↗ serie',
        'counterparty' => '↗ motpart',
        'end_of_day' => 'Dagens slut',
    ],
];
