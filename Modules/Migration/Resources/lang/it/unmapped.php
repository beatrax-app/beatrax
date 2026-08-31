<?php

declare(strict_types=1);

return [
    'label' => [
        'goal' => 'Obiettivo: :name',
        'category_goal' => 'Obiettivo della categoria :name',
        'schedule_untitled' => 'Transazione pianificata senza nome',
        'transaction' => 'Transazione: :name · :date · :amount',
        'transaction_unnamed' => 'Transazione',
        'amount_update' => "Aggiornamento dell'importo della transazione",
        'budget_history' => 'Storico del budget in :currency',
        'budget_file_currency' => 'Valuta del file di budget',
        'budget_file_mode' => 'Modalità del file di budget',
    ],

    'conflict' => [
        'budget_assignment' => 'Assegnazione di budget',
        'budget_for_month' => 'Budget di :category · :month',
        'budget_for_category' => 'Budget di :category',
        'category_name' => 'Nome della categoria',
        'category_name_of' => 'Nome della categoria «:name»',
        'account_name' => 'Nome del conto',
        'account_name_of' => 'Nome del conto «:name»',
        'transaction_amount' => 'Importo della transazione',
        'transaction_amount_of' => 'Importo: :name',
        'transaction_amount_of_dated' => 'Importo: :name · :date',
        'transaction_description' => 'Descrizione della transazione',
        'transaction_description_of' => 'Descrizione: :name',
        'transaction_description_of_dated' => 'Descrizione: :name · :date',
        'other' => 'Valore importato',
    ],

    'reason' => [
        'fingerprint_collision' => "Questa transazione è andata in collisione con un'altra transazione già registrata (impronta identica) e non è stata importata.",

        // i18n-review: it · reason.split_legs_without_category — the waiting
        // bucket reads «nella categoria Senza categoria», repeating this
        // locale's own name for Uncategorized. A bare «in :uncategorized» is
        // ungrammatical, so the repetition is what correctness costs here.
        'split_legs_without_category' => ':count quota della suddivisione su :legs non ha categoria, e una quota non può essere salvata senza. La transazione è stata importata per il suo importo completo ed è in attesa nella categoria :uncategorized.|:count quote della suddivisione su :legs non hanno categoria, e una quota non può essere salvata senza. La transazione è stata importata per il suo importo completo ed è in attesa nella categoria :uncategorized.',
        'split_sum_mismatch' => 'Le quote della suddivisione sommano :legs mentre la transazione è :total, e una suddivisione deve corrispondere esattamente alla sua transazione. La transazione è stata importata per il suo importo completo, senza le sue quote.',
        'split_unstorable' => "Beatrax non può salvare questa suddivisione così com'è, quindi la transazione è stata importata da sola, senza le sue quote.",
        'goal_without_target_date' => 'Questo obiettivo non ha una data obiettivo; Beatrax ne richiede una per creare un obiettivo di risparmio.',
        'goal_without_name' => 'Questo obiettivo non ha un nome; Beatrax ne richiede uno per creare un obiettivo di risparmio.',
        'goal_def_unsupported' => "categories.goal_def usa una forma di modello non supportata (non piatta) — l'obiettivo non è stato importato.",
        'budget_currency_mismatch' => ':count riga di budget non è stata importata: i tuoi budget sono tenuti in :envelope, mentre questa esportazione imposta i budget in :source.|:count righe di budget non sono state importate: i tuoi budget sono tenuti in :envelope, mentre questa esportazione imposta i budget in :source.',
        'amount_apply_collision' => "Il nuovo importo dell'origine non è stato applicato — collide con l'impronta di un'altra transazione (stesso conto, data, valuta e controparte). Lasciato invariato.",
        'schedule_unsupported' => 'Le transazioni pianificate e ricorrenti non hanno ancora in Beatrax un percorso di creazione da un\'origine esterna — sono conservate solo come nota, non come una serie ricorrente attiva.',
        'saved_report_unsupported' => 'I report salvati e le configurazioni di analisi non hanno un equivalente in Beatrax.',
        'assumed_currency' => "Si è assunto :currency — in questa esportazione non è stata trovata alcuna riga 'preferences.currencyCode'.",
        'assumed_budget_type' => "Si è assunto :mode — in questa esportazione non è stata trovata alcuna riga 'preferences.budgetType'.",
        'changed_on_both_sides' => "Sia il file di origine sia Beatrax hanno modificato questo dato dall'ultima importazione.\nLocale: :local\nOrigine: :source\nUltima importazione: :baseline",
        'take_source' => 'Il valore della nuova esportazione verrà applicato quando confermi — il tuo valore locale verrà sostituito.',
        'keep_local' => 'Il tuo valore locale verrà mantenuto — il valore della nuova esportazione non verrà applicato.',
        'compared_values' => ":intro\nLocale: :local · Origine: :source · Ultima importazione: :baseline",
    ],

    'value' => [
        'none' => '(nessuno)',
        'quoted' => '«:value»',
    ],
];
