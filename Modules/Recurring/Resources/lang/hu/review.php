<?php

declare(strict_types=1);

return [
    'title' => 'Ismétlődők áttekintése',
    'subtitle' => 'Hagyd jóvá, halaszd el vagy utasítsd el a felismert ismétlődő javaslatokat.',

    'tabs' => [
        'pending' => 'Függőben',
        'rejected' => 'Elutasítva',
        'cadence_changed' => 'Gyakoriság módosult',
    ],

    'bulk' => [
        'aria' => 'Tömeges műveletek',
        'selected' => ':count kijelölve',
        'approve' => 'Jóváhagyás: :count',
        'reject' => 'Elutasítás: :count',
    ],

    'empty' => [
        'heading' => 'Nincs áttekintendő tétel',
        'pending' => 'Az ismétlődő javaslatok itt jelennek meg, ahogy a felismerő stabil havi csoportokat talál.',
        'rejected' => 'Az elutasított javaslatok itt maradnak, hogy visszahozhasd őket, ha meggondolod magad.',
        'cadence_changed' => 'A megváltozott gyakoriságú jóváhagyott sorozatok itt jelennek meg újbóli áttekintésre.',
    ],

    'next' => 'Következő',
    'overdue' => 'Lejárt',
    'cadence_changed_note' => 'a gyakoriság módosult',
    'un_reject' => 'Elutasítás visszavonása',
    'approve' => 'Jóváhagyás',
    'approve_aria' => 'A(z) :id ismétlődő sorozat jóváhagyása',
    'reject' => 'Elutasítás',
    'reject_aria' => 'A(z) :id ismétlődő sorozat elutasítása',
    'snooze' => 'Halasztás',
    'snooze_aria' => 'A(z) :id ismétlődő sorozat halasztása',
    'snooze_1w' => '1 hét',
    'snooze_1m' => '1 hónap',
    'snooze_3m' => '3 hónap',
    'edit_name' => 'Név szerkesztése',
    'edit_name_aria' => 'A(z) :id ismétlődő sorozat átnevezése',
    'new_name_label' => 'A sorozat új neve',
    'load_more' => 'Továbbiak betöltése',
    'save' => 'Mentés',

    'toast' => [
        'approved' => 'Jóváhagyva',
        'rejected' => 'Elutasítva',
        'snoozed' => 'Elhalasztva',
        'renamed' => 'Átnevezve',
        'un_rejected' => 'Elutasítás visszavonva',
        'bulk_approved' => ':count jóváhagyva',
        'bulk_rejected' => ':count elutasítva',
    ],
];
