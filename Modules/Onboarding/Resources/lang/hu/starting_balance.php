<?php

declare(strict_types=1);

return [
    'eyebrow' => '🧮 NYITÓ EGYENLEG',
    'confirmed_aria' => 'megerősítve',
    'on_date' => ':date napon',

    'detected_h3' => 'Úgy látjuk, a(z) :label ezzel indult:',
    'confirm' => 'Megerősítés',
    'edit' => 'Szerkesztés',

    'conflict_h3' => 'Két értéket láttunk ehhez a számlához — melyik a helyes?',
    'conflict_legend' => 'Válassz nyitó egyenleget',
    'conflict_from' => ':source szerint:',
    'conflict_helper' => 'Alapértelmezetten a legkorábbi dátumot választjuk. Válaszd ki a helyeset, vagy szerkeszd kézzel.',
    'edit_manually' => 'Kézi szerkesztés',

    'editing_h3' => 'A(z) :label nyitó egyenlegének szerkesztése',
    'input_label' => 'NYITÓ EGYENLEG',
    'minor_units' => '(váltópénzben)',
    'on_date_label' => 'DÁTUM',
    'cancel' => 'Mégse',
    'save' => 'Mentés',

    'change' => 'Módosítás',

    'manual_h3' => 'Add meg kézzel a(z) :label nyitó egyenlegét',
    'manual_lede' => 'Nem sikerült automatikusan felismerni a számla nyitó egyenlegét. Add meg kézzel, vagy hagyd ki.',

    'unknown_state' => 'Ismeretlen kártyaállapot. Töltsd újra a varázslót.',

    'errors' => [
        'account_not_set' => 'A számla nincs beállítva. Töltsd újra a varázslót.',
        'invalid_amount' => 'Adj meg érvényes összeget.',
        'amount_range' => 'Adj meg :min és :max közötti összeget.',
        'pick_date' => 'Válassz dátumot.',
        'pick_valid_date' => 'Válassz érvényes dátumot.',
        'future_date' => 'A nyitó egyenleg dátuma nem lehet a jövőben.',
        'date_warning' => 'Ez későbbi, mint az első importált tranzakciód (:date). Az irányítópultodon megjelenhetnek e dátum előtti tranzakciók.',
    ],
];
