<?php

declare(strict_types=1);

return [
    'page' => [
        'title' => 'Kalendár',
        'subtitle' => 'Nadchádzajúce platby a tvoj predpokladaný denný zostatok.',
    ],

    'summary' => [
        'computing' => 'Prognóza sa aktualizuje…',
        'risk' => 'Zostatok klesne pod :zero dňa :date.|Zostatok klesne pod :zero v :count dňoch — prvý: :date.|Zostatok klesne pod :zero v :count dňoch — prvý: :date.',
    ],

    'toolbar' => [
        'prev_month' => 'Predchádzajúci mesiac',
        'next_month' => 'Nasledujúci mesiac',
        'accounts' => 'Účty',
        'popover_aria' => 'Nastavenia zobrazenia účtov',
        'no_accounts' => 'Nenašli sa žiadne účty.',
        'col_account' => 'Účet',
        'col_entries' => 'Položky',
        'col_balance' => 'Zostatok',
        'show_entries_aria' => 'Zobraziť položky pre účet: :name',
        'count_balance_aria' => 'Započítať do zostatku účet: :name',
    ],

    'empty' => [
        'heading' => 'Žiadne nadchádzajúce platby',
        'body' => 'Pripoj účet alebo schváľ opakovanú sériu a v kalendári uvidíš svoje očakávané platby.',
        'review' => 'Skontrolovať opakované →',
    ],

    'weekdays' => [
        'mon' => 'Po',
        'tue' => 'Ut',
        'wed' => 'St',
        'thu' => 'Št',
        'fri' => 'Pi',
        'sat' => 'So',
        'sun' => 'Ne',
    ],

    'grid' => [
        'aria' => 'Kalendár: :month',
    ],

    'cell' => [
        'entry' => 'položka|položky|položiek',
        'aria' => ':date: :entries — :count',
        'aria_balance_negative' => ', predpokladaný zostatok mínus :amount',
        'aria_balance_positive' => ', predpokladaný zostatok :amount',
        'overflow' => '+:count ďalších',
        'paid' => 'Zaplatené',
        'missed' => 'Očakávané — nenájdené',
    ],

    'entry' => [
        'booked_unnamed' => 'Zaúčtovaná platba',
    ],

    'panel' => [
        'aria' => 'Panel s detailom dňa',
        'close' => 'Zavrieť panel dňa',
        'start_of_day' => 'Začiatok dňa',
        'no_payments' => 'V tento deň nie sú žiadne platby.',
        'date_approximate' => '~ približný dátum',
        'series' => '↗ séria',
        'counterparty' => '↗ protistrana',
        'transaction' => '↗ transakcia',
        'end_of_day' => 'Koniec dňa',
    ],
];
