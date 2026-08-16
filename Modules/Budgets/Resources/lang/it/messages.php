<?php

declare(strict_types=1);

return [
    'page' => [
        'title' => 'Budget',
        'subtitle' => 'Assegna ogni euro — :period.',
    ],

    'nav' => [
        'prev_aria' => 'Periodo precedente',
        'next_aria' => 'Periodo successivo',
    ],

    'ready' => [
        'label' => 'Pronto da assegnare',
        'overassigned' => 'Hai assegnato più di quanto hai — riduci una busta o aspetta altre entrate.',
    ],

    'empty' => [
        'nothing_assigned_heading' => 'Non hai ancora assegnato nulla',
        'copy_hint' => 'Copia il piano del mese scorso oppure fai clic su una cella qui sotto per iniziare ad assegnare.',
        'first_hint' => 'Fai clic su una cella qui sotto per iniziare ad assegnare il tuo primo mese.',
        'copy_button' => 'Copia il mese scorso',
    ],

    'no_categories' => [
        'heading' => 'Nessuna categoria di spesa',
        'body' => 'Aggiungi una categoria di spesa per iniziare ad assegnarle del denaro.',
    ],

    'table' => [
        'category' => 'Categoria',
        'assigned' => 'Assegnato',
        'spent' => 'Speso',
        'available' => 'Disponibile',
        'if_overspent' => 'In caso di sforamento',
        'notify_at' => 'Soglia di avviso',
        'actions' => 'Azioni',
    ],

    'badge' => [
        'carries_negative' => 'Riporta il negativo',
        'non_eur_aria' => 'Le spese non in EUR di questa categoria non sono mostrate qui — consulta la dashboard',
        'non_eur_title' => 'Spese non in EUR non mostrate qui — consulta la dashboard',
        'over_budget' => ':count oltre il budget',
    ],

    'row' => [
        'assigned_aria' => 'Assegnato per :category',
        'overspend_aria' => 'Se :category sfora il budget',
        'notify_aria' => 'Avvisami alla percentuale di utilizzo per :category',
        'move_money' => 'Sposta denaro',
        'move' => 'Sposta',
    ],

    'overspend' => [
        'reduce' => 'Riduci il pronto da assegnare del mese prossimo',
        'carry' => 'Riporta il negativo in questa busta',
    ],

    'history' => [
        'show' => 'Mostra cronologia ↓',
        'hide' => 'Nascondi cronologia ↑',
        'moved_from' => 'Spostato da :category',
        'moved_to' => 'Spostato in :category',
        'undo' => 'Annulla',
    ],

    'phone' => [
        'spent' => 'Speso :amount',
        'available' => 'Disponibile :amount',
        'notify_at' => 'Soglia di avviso',
    ],

    'modal' => [
        'move_from' => 'Sposta da :name',
        'move_from_fallback' => 'busta',
        'move_to' => 'Sposta in',
        'no_other' => "Nessun'altra busta",
        'select' => 'Scegli una busta',
        'amount' => 'Importo',
        'available_in' => 'Disponibile in :name: :amount',
        'note' => 'Nota (facoltativa)',
        'note_placeholder' => 'es. Copertura dello sforamento dei ristoranti',
        'cancel' => 'Annulla',
        'move_funds' => 'Sposta i fondi',
    ],

    'glance' => [
        'see_all' => 'Vedi tutto →',
    ],

    'notices' => [
        'invalid_amount' => 'Inserisci un importo valido.',
        'threshold_range' => 'Inserisci un numero intero tra 1 e 200.',
        'copied_last_month' => 'Piano del mese scorso copiato.',
        'choose_envelope' => 'Scegli una busta in cui spostare il denaro.',
        'amount_positive' => 'Inserisci un importo maggiore di zero.',
        'move_failed' => 'Impossibile completare lo spostamento — riprova.',
        'money_moved' => 'Denaro spostato.',
        'move_undone' => 'Spostamento annullato.',
    ],

    'errors' => [
        'assigned_negative' => "L'importo assegnato non può essere negativo.",
        'invalid_overspend_mode' => 'Modalità di sforamento non valida.',
        'threshold_range' => 'La soglia di avviso deve essere compresa tra 1 e 200.',
        'same_envelope' => 'La busta di origine e quella di destinazione devono essere diverse.',
        'non_positive_amount' => 'Importo non valido o non positivo.',
        'category_not_found' => "Categoria non trovata o non accessibile dall'utente.",
    ],
];
