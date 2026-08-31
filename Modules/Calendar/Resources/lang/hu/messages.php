<?php

declare(strict_types=1);

return [
    'page' => [
        'title' => 'Naptár',
        'subtitle' => 'Közelgő fizetések és az előrejelzett napi egyenleged.',
    ],

    'summary' => [
        'computing' => 'Előrejelzés frissítése…',
        'risk' => 'Az egyenleg :zero alá csökken ekkor: :date.|Az egyenleg :count napon :zero alá csökken — az első: :date.',
    ],

    'toolbar' => [
        'prev_month' => 'Előző hónap',
        'next_month' => 'Következő hónap',
        'accounts' => 'Számlák',
        'popover_aria' => 'Számlamegjelenítési beállítások',
        'no_accounts' => 'Nem található számla.',
        'col_account' => 'Számla',
        'col_entries' => 'Tételek',
        'col_balance' => 'Egyenleg',
        'show_entries_aria' => 'A(z) :name tételeinek megjelenítése',
        'count_balance_aria' => 'A(z) :name beszámítása az egyenlegbe',
    ],

    'empty' => [
        'heading' => 'Nincs közelgő fizetés',
        'body' => 'Csatlakoztass egy számlát, vagy hagyj jóvá egy ismétlődő sorozatot, hogy az előrejelzett fizetések megjelenjenek a naptárban.',
        'review' => 'Ismétlődők áttekintése →',
    ],

    'weekdays' => [
        'mon' => 'H',
        'tue' => 'K',
        'wed' => 'Sze',
        'thu' => 'Cs',
        'fri' => 'P',
        'sat' => 'Szo',
        'sun' => 'V',
    ],

    'grid' => [
        'aria' => ':month naptára',
    ],

    'cell' => [
        'entry' => 'tétel|tétel',
        'aria' => ':date: :count :entries',
        'aria_balance_negative' => ', előrejelzett egyenleg mínusz :amount',
        'aria_balance_positive' => ', előrejelzett egyenleg :amount',
        'overflow' => '+:count további',
        'paid' => 'Kifizetve',
        'missed' => 'Várt — nem található',
    ],

    'entry' => [
        'booked_unnamed' => 'Könyvelt fizetés',
    ],

    'balance' => [
        'not_counted' => '· :list nem számít bele — az ottani fizetések nem mozdítják az egyenleget',
    ],

    'panel' => [
        'aria' => 'Napi részletek panel',
        'close' => 'Napi panel bezárása',
        'close_caption' => 'Bezárása',
        'start_of_day' => 'Nap eleje',
        'no_payments' => 'Ezen a napon nincs fizetés.',
        'date_approximate' => '~ hozzávetőleges dátum',
        'series' => '↗ sorozat',
        'counterparty' => '↗ partner',
        'transaction' => '↗ tranzakció',
        'end_of_day' => 'Nap vége',
    ],
];
