<?php

declare(strict_types=1);

return [
    'page' => [
        'title' => 'Kalender',
        'subtitle' => 'Eesseisvad maksed ja sinu prognoositav päevajääk.',
    ],

    'summary' => [
        'computing' => 'Prognoosi uuendatakse…',
        'risk' => 'Jääk langeb alla :zero kuupäeval :date.|Jääk langeb alla :zero :count päeval — esimene: :date.',
    ],

    'toolbar' => [
        'prev_month' => 'Eelmine kuu',
        'next_month' => 'Järgmine kuu',
        'accounts' => 'Kontod',
        'popover_aria' => 'Konto kuvamise seaded',
        'no_accounts' => 'Kontosid ei leitud.',
        'col_account' => 'Konto',
        'col_entries' => 'Kirjed',
        'col_balance' => 'Jääk',
        'show_entries_aria' => 'Näita konto :name kirjeid',
        'count_balance_aria' => 'Arvesta konto :name jäägi hulka',
    ],

    'empty' => [
        'heading' => 'Eesseisvaid makseid pole',
        'body' => 'Ühenda konto või kinnita korduvmaksete seeria, et näha kalendris prognoositud makseid.',
        'review' => 'Vaata korduvmakseid üle →',
    ],

    'weekdays' => [
        'mon' => 'E',
        'tue' => 'T',
        'wed' => 'K',
        'thu' => 'N',
        'fri' => 'R',
        'sat' => 'L',
        'sun' => 'P',
    ],

    'grid' => [
        'aria' => ':month kalender',
    ],

    'cell' => [
        'entry' => 'kirje|kirjet',
        'aria' => ':date: :count :entries',
        'aria_balance_negative' => ', prognoositav jääk miinus :amount',
        'aria_balance_positive' => ', prognoositav jääk :amount',
        'overflow' => '+:count veel',
        'paid' => 'Makstud',
        'missed' => 'Oodatud — ei leitud',
    ],

    'entry' => [
        'booked_unnamed' => 'Kirjendatud makse',
    ],

    'balance' => [
        'not_counted' => '· :list ei arvestata — sealsed maksed ei muuda saldot',
    ],

    'panel' => [
        'aria' => 'Päeva üksikasjade paneel',
        'close' => 'Sulge päevapaneel',
        'close_caption' => 'Sulge',
        'start_of_day' => 'Päeva algus',
        'no_payments' => 'Sel päeval makseid pole.',
        'date_approximate' => '~ kuupäev on ligikaudne',
        'series' => '↗ seeria',
        'counterparty' => '↗ vastaspool',
        'transaction' => '↗ tehing',
        'end_of_day' => 'Päeva lõpp',
    ],
];
