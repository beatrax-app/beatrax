<?php

declare(strict_types=1);

return [
    'search_placeholder' => 'Scrivi per cercare viste, comandi e azioni. Premi Esc per chiudere.',
    'search_aria' => 'Scrivi per cercare viste, comandi e azioni',
    'dialog_aria' => 'Palette dei comandi',
    'token_suggest_aria' => 'Suggerimenti di token',
    'rail_view' => 'Vista',
    'rail_dev' => 'Dev',
    'rail_action' => 'Azione',
    'rail_recent' => 'Recenti',
    'no_recent' => 'Ancora nessuna scelta recente.',
    'section_transactions' => 'Transazioni',
    'section_counterparties' => 'Controparti',
    'section_categories' => 'Categorie',
    'section_goals_recurring' => 'Obiettivi e ricorrenti',
    'no_name' => '(senza nome)',
    'see_all' => 'Vedi :count risultato →|Vedi tutti i :count risultati →',
    'no_transactions' => 'Nessuna transazione corrisponde a ":query"',
    'source_txn' => 'txn',
    'source_counterparty' => 'controparte',
    'source_category' => 'categoria',
    'results_aria' => 'Risultati',
    'no_results' => 'Nessun risultato.',
    'foot_navigate' => 'naviga',
    'foot_select' => 'seleziona',
    'foot_close' => 'chiudi',
    'close_aria' => 'Chiudi la ricerca',
    'close_caption' => 'Chiudi',
    'foot_try' => 'Prova',
    'results' => ':count risultato|:count risultati',

    'action' => [
        'run_import' => ['label' => 'Avvia un\'importazione', 'hint' => 'Apri la procedura guidata di importazione'],
        'scan_email' => ['label' => 'Scansiona le email ora', 'hint' => 'Esegui subito la sincronizzazione della casella di posta'],
        'open_profile' => ['label' => 'Apri il profilo', 'hint' => 'Impostazioni — account e preferenze'],
        'toggle_theme' => ['label' => 'Cambia tema', 'hint' => 'Alterna tra il tema chiaro e quello scuro'],
    ],

    'run_command' => 'Esegui :command',

    'nav' => [
        'overview' => ['label' => 'Panoramica dev', 'hint' => 'Riquadri di sistema + esecuzioni recenti'],
        'artisan' => ['label' => 'Runner Artisan', 'hint' => 'Esegui i comandi autorizzati'],
        'audit' => ['label' => 'Log di audit dev', 'hint' => 'Ogni azione della modalità sviluppatore'],
        'logs' => ['label' => 'Tail dei log', 'hint' => 'Flusso live di laravel-*.log'],
        'queue' => ['label' => 'Ispettore della coda', 'hint' => 'In attesa / falliti / batch'],
        'doctor' => ['label' => 'Doctor', 'hint' => 'Probe di sistema'],
        'sql' => ['label' => 'Pannello SQL', 'hint' => 'Browser solo SELECT'],
        'system' => ['label' => 'Snapshot del sistema', 'hint' => 'Ambiente + percorsi + configurazione'],
        'horizon' => ['label' => 'Horizon', 'hint' => 'Dashboard delle code integrata'],
        'sync_health' => ['label' => 'Stato sync', 'hint' => 'Operazioni di merge in quarantena o saltate'],
    ],
];
