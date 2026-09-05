<?php

declare(strict_types=1);

return [
    'label' => [
        'goal' => 'Mål: :name',
        'category_goal' => 'Mål för :name',
        'schedule_untitled' => 'Namnlös schemalagd transaktion',
        'transaction' => 'Transaktion: :name · :date · :amount',
        'transaction_unnamed' => 'Transaktion',
        'amount_update' => 'Uppdatering av transaktionsbelopp',
        'budget_history' => 'Budgethistorik i :currency',
        'budget_file_currency' => 'Budgetfilens valuta',
        'budget_file_mode' => 'Budgetfilens läge',
    ],

    'conflict' => [
        'budget_assignment' => 'Budgetfördelning',
        'budget_for_month' => 'Budget för :category · :month',
        'budget_for_category' => 'Budget för :category',
        'category_name' => 'Kategorinamn',
        'category_name_of' => 'Kategorinamn för ”:name”',
        'account_name' => 'Kontonamn',
        'account_name_of' => 'Kontonamn för ”:name”',
        'transaction_amount' => 'Transaktionsbelopp',
        'transaction_amount_of' => 'Belopp för :name',
        'transaction_amount_of_dated' => 'Belopp för :name · :date',
        'transaction_description' => 'Transaktionsbeskrivning',
        'transaction_description_of' => 'Beskrivning för :name',
        'transaction_description_of_dated' => 'Beskrivning för :name · :date',
        'other' => 'Importerat värde',
    ],

    'reason' => [
        'fingerprint_collision' => 'Den här transaktionen krockade med en redan bokförd transaktion (identiskt fingeravtryck) och importerades inte.',
        'reconciled_status_kept' => 'Källans avstämningsstatus kunde inte tillämpas — den här transaktionen är avstämd i Beatrax, och bara att häva avstämningen ändrar det. Lämnad oförändrad.',
        'split_legs_without_category' => ':count delpost av :legs saknar kategori, och en delpost går inte att spara utan. Transaktionen importerades med hela beloppet och ligger i kategorin :uncategorized.|:count delposter av :legs saknar kategori, och en delpost går inte att spara utan. Transaktionen importerades med hela beloppet och ligger i kategorin :uncategorized.',
        'split_sum_mismatch' => 'Delposterna summerar till :legs medan transaktionen är :total, och en uppdelning måste stämma exakt med sin transaktion. Transaktionen importerades med hela beloppet, utan sina delposter.',
        'split_unstorable' => 'Beatrax kan inte spara den här uppdelningen som den ser ut, så transaktionen importerades ensam, utan sina delposter.',
        'goal_without_target_date' => 'Det här målet saknar måldatum; Beatrax kräver ett för att skapa ett sparmål.',
        'goal_without_name' => 'Det här målet saknar namn; Beatrax kräver ett för att skapa ett sparmål.',
        'goal_def_unsupported' => 'categories.goal_def använder en mallform som inte stöds (inte platt) — målet importerades inte.',
        'budget_currency_mismatch' => ':count budgetrad importerades inte: dina budgetar förs i :envelope och den här exporten för budget i :source.|:count budgetrader importerades inte: dina budgetar förs i :envelope och den här exporten för budget i :source.',
        'amount_apply_collision' => 'Det nya beloppet från källan kunde inte tillämpas — det krockar med fingeravtrycket för en annan transaktion (samma konto, datum, valuta och motpart). Lämnades oförändrat.',
        'amount_currency_mismatch' => 'Transaktionsbeloppen stämdes inte av: dessa transaktioner förs i :local och den här exporten anger dem i :source. Lämnades oförändrade.',
        'schedule_unsupported' => 'Schemalagda och återkommande transaktioner går ännu inte att skapa i Beatrax från en extern källa — sparas bara som en anteckning, inte som en aktiv serie under Återkommande.',
        'saved_report_unsupported' => 'Sparade rapporter och analysinställningar har ingen motsvarighet i Beatrax.',
        'assumed_currency' => "Antog :currency — ingen rad 'preferences.currencyCode' hittades i den här exporten.",
        'assumed_budget_type' => "Antog :mode — ingen rad 'preferences.budgetType' hittades i den här exporten.",
        'changed_on_both_sides' => "Både källfilen och Beatrax har ändrat det här sedan den senaste importen.\nLokalt: :local\nKälla: :source\nSenast importerat: :baseline",
        'take_source' => 'Värdet från den nya exporten tillämpas när du bekräftar — ditt lokala värde ersätts.',
        'keep_local' => 'Ditt lokala värde behålls — värdet från den nya exporten tillämpas inte.',
        'compared_values' => ":intro\nLokalt: :local · Källa: :source · Senast importerat: :baseline",
    ],

    'value' => [
        'none' => '(inget)',
        'quoted' => '”:value”',
    ],
];
