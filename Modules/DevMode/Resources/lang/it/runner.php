<?php

declare(strict_types=1);

return [
    'heading' => 'Runner Artisan',
    'subtitle' => 'Esegui i comandi SAFE con un clic; i comandi DESTRUCTIVE passano dal triple-gate.',
    'run_a_command' => 'Esegui un comando',
    'filter_aria' => 'Filtro delle esecuzioni',
    'filter' => [
        'all' => 'Tutte',
        'running' => 'In esecuzione',
        'failed' => 'Fallite',
        'destructive' => 'Distruttive',
    ],
    'worker_running' => 'Worker della coda: IN ESECUZIONE',
    'worker_not_running' => 'Worker della coda: NON IN ESECUZIONE',
    'no_runs' => 'Ancora nessuna esecuzione. Fai clic su "Esegui un comando" oppure usa la palette dei comandi (⌘K).',
    // i18n-review: it · no_runs_touch — the same line for a touch
    // screen; check the verb governs this case.
    'no_runs_touch' => 'Ancora nessuna esecuzione. Tocca su "Esegui un comando" oppure usa la palette dei comandi (⌘K).',
    'recent_runs_aria' => 'Esecuzioni recenti',
    'modal_heading' => 'Esegui un comando SAFE',
    'modal_intro' => "Scegli un comando di livello SAFE da eseguire subito. I comandi DESTRUCTIVE non sono elencati qui — usa l'opzione Riesegui nella timeline oppure la palette ⌘K.",
    'args_badge' => 'args',
    'args_badge_title' => 'Apre un modulo per gli argomenti',

    'spawning_unavailable' => 'I comandi Artisan girano in un processo separato, e questa piattaforma non permette all\'app di avviarne uno. Eseguili dall\'app per computer.',

    'status' => [
        'running' => 'In esecuzione',
        'done' => 'Completata',
        'failed' => 'Fallita',
        'cancelled' => 'Annullata',
    ],
    'cancel' => 'Annulla',
    'rerun' => 'Riesegui',
    'started' => 'Avviata :when',
    'exit' => 'exit',

    'toast' => [
        'unknown_command' => 'Comando sconosciuto: :command',
        'missing_args' => 'Impossibile eseguire :command — richiede :noun: :list',
        'invalid_args' => 'Impossibile eseguire :command — :reason',
        'arg' => 'argomento|argomenti',
        'started' => 'Avviato :command (esecuzione :runId)',
        'run_expired' => 'Record di esecuzione scaduto — impossibile rieseguire.',
        'reran' => 'Rieseguito :command (esecuzione :runId)',
        'rerun_forbidden' => 'Questa esecuzione appartiene a un altro sviluppatore.',
    ],

    'command' => [
        'db_backup' => ['label' => 'Esegui il backup del database', 'description' => 'Scrive una copia SQLite con marca temporale nella cartella dei backup (o nel percorso indicato).'],
        'doctor' => ['label' => 'Esegui doctor', 'description' => 'Riporta le versioni installate di PHP / Composer / SQLite e verifica i minimi richiesti.'],
        'failed_jobs' => ['label' => 'Elimina i job falliti', 'description' => 'Elimina le voci già risolte dalla tabella failed_jobs gestita da Laravel.'],
        'cache_clear' => ['label' => 'Svuota la cache', 'description' => "Svuota lo store di cache dell'applicazione."],
        'route_list' => ['label' => 'Elenca le rotte', 'description' => 'Stampa su stdout ogni rotta HTTP registrata.'],
        'config_show' => ['label' => 'Mostra la configurazione', 'description' => 'Stampa il valore della chiave di configurazione indicata.'],
        'view_clear' => ['label' => 'Svuota la cache delle viste', 'description' => 'Svuota la cache delle viste Blade compilate.'],
        'queue_retry' => ['label' => 'Riprova i job falliti', 'description' => 'Riprova un job (per id) oppure tutti i job falliti (id vuoto).'],
        'rederive_fingerprints' => ['label' => 'Ricalcola le impronte', 'description' => "Ricalcola l'impronta di ogni transazione con l'attuale versione di normalizzazione."],
        'db_restore' => ['label' => 'Ripristina il database', 'description' => 'Sostituisce il database attuale con il file di backup indicato.'],
        'migrate_fresh' => ['label' => 'Elimina le tabelle e rimigra', 'description' => 'Elimina tutte le tabelle, poi riesegue tutte le migrazioni.'],
        'reset_password' => ['label' => 'Reimposta la password', 'description' => "Reimposta la password di un utente in modo interattivo (rifiuta l'uso non interattivo)."],
        'regenerate_recovery_codes' => ['label' => 'Rigenera i codici di recupero', 'description' => 'Rigenera i 10 codici di recupero monouso di un utente.'],
        'grant_dev' => ['label' => "Concedi l'accesso sviluppatore", 'description' => "Imposta is_developer=true per l'utente indicato."],
        'install' => ['label' => "Esegui l'installazione", 'description' => "Configurazione iniziale idempotente. Rieseguirla su un'installazione già configurata è distruttivo."],
    ],

    'arg' => [
        'destination' => ['label' => 'File di destinazione', 'help' => 'Lascia vuoto per usare la cartella dei backup predefinita.', 'placeholder' => '/percorso/di/backup.sqlite (facoltativo)'],
        'action' => ['label' => 'Azione'],
        'config' => ['label' => 'Chiave di configurazione', 'help' => 'Il file di configurazione o la chiave puntata da stampare, ad esempio `app` o `database.connections.sqlite`.', 'placeholder' => 'app.name'],
        'id' => ['label' => 'Id del job', 'help' => 'Lascia vuoto per riprovare tutti i job falliti; indica un id per riprovarne uno solo.', 'placeholder' => 'tutti (o un id specifico)'],
        'queue' => ['label' => 'Nome della coda', 'help' => 'Filtro sulla coda facoltativo; per impostazione predefinita tutte le code.', 'placeholder' => 'default'],
        'from' => ['label' => 'Percorso del file di backup', 'help' => 'Sostituisce il database attuale con il file che si trova nel percorso indicato.', 'placeholder' => '/percorso/di/backup.sqlite'],
        'username' => ['label' => 'Nome utente', 'placeholder' => 'alice'],
    ],
];
