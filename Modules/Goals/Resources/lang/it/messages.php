<?php

declare(strict_types=1);

return [
    'page' => [
        'title' => 'Obiettivi',
        'subtitle' => 'Segui i progressi verso i tuoi obiettivi di risparmio.',
        'add_goal' => 'Aggiungi obiettivo',
    ],

    'empty' => [
        'heading' => 'Nessun obiettivo',
        'body' => 'Imposta un importo e una data obiettivo per iniziare a seguire i tuoi risparmi.',
        'add_first' => 'Aggiungi il tuo primo obiettivo',
    ],

    'status' => [
        'overdue' => 'In ritardo',
        'reached' => 'Raggiunto',
        'completed' => 'Completato',
        'archived' => 'Archiviato',
    ],

    'row' => [
        'edit' => 'Modifica',
    ],

    'progress' => [
        'aria' => ':name: :pct% completato',
    ],

    'card' => [
        'target_date' => 'Data obiettivo: :date',
    ],

    'projection' => [
        'target_reached' => 'Obiettivo raggiunto',
        'closed_short' => 'Chiuso prima dell’obiettivo',
        'add_contributions' => 'Aggiungi contributi per vedere una proiezione',
        'not_enough_history' => 'Storico ancora insufficiente per stimare una data',
        'no_recent_contributions' => 'Nessun versamento recente su cui basare una stima',
        'est' => 'Stima :date ·',
        'projection_note' => '(proiezione)',
        'projected' => 'Previsto: :date',
    ],

    'archive' => [
        'confirm_question' => 'Archiviare questo obiettivo?',
        'close' => 'Chiudi',
        'confirm_aria' => 'Conferma archiviazione di :name',
        'archive' => 'Archivia',
    ],

    'actions' => [
        'more_aria' => 'Altre azioni per :name',
        'mark_complete' => 'Segna come completato',
        'archive' => 'Archivia',
        'restore' => 'Ripristina',
    ],

    'archived_disclosure' => 'Obiettivi archiviati (:count)',

    'form' => [
        'title_edit' => 'Modifica obiettivo',
        'title_create' => 'Crea un obiettivo di risparmio',
        'subtitle_edit' => "Aggiorna il nome, l'importo obiettivo, la data o il salvadanaio collegato.",
        'subtitle_create' => 'Imposta un importo e una data obiettivo per seguire i tuoi risparmi.',
        'name' => 'Nome',
        'name_placeholder' => 'es. Fondo di emergenza',
        'target_amount' => 'Importo obiettivo (:currency)',
        'target_date' => 'Data obiettivo',
        'linked_pot' => 'Salvadanaio collegato (facoltativo)',
        'no_pot' => 'Nessun salvadanaio — usa il monitoraggio dei trasferimenti',
        'linked_pot_help' => "Quando è collegato, il saldo del salvadanaio determina i progressi dell'obiettivo.",
        'save_changes' => 'Salva modifiche',
        'save_goal' => 'Salva obiettivo',
        'close' => 'Chiudi',
    ],

    'summary' => [
        'see_all' => 'Vedi tutto →',
        'no_goals' => 'Nessun obiettivo.',
        'add_first' => 'Aggiungi il tuo primo obiettivo →',
    ],

    'notices' => [
        'goal_created' => 'Obiettivo creato.',
        'goal_updated' => 'Obiettivo aggiornato.',
        'goal_marked_complete' => 'Obiettivo segnato come completato.',
        'goal_archived' => 'Obiettivo archiviato.',
        'goal_restored' => 'Obiettivo ripristinato.',
    ],

    'errors' => [
        'name' => 'Inserisci un nome per il tuo obiettivo.',
        'date' => 'Scegli una data obiettivo.',
        'date_invalid' => 'Scegli una data reale.',
        'generic' => 'Impossibile salvare l\'obiettivo. Controlla i campi e riprova.',
        'amount' => 'Inserisci un importo valido maggiore di zero.',
        'pot_linked_category' => 'Questo salvadanaio è collegato a una categoria. Rimuovi prima quel collegamento nella pagina Salvadanai.',
    ],
];
