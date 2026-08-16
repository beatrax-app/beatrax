<?php

declare(strict_types=1);

return [
    'heading_edit' => 'Modifica regola',
    'heading_new' => 'Nuova regola',

    'combinator_aria' => 'Combinazione delle condizioni',
    'match_all' => 'Soddisfa tutte le condizioni',
    'match_any' => 'Soddisfa almeno una condizione',

    'condition_label' => 'Condizione :number',
    'condition_field_aria' => 'Campo della condizione :number',
    'condition_operator_aria' => 'Operatore della condizione :number',
    'condition_value_aria' => 'Valore della condizione :number',
    'condition_value_from_aria' => 'Valore della condizione :number (da)',
    'condition_value_to_aria' => 'Valore della condizione :number (a)',
    'to' => 'a',
    'amount_placeholder' => '0,00',
    'text_placeholder' => 'es. SPOTIFY',
    'remove_condition' => 'Rimuovi condizione',
    'add_condition' => '+ Aggiungi condizione',

    'then' => 'Allora',
    'action_label' => 'Azione :number',
    'action_type_aria' => "Tipo dell'azione :number",
    'action_category' => 'Categoria',
    'action_counterparty' => 'Controparte',
    'action_note' => 'Nota',
    'action_tax_tag' => 'Etichetta fiscale',
    'assign_category_aria' => "Assegna una categoria per l'azione :number",
    'reassign_counterparty_aria' => "Riassegna alla controparte per l'azione :number",
    'note_text_aria' => "Testo della nota per l'azione :number",
    'note_placeholder' => 'Testo della nota…',
    'note_mode_aria' => "Modalità della nota per l'azione :number",
    'note_set' => 'Imposta',
    'note_append' => 'Aggiungi in coda',
    'deduction_category_aria' => "Categoria di detrazione per l'azione :number",
    'remove_action' => 'Rimuovi azione',
    'add_action' => '+ Aggiungi azione',

    'this_year_only' => "Solo quest'anno ▾",
    'override_tax_year' => "Sostituisci l'anno fiscale",
    'tax_year_override_aria' => "Anno fiscale sostituito per l'azione :number",
    'tax_tag_note' => "Le azioni sull'etichetta fiscale si applicano alla prossima riapplicazione, non all'importazione in corso.",

    'priority' => 'Priorità',
    'priority_help' => 'I numeri più bassi vengono eseguiti per primi. Le regole senza campi in comune non vanno mai in conflitto.',

    'cancel' => 'Annulla',
    'save_changes' => 'Salva le modifiche',
    'save_rule' => 'Salva la regola',
    'saving' => 'Salvataggio…',

    'error_rule_unavailable' => 'Quella regola non è più disponibile.',
    'error_invalid_data' => 'Dati della regola non validi — scegli dai menu a discesa e riprova.',
    'error_duplicate' => 'Esiste già una regola con questo campo, questo confronto e questo valore. Modifica invece la regola esistente.',
    'error_priority_whole' => 'La priorità deve essere un numero intero.',
    'error_add_condition' => 'Aggiungi almeno una condizione.',
    'error_add_action' => "Aggiungi almeno un'azione.",
    'condition_value_required' => 'Inserisci un valore per la condizione :position.',
    'condition_bounds_required' => 'Scegli un limite inferiore e uno superiore per la condizione :position.',
    'condition_amount_invalid' => 'Inserisci un importo valido per la condizione :position.',
    'action_pick_category' => 'Scegli una categoria per questa azione.',
    'action_pick_counterparty' => 'Scegli la controparte a cui riassegnare.',
    'action_note_required' => 'Inserisci il testo della nota.',
    'action_pick_deduction' => "Scegli una categoria di detrazione per l'etichetta fiscale.",
];
