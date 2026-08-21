<?php

declare(strict_types=1);

return [
    'editor_aria' => 'Editor dello scenario — :name',
    'rename_aria' => 'Rinomina lo scenario',
    'save' => 'Salva',
    'save_changes' => 'Salva le modifiche',
    'cancel' => 'Annulla',
    'rename' => 'Rinomina',
    'confirm_delete' => "Conferma l'eliminazione",
    'delete_scenario' => 'Elimina lo scenario',
    'delete_confirm' => 'Eliminare questo scenario?',

    'mutations_count' => 'Variazioni (:count)',
    'no_mutations' => 'Ancora nessuna variazione. Aggiungine una qui sotto per vedere come questo scenario si confronta con il tuo riferimento.',
    'editing' => 'Modifica in corso — :kind',
    'edit' => 'Modifica',
    'remove' => 'Rimuovi',

    'add_mutation' => '+ Aggiungi una variazione',
    'add_to_scenario' => 'Aggiungi allo scenario',
    'pick_kind' => 'Scegli un tipo di variazione:',

    'kind' => [
        'cancel_series' => [
            'title' => 'Annulla una serie',
            'desc' => 'Elimina tutte le occorrenze previste di una serie approvata.',
        ],
        'add_one_off' => [
            'title' => 'Aggiungi un addebito o un accredito una tantum',
            'desc' => 'Un singolo evento ipotetico in una data specifica.',
        ],
        'add_recurring' => [
            'title' => 'Aggiungi una serie ricorrente',
            'desc' => 'Un nuovo abbonamento o flusso di entrate ipotetico.',
        ],
        'change_series_amount' => [
            'title' => "Cambia l'importo di una serie",
            'desc' => 'Simula un aumento o una riduzione di prezzo su una serie esistente.',
        ],
        'shift_series_date' => [
            'title' => 'Sposta la data di una serie',
            'desc' => 'Sposta in avanti la prossima occorrenza o tutte quelle successive.',
        ],
    ],

    'form' => [
        'series_to_cancel' => 'Serie da annullare',
        'pick_series' => '— scegli una serie —',
        'date' => 'Data',
        'amount' => 'Importo',
        'currency' => 'Valuta',
        'direction' => 'Direzione',
        'expense_long' => 'Spesa (denaro in uscita)',
        'income_long' => 'Entrata (denaro in entrata)',
        'note' => 'Nota (facoltativa)',
        'start_date' => 'Data di inizio',
        'expense' => 'Spesa',
        'income' => 'Entrata',
        'cadence' => 'Frequenza',
        'cadence_weekly' => 'Settimanale',
        'cadence_monthly' => 'Mensile',
        'cadence_quarterly' => 'Trimestrale',
        'cadence_yearly' => 'Annuale',
        'series' => 'Serie',
        'new_amount' => 'Nuovo importo',
        'new_next_date' => 'Nuova data successiva',
        'scope' => 'Ambito',
        'scope_legend' => 'Quali occorrenze spostare',
        'scope_next' => 'Solo la prossima occorrenza',
        'scope_all' => 'Tutte le occorrenze successive',
    ],

    'whatif' => [
        'trigger' => 'Simula',
        'menu_aria' => 'Simula uno scenario per :name',
        'model_cancellation' => 'Simula una disdetta',
        'model_amount_change' => 'Simula un cambio di importo…',
        'amount_dialog_aria' => 'Simula un cambio di importo per :name',
        'current_amount' => 'Importo attuale',
        'new_amount' => 'Nuovo importo',
    ],

    'series_name_fallback' => 'serie',

    'summary' => [
        'cancel' => 'Annulla :name',
        'series_fallback' => 'serie n. :id',
        'one_off' => ':amount :currency il :date',
        'recurring' => ':amount :currency :cadence dal :date',
        'change_amount' => ':name: nuovo importo :amount',
        'shift' => ':name: sposta :scope al :date',
        'scope_all' => 'tutte le successive',
        'scope_next' => 'la prossima',
    ],

    'toast' => [
        'created' => 'Scenario «:name» creato.',
        'deleted' => 'Scenario eliminato.',
        'renamed' => 'Scenario rinominato.',
        'mutation_added' => 'Variazione aggiunta.',
        'mutation_updated' => 'Variazione aggiornata.',
        'mutation_removed' => 'Variazione rimossa. Annulla',
    ],

    'errors' => [
        'name_empty' => 'Il nome dello scenario non può essere vuoto.',
        'name_too_long' => 'Il nome dello scenario deve avere al massimo :max caratteri.',
        'name_taken' => 'Esiste già uno scenario con questo nome.',
        'pick_kind_first' => 'Scegli prima un tipo di variazione.',
        'amount_positive' => "L'importo deve essere un numero positivo.",
    ],
];
