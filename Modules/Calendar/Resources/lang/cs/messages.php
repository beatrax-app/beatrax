<?php

declare(strict_types=1);

return [
    'page' => [
        'title' => 'Kalendář',
        'subtitle' => 'Nadcházející platby a tvůj odhadovaný denní zůstatek.',
    ],

    'summary' => [
        'computing' => 'Odhad se aktualizuje…',
        'risk' => 'Zůstatek klesne pod :zero dne :date.|Zůstatek klesne pod :zero v :count dnech — poprvé :date.|Zůstatek klesne pod :zero v :count dnech — poprvé :date.',
    ],

    'toolbar' => [
        'prev_month' => 'Předchozí měsíc',
        'next_month' => 'Další měsíc',
        'accounts' => 'Účty',
        'popover_aria' => 'Nastavení zobrazení účtů',
        'no_accounts' => 'Nenalezeny žádné účty.',
        'col_account' => 'Účet',
        'col_entries' => 'Položky',
        'col_balance' => 'Zůstatek',
        'show_entries_aria' => 'Zobrazit položky — účet: :name',
        'count_balance_aria' => 'Započítat do zůstatku — účet: :name',
    ],

    'empty' => [
        'heading' => 'Žádné nadcházející platby',
        'body' => 'Připoj účet nebo schval opakovanou sérii a v kalendáři uvidíš odhadované platby.',
        'review' => 'Zkontrolovat opakované →',
    ],

    'weekdays' => [
        'mon' => 'Po',
        'tue' => 'Út',
        'wed' => 'St',
        'thu' => 'Čt',
        'fri' => 'Pá',
        'sat' => 'So',
        'sun' => 'Ne',
    ],

    'grid' => [
        'aria' => 'Kalendář :month',
    ],

    'cell' => [
        'entry' => 'položka|položky|položek',
        'aria' => ':date: :count :entries',
        'aria_balance_negative' => ', odhadovaný zůstatek mínus :amount',
        'aria_balance_positive' => ', odhadovaný zůstatek :amount',
        'overflow' => '+:count dalších',
        'paid' => 'Zaplaceno',
        'missed' => 'Očekáváno — nenalezeno',
    ],

    'entry' => [
        'booked_unnamed' => 'Zaúčtovaná platba',
    ],

    'panel' => [
        'aria' => 'Panel s detailem dne',
        'close' => 'Zavřít panel dne',
        'start_of_day' => 'Začátek dne',
        'no_payments' => 'V tento den nejsou žádné platby.',
        'date_approximate' => '~ přibližné datum',
        'series' => '↗ série',
        'counterparty' => '↗ protistrana',
        'transaction' => '↗ transakce',
        'end_of_day' => 'Konec dne',
    ],
];
