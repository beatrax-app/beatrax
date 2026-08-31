<?php

declare(strict_types=1);

return [
    'label' => [
        'goal' => 'Cíl: :name',
        'category_goal' => 'Cíl kategorie :name',
        'schedule_untitled' => 'Naplánovaná transakce bez názvu',
        'transaction' => 'Transakce: :name · :date · :amount',
        'transaction_unnamed' => 'Transakce',
        'amount_update' => 'Aktualizace částky transakce',
        'budget_history' => 'Historie rozpočtu v :currency',
        'budget_file_currency' => 'Měna souboru rozpočtu',
        'budget_file_mode' => 'Režim souboru rozpočtu',
    ],

    'conflict' => [
        'budget_assignment' => 'Přidělení rozpočtu',
        'budget_for_month' => 'Rozpočet: :category · :month',
        'budget_for_category' => 'Rozpočet: :category',
        'category_name' => 'Název kategorie',
        'category_name_of' => 'Název kategorie „:name“',
        'account_name' => 'Název účtu',
        'account_name_of' => 'Název účtu „:name“',
        'transaction_amount' => 'Částka transakce',
        'transaction_amount_of' => 'Částka: :name',
        'transaction_amount_of_dated' => 'Částka: :name · :date',
        'transaction_description' => 'Popis transakce',
        'transaction_description_of' => 'Popis: :name',
        'transaction_description_of_dated' => 'Popis: :name · :date',
        'other' => 'Importovaná hodnota',
    ],

    'reason' => [
        'fingerprint_collision' => 'Tato transakce se střetla s jinou už zaznamenanou transakcí (shodný otisk) a nebyla naimportována.',

        // i18n-review: cs · reason.split_legs_without_category — the waiting
        // bucket reads "v kategorii Bez kategorie", repeating this locale's own
        // name for Uncategorized. A bare "čeká v :uncategorized" is
        // ungrammatical, so the repetition is what correctness costs here.
        'split_legs_without_category' => ':count položka rozdělení z celkových :legs nemá kategorii a položku bez kategorie nelze uložit. Transakce byla naimportována v plné částce a čeká v kategorii :uncategorized.|:count položky rozdělení z celkových :legs nemají kategorii a položku bez kategorie nelze uložit. Transakce byla naimportována v plné částce a čeká v kategorii :uncategorized.|:count položek rozdělení z celkových :legs nemá kategorii a položku bez kategorie nelze uložit. Transakce byla naimportována v plné částce a čeká v kategorii :uncategorized.',
        'split_sum_mismatch' => 'Položky rozdělení dávají dohromady :legs, ale transakce je :total, a rozdělení musí své transakci odpovídat přesně. Transakce byla naimportována v plné částce, bez svých položek.',
        'split_unstorable' => 'Beatrax nedokáže toto rozdělení v této podobě uložit, takže transakce byla naimportována samostatně, bez svých položek.',
        'goal_without_target_date' => 'Tento cíl nemá cílové datum; Beatrax ho pro vytvoření spořicího cíle vyžaduje.',
        'goal_without_name' => 'Tento cíl nemá název; Beatrax ho pro vytvoření spořicího cíle vyžaduje.',
        'goal_def_unsupported' => 'categories.goal_def používá nepodporovaný (nikoli plochý) tvar šablony — cíl nebyl naimportován.',
        'budget_currency_mismatch' => ':count řádek rozpočtu nebyl naimportován: tvé rozpočty se vedou v :envelope a tento export rozpočtuje v :source.|:count řádky rozpočtu nebyly naimportovány: tvé rozpočty se vedou v :envelope a tento export rozpočtuje v :source.|:count řádků rozpočtu nebylo naimportováno: tvé rozpočty se vedou v :envelope a tento export rozpočtuje v :source.',
        'amount_apply_collision' => 'Novou částku ze zdroje nešlo použít — střetává se s otiskem jiné transakce (stejný účet, datum, měna a protistrana). Ponecháno beze změny.',
        'schedule_unsupported' => 'Naplánované a opakované transakce zatím nemají v Beatraxu cestu pro vytvoření z externího zdroje — jsou zachovány jen jako poznámka, ne jako živá opakovaná řada.',
        'saved_report_unsupported' => 'Uložené sestavy a konfigurace analýz nemají v Beatraxu obdobu.',
        'assumed_currency' => "Předpokládáme :currency — v tomto exportu se nenašel řádek 'preferences.currencyCode'.",
        'assumed_budget_type' => "Předpokládáme :mode — v tomto exportu se nenašel řádek 'preferences.budgetType'.",
        'changed_on_both_sides' => "Zdrojový soubor i Beatrax to od posledního importu změnily.\nMístní: :local\nZdroj: :source\nNaposledy naimportováno: :baseline",
        'take_source' => 'Hodnota z nového exportu se použije, jakmile potvrdíš — tvá místní hodnota bude nahrazena.',
        'keep_local' => 'Tvá místní hodnota zůstane — hodnota z nového exportu se nepoužije.',
        'compared_values' => ":intro\nMístní: :local · Zdroj: :source · Naposledy naimportováno: :baseline",
    ],

    'value' => [
        'none' => '(žádná)',
        'quoted' => '„:value“',
    ],
];
