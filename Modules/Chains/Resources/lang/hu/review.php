<?php

declare(strict_types=1);

return [
    'page_title' => 'Láncok áttekintése',
    'heading' => 'Láncok áttekintése',
    'hint' => '{0} :count tipp|[1,1] :count tipp|[2,*] :count tipp',
    'subtitle' => 'Erősítsd meg vagy utasítsd el azokat a jelölt kapcsolatokat, amelyeket a láncfeloldó nem tudott automatikusan megerősíteni.',

    'empty_heading' => 'Nincs áttekintendő tétel',
    'empty_body' => 'Minden lánckapcsolat vagy megerősített, vagy elutasított. Az új jelöltek az importok beérkezésekor jelennek meg itt.',

    'auto_confirm_nudge' => 'Még egy megerősítés, és a hasonló kapcsolatok automatikusan megerősítésre kerülnek.',

    'confirm' => 'Megerősítés',
    'reject' => 'Elutasítás',
    'confirm_aria' => 'A(z) :id lánckapcsolat megerősítése',
    'reject_aria' => 'A(z) :id lánckapcsolat elutasítása',
    'show_more' => 'Több megjelenítése',

    'kind' => [
        'paypal_funding' => 'PayPal-fedezet',
        'ics_bulk_settle' => 'Csoportos iDEAL-elszámolás',
    ],

    'errors' => [
        'confirm_hint' => 'Ez a jelölt egy tipp — nyisd meg, és csatold hozzá a párját, mielőtt megerősíted.',
        'reject_hint' => 'Ez a jelölt egy tipp — nyisd meg, és csatold hozzá a párját, mielőtt elutasítod.',
    ],
];
