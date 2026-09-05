<?php

declare(strict_types=1);

return [
    'label' => [
        'goal' => 'Cieľ: :name',
        'category_goal' => 'Cieľ pre :name',
        'schedule_untitled' => 'Nepomenovaná plánovaná transakcia',
        'transaction' => 'Transakcia: :name · :date · :amount',
        'transaction_unnamed' => 'Transakcia',
        'amount_update' => 'Aktualizácia sumy transakcie',
        'budget_history' => 'História rozpočtu v :currency',
        'budget_file_currency' => 'Mena súboru s rozpočtom',
        'budget_file_mode' => 'Režim súboru s rozpočtom',
    ],

    'conflict' => [
        'budget_assignment' => 'Pridelenie rozpočtu',
        'budget_for_month' => 'Rozpočet: :category · :month',
        'budget_for_category' => 'Rozpočet: :category',
        'category_name' => 'Názov kategórie',
        'category_name_of' => 'Názov kategórie „:name“',
        'account_name' => 'Názov účtu',
        'account_name_of' => 'Názov účtu „:name“',
        'transaction_amount' => 'Suma transakcie',
        'transaction_amount_of' => 'Suma: :name',
        'transaction_amount_of_dated' => 'Suma: :name · :date',
        'transaction_description' => 'Popis transakcie',
        'transaction_description_of' => 'Popis: :name',
        'transaction_description_of_dated' => 'Popis: :name · :date',
        'other' => 'Importovaná hodnota',
    ],

    'reason' => [
        'fingerprint_collision' => 'Táto transakcia sa zhodovala s inou už zaznamenanou transakciou (rovnaký odtlačok) a nebola naimportovaná.',
        'split_legs_without_category' => ':count časť rozdelenia z :legs nemá kategóriu a časť rozdelenia sa bez nej nedá uložiť. Transakcia sa naimportovala v plnej sume a čaká v kategórii „:uncategorized“.|:count časti rozdelenia z :legs nemajú kategóriu a časť rozdelenia sa bez nej nedá uložiť. Transakcia sa naimportovala v plnej sume a čaká v kategórii „:uncategorized“.|:count častí rozdelenia z :legs nemá kategóriu a časť rozdelenia sa bez nej nedá uložiť. Transakcia sa naimportovala v plnej sume a čaká v kategórii „:uncategorized“.',
        'split_sum_mismatch' => 'Časti rozdelenia dávajú spolu :legs, ale transakcia je :total, pričom rozdelenie musí presne sedieť so svojou transakciou. Transakcia sa naimportovala v plnej sume, bez svojich častí.',
        'split_unstorable' => 'Beatrax nedokáže uložiť toto rozdelenie v tejto podobe, takže transakcia sa naimportovala samostatne, bez svojich častí.',
        'goal_without_target_date' => 'Tento cieľ nemá cieľový dátum; Beatrax ho vyžaduje na vytvorenie sporiaceho cieľa.',
        'goal_without_name' => 'Tento cieľ nemá názov; Beatrax ho vyžaduje na vytvorenie sporiaceho cieľa.',
        'goal_def_unsupported' => 'categories.goal_def používa nepodporovaný (neplochý) tvar šablóny — cieľ sa nenaimportoval.',
        'budget_currency_mismatch' => ':count riadok rozpočtu sa nenaimportoval: tvoje rozpočty sa vedú v :envelope a tento export vedie rozpočet v :source.|:count riadky rozpočtu sa nenaimportovali: tvoje rozpočty sa vedú v :envelope a tento export vedie rozpočet v :source.|:count riadkov rozpočtu sa nenaimportovalo: tvoje rozpočty sa vedú v :envelope a tento export vedie rozpočet v :source.',
        'amount_apply_collision' => 'Novú sumu zo zdroja sa nepodarilo použiť — koliduje s odtlačkom inej transakcie (rovnaký účet, dátum, mena a protistrana). Zostala nezmenená.',
        'amount_currency_mismatch' => 'Sumy transakcií sa nezosúladili: tieto transakcie sa vedú v :local a tento export ich uvádza v :source. Ponechané bez zmeny.',
        'schedule_unsupported' => 'Beatrax zatiaľ nevie vytvárať plánované a opakované transakcie z externého zdroja — zachované len ako poznámka, nie ako aktívna séria v sekcii Opakované.',
        'saved_report_unsupported' => 'Uložené zostavy a konfigurácie analýz nemajú v Beatraxe ekvivalent.',
        'assumed_currency' => "Predpokladá sa :currency — v tomto exporte sa nenašiel žiadny riadok 'preferences.currencyCode'.",
        'assumed_budget_type' => "Predpokladá sa :mode — v tomto exporte sa nenašiel žiadny riadok 'preferences.budgetType'.",
        'changed_on_both_sides' => "Od posledného importu to zmenil zdrojový súbor aj Beatrax.\nLokálne: :local\nZdroj: :source\nNaposledy importované: :baseline",
        'take_source' => 'Hodnota z nového exportu sa použije, keď to potvrdíš — tvoja lokálna hodnota sa nahradí.',
        'keep_local' => 'Tvoja lokálna hodnota sa zachová — hodnota z nového exportu sa nepoužije.',
        'compared_values' => ":intro\nLokálne: :local · Zdroj: :source · Naposledy importované: :baseline",
    ],

    'value' => [
        'none' => '(žiadna)',
        'quoted' => '„:value“',
    ],
];
