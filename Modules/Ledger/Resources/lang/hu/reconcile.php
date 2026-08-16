<?php

declare(strict_types=1);

return [
    'page_title' => 'Egyeztetés',
    'heading' => 'Egyeztetés',
    'intro' => 'Vesd össze a számla kivonategyenlegét az elszámolt tranzakcióiddal. Ha egyeznek, zárd le az egyeztetést, hogy a sorok a helyükön rögzüljenek.',

    'account' => 'Számla',
    'choose_account' => 'Válassz számlát…',
    'statement_date' => 'Kivonat dátuma',
    'statement_balance' => 'Kivonategyenleg (€)',
    'balance_help' => 'Ha van rá adat, a legutóbb importált számlakivonatból előre kitöltve — tartozás esetén negatív, mindkét esetben szerkeszthető.',

    'cleared_balance' => 'Elszámolt egyenleg',
    'statement_target' => 'Kivonat szerinti célérték',
    'difference' => 'Különbség',

    'pill' => [
        'choose_account' => 'válassz számlát',
        'enter_balance' => 'add meg a kivonategyenleget',
        'matched' => 'egyezik — :amount',
        'discrepancy' => 'eltérés — :amount',
    ],

    'mismatch_html' => 'A kivonategyenleg még nem egyezik az elszámolt egyenlegeddel. Kapcsolgasd az elszámolt sorokat a <a href=":url" class="underline">tranzakciólistán</a>, vagy módosítsd a megadott egyenleget, amíg a különbség nulla nem lesz — ez a folyamat soha nem hoz létre kiegyenlítő tételt.',

    'check' => 'Ellenőrzés',
    'complete' => 'Egyeztetés lezárása',

    'errors' => [
        'choose_account' => 'Először válassz számlát.',
        'invalid_balance_date' => 'Adj meg érvényes kivonategyenleget és dátumot.',
        'mismatch' => 'A kivonategyenleg még nem egyezik az elszámolt egyenleggel — módosítsd az elszámolt sorokat vagy a megadott egyenleget, amíg a különbség nulla nem lesz.',
    ],

    'toast' => [
        'nothing_to_lock' => 'Ehhez a kivonatdátumhoz nincs mit lezárni.',
        'complete' => 'Egyeztetés kész — :count sor lezárva.',
    ],
];
