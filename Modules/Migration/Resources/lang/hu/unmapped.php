<?php

declare(strict_types=1);

return [
    'label' => [
        'goal' => 'Cél: :name',
        'category_goal' => ':name kategória célja',
        'schedule_untitled' => 'Névtelen ütemezett tranzakció',
        'transaction' => 'Tranzakció: :name · :date · :amount',
        'transaction_unnamed' => 'Tranzakció',
        'amount_update' => 'Tranzakció összegének frissítése',
        'budget_history' => 'Költségvetési előzmények :currency pénznemben',
        'budget_file_currency' => 'A költségvetésfájl pénzneme',
        'budget_file_mode' => 'A költségvetésfájl módja',
    ],

    'conflict' => [
        'budget_assignment' => 'Költségvetés kiosztása',
        'budget_for_month' => ':category · :month költségvetése',
        'budget_for_category' => ':category költségvetése',
        'category_name' => 'Kategória neve',
        'category_name_of' => 'A(z) „:name” kategória neve',
        'account_name' => 'Számla neve',
        'account_name_of' => 'A(z) „:name” számla neve',
        'transaction_amount' => 'Tranzakció összege',
        'transaction_amount_of' => 'Összeg: :name',
        'transaction_amount_of_dated' => 'Összeg: :name · :date',
        'transaction_description' => 'Tranzakció leírása',
        'transaction_description_of' => 'Leírás: :name',
        'transaction_description_of_dated' => 'Leírás: :name · :date',
        'other' => 'Importált érték',
    ],

    'reason' => [
        'fingerprint_collision' => 'Ez a tranzakció ütközött egy már rögzített tranzakcióval (azonos ujjlenyomat), ezért nem lett importálva.',

        // i18n-review: hu · reason.split_legs_without_category — the article
        // before :uncategorized is written "a" because ledger::common.uncategorized
        // is today "Kategorizálatlan"; the repo's usual "a(z)" is for values no
        // key fixes. If that word ever starts with a vowel, this breaks.
        'split_legs_without_category' => ':legs felosztási tételből :count nem tartozik kategóriához, tétel viszont kategória nélkül nem tárolható. A tranzakció a teljes összegével importálódott, és a :uncategorized kategóriában várakozik.|:legs felosztási tételből :count nem tartozik kategóriához, tétel viszont kategória nélkül nem tárolható. A tranzakció a teljes összegével importálódott, és a :uncategorized kategóriában várakozik.',
        'split_sum_mismatch' => 'A felosztás tételei :legs összeget adnak ki, a tranzakció viszont :total, a felosztásnak pedig pontosan egyeznie kell a tranzakciójával. A tranzakció a teljes összegével importálódott, a tételei nélkül.',
        'split_unstorable' => 'A Beatrax ezt a felosztást így nem tudja tárolni, ezért a tranzakció önmagában, a tételei nélkül importálódott.',
        'goal_without_target_date' => 'Ennek a célnak nincs céldátuma; a Beatraxnak szüksége van rá megtakarítási cél létrehozásához.',
        'goal_without_name' => 'Ennek a célnak nincs neve; a Beatraxnak szüksége van rá megtakarítási cél létrehozásához.',
        'goal_def_unsupported' => 'A categories.goal_def nem támogatott (nem lapos) sablonformát használ — a cél nem lett importálva.',
        'budget_currency_mismatch' => ':count költségvetési sor nem lett importálva: a költségvetéseidet :envelope pénznemben vezeted, ez az export viszont :source pénznemben tervez költségvetést.|:count költségvetési sor nem lett importálva: a költségvetéseidet :envelope pénznemben vezeted, ez az export viszont :source pénznemben tervez költségvetést.',
        'amount_apply_collision' => 'A forrás új összegét nem lehetett alkalmazni — ütközik egy másik tranzakció ujjlenyomatával (ugyanaz a számla, dátum, pénznem és partner). Változatlanul maradt.',
        'amount_currency_mismatch' => 'A tranzakciók összegei nem lettek egyeztetve: ezek a tranzakciók :local pénznemben vannak vezetve, ez az export viszont :source pénznemben adja meg őket. Változatlanul maradtak.',
        'schedule_unsupported' => 'Az ütemezett és ismétlődő tranzakciókat a Beatrax még nem tudja külső forrásból létrehozni — csak megjegyzésként őrződtek meg, nem élő ismétlődő sorozatként.',
        'saved_report_unsupported' => 'A mentett jelentéseknek és az elemzési beállításoknak nincs megfelelőjük a Beatraxban.',
        'assumed_currency' => "Feltételezett érték: :currency — ebben az exportban nem található 'preferences.currencyCode' sor.",
        'assumed_budget_type' => "Feltételezett érték: :mode — ebben az exportban nem található 'preferences.budgetType' sor.",
        'changed_on_both_sides' => "A legutóbbi import óta a forrásfájl és a Beatrax is megváltoztatta ezt.\nHelyi: :local\nForrás: :source\nLegutóbb importálva: :baseline",
        'take_source' => 'Az új export értéke a megerősítésedkor lép életbe — a helyi értéked lecserélődik.',
        'keep_local' => 'A helyi értéked megmarad — az új export értéke nem lép életbe.',
        'compared_values' => ":intro\nHelyi: :local · Forrás: :source · Legutóbb importálva: :baseline",
    ],

    'value' => [
        'none' => '(nincs)',
        'quoted' => '„:value”',
    ],
];
