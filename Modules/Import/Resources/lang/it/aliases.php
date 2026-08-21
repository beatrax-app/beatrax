<?php

declare(strict_types=1);

return [
    'page_title' => 'Alias',
    'heading' => 'Alias',
    'subtitle' => 'I nomi leggibili che hai insegnato a Beatrax per le descrizioni criptiche dei tuoi estratti conto. Modifica lo schema generalizzato di una riga per allargare o restringere quali altre transazioni ereditano lo stesso nome leggibile.',
    'dismiss' => 'ignora',

    'selected_count' => ':count selezionati',
    'merge_selected' => 'Unisci i selezionati',

    'empty_heading' => 'Ancora nessun alias',
    'empty_body' => "Gli alias compaiono qui dopo che hai fatto clic sulla descrizione grezza in corsivo di una riga dell'anteprima di importazione e le hai dato un nome leggibile.",

    'col_select' => 'Seleziona',
    'col_raw' => 'Descrizione grezza',
    'col_generalized' => 'Schema generalizzato',
    'col_friendly' => 'Nome leggibile',
    'col_actions' => 'Azioni',

    'select_alias_aria' => "Seleziona l'alias :name",
    'generalized_pattern_aria' => 'Schema generalizzato',

    'save' => 'Salva',
    'cancel' => 'Annulla',
    'edit' => 'Modifica',
    'delete' => 'Elimina',
    'delete_confirm' => 'Eliminare questo alias? Le importazioni future di «:pattern» torneranno alla descrizione grezza.',

    'backup_transfer' => 'Backup e trasferimento',
    'export_yaml' => 'Esporta gli alias in YAML',

    'export_help_html' => 'Scarica <code class="font-mono">aliases.yaml</code> nel formato del corpus della community.',
    'import_from_yaml' => 'Importa da YAML',
    'parse_preview' => 'Analizza e visualizza',
    'cancel_import' => "Annulla l'importazione",

    'diff_new' => 'nuovi,',
    'diff_unchanged' => 'invariati,',
    'diff_conflicts' => 'conflitti.',

    'conflicts_heading' => 'Conflitti',
    'conflict_name' => 'nome — esistente: :existing → file: :file',
    'conflict_pattern_existing' => 'schema — esistente:',
    'conflict_file' => '→ file:',
    'resolution_for_aria' => 'Risoluzione per :pattern',
    'keep_yours' => 'Mantieni i tuoi',
    'replace' => 'Sostituisci',
    'confirm_import' => "Conferma l'importazione",

    'preview_aria' => 'Anteprima sulle transazioni',
    'test_heading' => 'Prova sulle mie transazioni',
    'test_help' => 'Modifica lo schema generalizzato di una riga per vedere quali transazioni troverebbe.',
    'typing' => 'Digitazione…',
    'matches_prefix' => 'Corrisponde a',
    'matches_suffix' => 'transazioni nella tua cronologia recente.',

    'merge_modal_title' => 'Unisci :count alias|Unisci :count alias',

    'merge_modal_help_html' => 'La riga rimanente mantiene la sua descrizione grezza; le righe assorbite vengono conservate in <code class="font-mono text-xs">merged_from</code>.',
    'friendly_name_label' => 'Nome leggibile',
    'generalized_pattern_label' => 'Schema generalizzato',
    'no_prefix_warning' => 'Non è stato trovato alcun prefisso comune di 4 caratteri tra gli alias selezionati — digita uno schema manualmente prima di confermare.',
    'confirm_merge' => "Conferma l'unione",

    'flash' => [
        'updated' => 'Alias aggiornato.',
        'deleted' => 'Alias eliminato.',
        'merged' => 'Alias uniti.',
        'imported' => 'Importato :count alias.|Importati :count alias.',
        'nothing' => "Non c'è nulla da importare.",
    ],

    'errors' => [
        'not_found' => "Alias non trovato (potrebbe essere stato eliminato in un'altra scheda).",
        'pattern_empty' => 'Lo schema generalizzato non può essere vuoto.',
        'select_two' => 'Seleziona almeno due alias da unire.',
        'some_not_found' => 'Uno o più alias selezionati non sono stati trovati.',
        'both_required' => 'Il nome leggibile e lo schema generalizzato sono entrambi obbligatori.',
        'merge_not_found' => "Uno o più alias non sono stati trovati (potrebbero essere stati eliminati in un'altra scheda).",
        'merge_failed' => "L'unione non è riuscita (:class).",
        'no_file' => 'Nessun file caricato.',
        'unreadable' => 'Impossibile leggere il file caricato.',
        'too_short' => 'Lo schema è troppo corto per essere testato.',
    ],
];
