<?php

declare(strict_types=1);

return [
    'page_title' => 'Salvadanai · Beatrax',
    'heading' => 'Salvadanai',
    'subtitle' => 'Sotto-saldi virtuali che sommati danno sempre il saldo reale del tuo conto.',
    'add_pot' => 'Aggiungi salvadanaio',

    'pot_fallback' => 'salvadanaio',

    'empty' => [
        'heading' => 'Nessun salvadanaio',
        'body' => 'Crea sotto-saldi virtuali in qualsiasi conto per organizzare il tuo denaro senza un bonifico reale.',
        'cta' => 'Aggiungi il tuo primo salvadanaio',
        'no_accounts_cta' => 'Importa un estratto conto',
    ],

    'common' => [
        'cancel' => 'Annulla',
        'amount' => 'Importo',
        'note_optional' => 'Nota (facoltativa)',
    ],

    'actions' => [
        'fund' => 'Alimenta',
        'move' => 'Sposta',
        'edit' => 'Modifica',
        'withdraw' => 'Preleva',
        'archive' => 'Archivia',
        'restore' => 'Ripristina',
    ],

    'recon' => [
        'over_allocated' => 'I salvadanai superano il saldo reale di :amount — riequilibra per correggere',
        'real_balance' => 'Saldo reale:',
        'allocated' => 'Assegnato:',
        'unallocated' => 'Non assegnato:',
    ],

    'chip' => [
        'goal' => 'Obiettivo:',
        'goal_name_fallback' => 'Obiettivo',
        'category_fallback' => 'Categoria',
    ],

    'coverage' => [
        'spent' => 'spesi',
        'in_pot' => 'nel salvadanaio',
    ],

    'archive_confirm' => 'Archiviare questo salvadanaio? Il saldo di :amount tornerà non assegnato.',
    'confirm_archive_aria' => 'Conferma archiviazione di :name',
    'more_actions_aria' => 'Altre azioni per :name',

    'history' => [
        'show' => 'Mostra cronologia ↓',
        'hide' => 'Nascondi cronologia ↑',
    ],

    'movement' => [
        'fund' => 'Versamento',
        'withdraw' => 'Prelievo',
        'moved_from' => 'Spostato da :name',
        'moved_to' => 'Spostato in :name',
    ],

    'archived' => [
        'toggle' => 'Salvadanai archiviati (:count)',
        'badge' => 'Archiviato',
    ],

    'form' => [
        'create_title' => 'Crea un salvadanaio',
        'edit_title' => 'Modifica salvadanaio',
        'create_subtitle' => 'Dai un nome a un sotto-saldo virtuale in un conto.',
        'edit_subtitle' => 'Aggiorna il nome o il collegamento di questo salvadanaio.',
        'name' => 'Nome',
        'name_placeholder' => 'es. Fondo vacanze',
        'account' => 'Conto',
        'select_account' => 'Seleziona un conto',
        'initial_amount' => 'Importo iniziale (facoltativo)',
        'initial_amount_help' => "L'importo viene dedotto dal non assegnato. Lascia vuoto per crearlo vuoto.",
        'link_to' => 'Collega a (facoltativo)',
        'link_goal' => 'Obiettivo',
        'link_none' => 'Nessuno',
        'select_goal' => 'Seleziona un obiettivo',
        'save_pot' => 'Salva salvadanaio',
        'save_changes' => 'Salva modifiche',
    ],

    'fund' => [
        'title' => 'Alimenta il salvadanaio',
        'heading' => 'Alimenta :name',
        'submit' => 'Alimenta il salvadanaio',
        'note_placeholder' => 'es. Risparmio mensile',
        'available' => 'Disponibile da assegnare: :amount (non assegnato)',
    ],

    'move' => [
        'title' => 'Sposta i fondi',
        'heading' => 'Sposta da :name',
        'to' => 'Sposta in',
        'select_pot' => 'Seleziona un salvadanaio',
        'no_others_short' => 'Nessun altro salvadanaio',
        'no_others' => 'Nessun altro salvadanaio in questo conto',
        'submit' => 'Sposta i fondi',
        'note_placeholder' => 'es. Trasferimento per le vacanze',
    ],

    'withdraw' => [
        'heading' => 'Preleva da :name',
        'note_placeholder' => 'es. Prelievo',
    ],

    'available_in' => 'Disponibile in :name: :amount',

    'errors' => [
        'enter_name' => 'Inserisci un nome per questo salvadanaio.',
        'select_account' => 'Seleziona un conto per questo salvadanaio.',
        'amount_exceeds_unallocated' => "L'importo supera il saldo non assegnato.",
        'amount_exceeds_unallocated_available' => "L'importo supera il saldo non assegnato (:amount disponibile).",
        'amount_exceeds_pot_balance' => "L'importo supera il saldo di :name (:amount disponibile).",
    ],

    'toast' => [
        'pot_created' => 'Salvadanaio creato.',
        'pot_updated' => 'Salvadanaio aggiornato.',
        'pot_funded' => 'Salvadanaio alimentato.',
        'withdrawn' => 'Prelevato dal salvadanaio.',
        'funds_moved' => 'Fondi spostati.',
        'pot_archived' => 'Salvadanaio archiviato.',
        'pot_restored' => 'Salvadanaio ripristinato.',
    ],
];
