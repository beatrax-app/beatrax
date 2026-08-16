<?php

declare(strict_types=1);

return [
    'page' => [
        'title' => 'Kalender',
        'subtitle' => 'Anstehende Zahlungen und dein voraussichtlicher Tagessaldo.',
    ],

    'summary' => [
        'computing' => 'Prognose wird aktualisiert…',
        'risk' => 'Saldo fällt am :date unter €0.|Saldo fällt an :count Tagen unter €0 — erster: :date.',
    ],

    'toolbar' => [
        'prev_month' => 'Vorheriger Monat',
        'next_month' => 'Nächster Monat',
        'accounts' => 'Konten',
        'popover_aria' => 'Anzeigeeinstellungen für Konten',
        'no_accounts' => 'Keine Konten gefunden.',
        'col_account' => 'Konto',
        'col_entries' => 'Zahlungen',
        'col_balance' => 'Saldo',
        'show_entries_aria' => 'Zahlungen für :name anzeigen',
        'count_balance_aria' => ':name im Saldo mitzählen',
    ],

    'empty' => [
        'heading' => 'Keine anstehenden Zahlungen',
        'body' => 'Verbinde ein Konto oder bestätige eine wiederkehrende Reihe, um deine erwarteten Zahlungen im Kalender zu sehen.',
        'review' => 'Wiederkehrendes prüfen →',
    ],

    'weekdays' => [
        'mon' => 'Mo',
        'tue' => 'Di',
        'wed' => 'Mi',
        'thu' => 'Do',
        'fri' => 'Fr',
        'sat' => 'Sa',
        'sun' => 'So',
    ],

    'grid' => [
        'aria' => 'Kalender :month',
    ],

    'cell' => [
        'entry' => 'Zahlung|Zahlungen',
        'aria' => ':date: :count :entries',
        'aria_balance_negative' => ', voraussichtlicher Saldo minus €:amount',
        'aria_balance_positive' => ', voraussichtlicher Saldo €:amount',
        'overflow' => '+:count weitere',
        'paid' => 'Bezahlt',
        'missed' => 'Erwartet — nicht gefunden',
    ],

    'panel' => [
        'aria' => 'Detailbereich Tag',
        'close' => 'Tagesbereich schließen',
        'start_of_day' => 'Tagesbeginn',
        'no_payments' => 'Keine Zahlungen an diesem Tag.',
        'date_approximate' => '~ Datum ungefähr',
        'series' => '↗ Reihe',
        'counterparty' => '↗ Zahlungspartner',
        'end_of_day' => 'Tagesende',
    ],
];
