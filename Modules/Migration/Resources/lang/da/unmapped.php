<?php

declare(strict_types=1);

return [
    'label' => [
        'goal' => 'Mål: :name',
        'category_goal' => 'Mål for kategorien :name',
        'schedule_untitled' => 'Planlagt transaktion uden navn',
        'transaction' => 'Transaktion: :name · :date · :amount',
        'transaction_unnamed' => 'Transaktion',
        'amount_update' => 'Opdatering af transaktionsbeløb',
        'budget_history' => 'Budgethistorik i :currency',
        'budget_file_currency' => 'Budgetfilens valuta',
        'budget_file_mode' => 'Budgetfilens tilstand',
    ],

    'conflict' => [
        'budget_assignment' => 'Budgetfordeling',
        'budget_for_month' => 'Budget for :category · :month',
        'budget_for_category' => 'Budget for :category',
        'category_name' => 'Kategorinavn',
        'category_name_of' => 'Kategorinavnet ”:name”',
        'account_name' => 'Kontonavn',
        'account_name_of' => 'Kontonavnet ”:name”',
        'transaction_amount' => 'Transaktionsbeløb',
        'transaction_amount_of' => 'Beløb: :name',
        'transaction_amount_of_dated' => 'Beløb: :name · :date',
        'transaction_description' => 'Transaktionsbeskrivelse',
        'transaction_description_of' => 'Beskrivelse: :name',
        'transaction_description_of_dated' => 'Beskrivelse: :name · :date',
        'other' => 'Importeret værdi',
    ],

    'reason' => [
        'fingerprint_collision' => 'Denne transaktion stødte sammen med en anden allerede registreret transaktion (identisk fingeraftryk) og blev ikke importeret.',
        'split_legs_without_category' => ':count delpost ud af :legs har ingen kategori, og en delpost kan ikke gemmes uden. Transaktionen blev importeret med hele sit beløb og venter i kategorien :uncategorized.|:count delposter ud af :legs har ingen kategori, og en delpost kan ikke gemmes uden. Transaktionen blev importeret med hele sit beløb og venter i kategorien :uncategorized.',
        'split_sum_mismatch' => 'Delposterne summerer til :legs, men transaktionen er :total, og en opdeling skal passe præcist med sin transaktion. Transaktionen blev importeret med hele sit beløb, uden sine delposter.',
        'split_unstorable' => 'Beatrax kan ikke gemme denne opdeling, som den ser ud, så transaktionen blev importeret alene, uden sine delposter.',
        'goal_without_target_date' => 'Dette mål har ingen måldato; Beatrax kræver en for at oprette et opsparingsmål.',
        'goal_without_name' => 'Dette mål har intet navn; Beatrax kræver et for at oprette et opsparingsmål.',
        'goal_def_unsupported' => 'categories.goal_def bruger en ikke-understøttet (ikke-flad) skabelonform — målet blev ikke importeret.',
        'budget_currency_mismatch' => ':count budgetrække blev ikke importeret: dine budgetter føres i :envelope, og denne eksport budgetterer i :source.|:count budgetrækker blev ikke importeret: dine budgetter føres i :envelope, og denne eksport budgetterer i :source.',
        'amount_apply_collision' => 'Kildens nye beløb kunne ikke anvendes — det støder sammen med en anden transaktions fingeraftryk (samme konto, dato, valuta og modpart). Efterladt uændret.',
        'amount_currency_mismatch' => 'Transaktionsbeløbene blev ikke afstemt: disse transaktioner føres i :local, og denne eksport angiver dem i :source. Efterladt uændret.',
        'schedule_unsupported' => 'Planlagte og tilbagevendende transaktioner har endnu ingen vej i Beatrax til at blive oprettet fra en ekstern kilde — de er kun bevaret som en note, ikke som en aktiv tilbagevendende serie.',
        'saved_report_unsupported' => 'Gemte rapporter og analyseopsætninger har ingen modsvarighed i Beatrax.',
        'assumed_currency' => "Antog :currency — der blev ikke fundet nogen 'preferences.currencyCode'-række i denne eksport.",
        'assumed_budget_type' => "Antog :mode — der blev ikke fundet nogen 'preferences.budgetType'-række i denne eksport.",
        'changed_on_both_sides' => "Både kildefilen og Beatrax har ændret dette siden sidste import.\nLokal: :local\nKilde: :source\nSidst importeret: :baseline",
        'take_source' => 'Den nye eksports værdi bliver anvendt, når du bekræfter — din lokale værdi bliver erstattet.',
        'keep_local' => 'Din lokale værdi bliver bevaret — den nye eksports værdi bliver ikke anvendt.',
        'compared_values' => ":intro\nLokal: :local · Kilde: :source · Sidst importeret: :baseline",
    ],

    'value' => [
        'none' => '(ingen)',
        'quoted' => '”:value”',
    ],
];
