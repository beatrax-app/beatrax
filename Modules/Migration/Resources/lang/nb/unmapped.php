<?php

declare(strict_types=1);

return [
    'label' => [
        'goal' => 'Mål: :name',
        'category_goal' => 'Mål for :name',
        'schedule_untitled' => 'Planlagt transaksjon uten navn',
        'transaction' => 'Transaksjon: :name · :date · :amount',
        'transaction_unnamed' => 'Transaksjon',
        'amount_update' => 'Oppdatering av transaksjonsbeløp',
        'budget_history' => 'Budsjetthistorikk i :currency',
        'budget_file_currency' => 'Valuta i budsjettfilen',
        'budget_file_mode' => 'Modus i budsjettfilen',
    ],

    'conflict' => [
        'budget_assignment' => 'Budsjettfordeling',
        'budget_for_month' => 'Budsjett for :category · :month',
        'budget_for_category' => 'Budsjett for :category',
        'category_name' => 'Kategorinavn',
        'category_name_of' => 'Kategorinavn for «:name»',
        'account_name' => 'Kontonavn',
        'account_name_of' => 'Kontonavn for «:name»',
        'transaction_amount' => 'Transaksjonsbeløp',
        'transaction_amount_of' => 'Beløp for :name',
        'transaction_amount_of_dated' => 'Beløp for :name · :date',
        'transaction_description' => 'Transaksjonsbeskrivelse',
        'transaction_description_of' => 'Beskrivelse for :name',
        'transaction_description_of_dated' => 'Beskrivelse for :name · :date',
        'other' => 'Importert verdi',
    ],

    'reason' => [
        'fingerprint_collision' => 'Denne transaksjonen kolliderte med en allerede registrert transaksjon (identisk fingeravtrykk) og ble ikke importert.',
        'split_legs_without_category' => ':count delpost av :legs mangler kategori, og en delpost kan ikke lagres uten. Transaksjonen ble importert med hele beløpet og ligger i kategorien :uncategorized.|:count delposter av :legs mangler kategori, og en delpost kan ikke lagres uten. Transaksjonen ble importert med hele beløpet og ligger i kategorien :uncategorized.',
        'split_sum_mismatch' => 'Delpostene summerer seg til :legs, mens transaksjonen er :total, og en oppdeling må stemme nøyaktig med transaksjonen sin. Transaksjonen ble importert med hele beløpet, uten delpostene.',
        'split_unstorable' => 'Beatrax kan ikke lagre denne oppdelingen slik den står, så transaksjonen ble importert alene, uten delpostene.',
        'goal_without_target_date' => 'Dette målet har ingen måldato; Beatrax krever en for å opprette et sparemål.',
        'goal_without_name' => 'Dette målet har ikke noe navn; Beatrax krever et for å opprette et sparemål.',
        'goal_def_unsupported' => 'categories.goal_def bruker en malform som ikke støttes (ikke flat) — målet ble ikke importert.',
        'budget_currency_mismatch' => ':count budsjettrad ble ikke importert: budsjettene dine føres i :envelope, og denne eksporten fører budsjettet i :source.|:count budsjettrader ble ikke importert: budsjettene dine føres i :envelope, og denne eksporten fører budsjettet i :source.',
        'amount_apply_collision' => 'Det nye beløpet fra kilden kunne ikke tas i bruk — det kolliderer med fingeravtrykket til en annen transaksjon (samme konto, dato, valuta og motpart). Ble stående uendret.',
        'amount_currency_mismatch' => 'Transaksjonsbeløpene ble ikke avstemt: disse transaksjonene føres i :local, og denne eksporten oppgir dem i :source. Latt stå uendret.',
        'schedule_unsupported' => 'Planlagte og gjentakende transaksjoner kan ennå ikke opprettes i Beatrax fra en ekstern kilde — bevart bare som et notat, ikke som en aktiv serie under Gjentakende.',
        'saved_report_unsupported' => 'Lagrede rapporter og analyseoppsett har ingen tilsvarende funksjon i Beatrax.',
        'assumed_currency' => "Antok :currency — det ble ikke funnet noen 'preferences.currencyCode'-rad i denne eksporten.",
        'assumed_budget_type' => "Antok :mode — det ble ikke funnet noen 'preferences.budgetType'-rad i denne eksporten.",
        'changed_on_both_sides' => "Både kildefilen og Beatrax har endret dette siden forrige import.\nLokalt: :local\nKilde: :source\nSist importert: :baseline",
        'take_source' => 'Verdien fra den nye eksporten tas i bruk når du bekrefter — din lokale verdi blir erstattet.',
        'keep_local' => 'Din lokale verdi beholdes — verdien fra den nye eksporten tas ikke i bruk.',
        'compared_values' => ":intro\nLokalt: :local · Kilde: :source · Sist importert: :baseline",
    ],

    'value' => [
        'none' => '(ingen)',
        'quoted' => '«:value»',
    ],
];
