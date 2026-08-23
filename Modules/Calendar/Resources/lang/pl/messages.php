<?php

declare(strict_types=1);

return [
    'page' => [
        'title' => 'Kalendarz',
        'subtitle' => 'Nadchodzące płatności i prognozowane saldo dzienne.',
    ],

    'summary' => [
        'computing' => 'Aktualizowanie prognozy…',
        'risk' => 'Saldo spada poniżej €0 w dniu :date.|Saldo spada poniżej €0 w :count dniach — pierwszy: :date.|Saldo spada poniżej €0 w :count dniach — pierwszy: :date.',
    ],

    'toolbar' => [
        'prev_month' => 'Poprzedni miesiąc',
        'next_month' => 'Następny miesiąc',
        'accounts' => 'Konta',
        'popover_aria' => 'Ustawienia wyświetlania kont',
        'no_accounts' => 'Nie znaleziono kont.',
        'col_account' => 'Konto',
        'col_entries' => 'Pozycje',
        'col_balance' => 'Saldo',
        'show_entries_aria' => 'Pokaż pozycje — konto: :name',
        'count_balance_aria' => 'Uwzględnij w saldzie konto: :name',
    ],

    'empty' => [
        'heading' => 'Brak nadchodzących płatności',
        'body' => 'Podłącz konto lub zatwierdź serię cykliczną, aby zobaczyć prognozowane płatności w kalendarzu.',
        'review' => 'Przejrzyj cykliczne →',
    ],

    'weekdays' => [
        'mon' => 'Pon',
        'tue' => 'Wt',
        'wed' => 'Śr',
        'thu' => 'Czw',
        'fri' => 'Pt',
        'sat' => 'Sob',
        'sun' => 'Nd',
    ],

    'grid' => [
        'aria' => 'Kalendarz: :month',
    ],

    'cell' => [
        'entry' => 'pozycja|pozycje|pozycji',
        'aria' => ':date: :entries — :count',
        'aria_balance_negative' => ', prognozowane saldo minus :amount',
        'aria_balance_positive' => ', prognozowane saldo :amount',
        'overflow' => '+:count więcej',
        'paid' => 'Opłacone',
        'missed' => 'Oczekiwane — nie znaleziono',
    ],

    'panel' => [
        'aria' => 'Panel szczegółów dnia',
        'close' => 'Zamknij panel dnia',
        'start_of_day' => 'Początek dnia',
        'no_payments' => 'Brak płatności w tym dniu.',
        'date_approximate' => '~ data przybliżona',
        'series' => '↗ seria',
        'counterparty' => '↗ kontrahent',
        'end_of_day' => 'Koniec dnia',
    ],
];
