<?php

declare(strict_types=1);

return [
    'page_title' => 'Egyeztetés',
    'heading' => 'Egyeztetés',
    'intro' => 'Vesd össze a számla kivonategyenlegét az elszámolt tranzakcióiddal. Ha egyeznek, zárd le az egyeztetést, hogy a sorok a helyükön rögzüljenek.',

    'account' => 'Számla',
    'choose_account' => 'Válassz számlát…',
    'statement_date' => 'Kivonat dátuma',
    'statement_balance' => 'Kivonategyenleg (:symbol)',
    'balance_help' => 'Ha van rá adat, a legutóbb importált számlakivonatból előre kitöltve — tartozás esetén negatív, mindkét esetben szerkeszthető.',

    'cleared_balance' => 'Elszámolt egyenleg',
    'statement_target' => 'Kivonat szerinti célérték',
    'difference' => 'Különbség',

    'pill' => [
        'choose_account' => 'válassz számlát',
        'choose_date' => 'válaszd ki a kivonat dátumát',
        'enter_balance' => 'add meg a kivonategyenleget',
        'matched' => 'egyezik — :amount',
        'discrepancy' => 'eltérés — :amount',
        'reconciled_through' => 'egyeztetve eddig: :date',
    ],

    'mismatch_html' => 'A kivonategyenleg még nem egyezik az elszámolt egyenlegeddel. Kapcsolgasd az elszámolt sorokat a <a href=":url" class="underline">tranzakciólistán</a>, vagy módosítsd a megadott egyenleget, amíg a különbség nulla nem lesz — ez a folyamat soha nem hoz létre kiegyenlítő tételt.',
    'unreachable_no_baseline_html' => 'A sorok átkapcsolgatása nem tudja nullára hozni ezt a különbséget. Ehhez a számlához nincs rögzítve nyitó egyenleg, ezért az egyenlege nulláról számít. Importáld azt a kivonatot, amellyel a számla indul, vagy add meg a nyitó egyenleget a <a href=":url" class="underline">Beállításokban</a>.',
    'unreachable' => 'A sorok átkapcsolgatása nem tudja nullára hozni ezt a különbséget: kívül esik a számla összes sorának tartományán a megadott dátumig. Ellenőrizd a kivonat dátumát és a megadott egyenleget.',

    'check' => 'Ellenőrzés',
    'complete' => 'Egyeztetés lezárása',
    'complete_unavailable' => 'Eddig a dátumig már nincs mit lezárni — jelölj meg több sort elszámoltként, vagy válassz későbbi kivonatdátumot.',

    'errors' => [
        'choose_account' => 'Először válassz számlát.',
        'invalid_balance_date' => 'Adj meg érvényes kivonategyenleget és dátumot.',
        'mismatch' => 'A kivonategyenleg még nem egyezik az elszámolt egyenleggel — módosítsd az elszámolt sorokat vagy a megadott egyenleget, amíg a különbség nulla nem lesz.',
    ],

    'toast' => [
        'nothing_to_lock' => 'Ehhez a kivonatdátumhoz nincs mit lezárni.',
        'complete' => 'Egyeztetés kész — :count sor lezárva.|Egyeztetés kész — :count sor lezárva.',
    ],
];
