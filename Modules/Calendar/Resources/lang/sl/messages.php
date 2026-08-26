<?php

declare(strict_types=1);

return [
    'page' => [
        'title' => 'Koledar',
        'subtitle' => 'Prihajajoča plačila in tvoje predvideno dnevno stanje.',
    ],

    'summary' => [
        'computing' => 'Posodabljanje napovedi…',
        'risk' => 'Stanje pade pod :zero v :count dnevu — prvi: :date.|Stanje pade pod :zero v :count dneh — prvi: :date.|Stanje pade pod :zero v :count dneh — prvi: :date.|Stanje pade pod :zero v :count dneh — prvi: :date.',
    ],

    'toolbar' => [
        'prev_month' => 'Prejšnji mesec',
        'next_month' => 'Naslednji mesec',
        'accounts' => 'Računi',
        'popover_aria' => 'Nastavitve prikaza računov',
        'no_accounts' => 'Ni najdenih računov.',
        'col_account' => 'Račun',
        'col_entries' => 'Postavke',
        'col_balance' => 'Stanje',
        'show_entries_aria' => 'Prikaži postavke za :name',
        'count_balance_aria' => 'Upoštevaj :name v stanju',
    ],

    'empty' => [
        'heading' => 'Ni prihajajočih plačil',
        'body' => 'Poveži račun ali odobri ponavljajočo serijo, da v koledarju vidiš svoja predvidena plačila.',
        'review' => 'Preglej ponavljajoča plačila →',
    ],

    'weekdays' => [
        'mon' => 'Pon',
        'tue' => 'Tor',
        'wed' => 'Sre',
        'thu' => 'Čet',
        'fri' => 'Pet',
        'sat' => 'Sob',
        'sun' => 'Ned',
    ],

    'grid' => [
        'aria' => 'Koledar za :month',
    ],

    'cell' => [
        'entry' => 'postavka|postavki|postavke|postavk',
        'aria' => ':date: :count :entries',
        'aria_balance_negative' => ', predvideno stanje minus :amount',
        'aria_balance_positive' => ', predvideno stanje :amount',
        'overflow' => '+:count več',
        'paid' => 'Plačano',
        'missed' => 'Pričakovano — ni najdeno',
    ],

    'entry' => [
        'booked_unnamed' => 'Knjiženo plačilo',
    ],

    'panel' => [
        'aria' => 'Plošča s podrobnostmi dneva',
        'close' => 'Zapri ploščo dneva',
        'start_of_day' => 'Začetek dneva',
        'no_payments' => 'Na ta dan ni plačil.',
        'date_approximate' => '~ datum približen',
        'series' => '↗ serija',
        'counterparty' => '↗ nasprotna stranka',
        'transaction' => '↗ transakcija',
        'end_of_day' => 'Konec dneva',
    ],
];
