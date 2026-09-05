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
        'db_backup' => ['label' => 'Esegui il backup del database', 'description' => "Scrive una copia SQLite con marca temporale nella cartella dei backup, a meno che il database non sia cambiato dall'ultimo. Una copia che viene conservata elimina anche i backup più vecchi secondo la politica di conservazione."],
        'doctor' => ['label' => 'Esegui doctor', 'description' => 'Esegue la suite di probe operative e riporta pass / warn / fail per ogni riga. Una riga warn o fail produce un codice di uscita diverso da zero.'],
        'failed_jobs' => ['label' => 'Elimina i job falliti', 'description' => 'Elimina dalla tabella failed_jobs gestita da Laravel ogni riga più vecchia di 30 giorni, che il job sia stato riprovato o no.'],
        'cache_clear' => ['label' => 'Svuota la cache', 'description' => "Svuota lo store di cache dell'applicazione."],
        'route_list' => ['label' => 'Elenca le rotte', 'description' => 'Stampa su stdout ogni rotta HTTP registrata.'],
        'config_show' => ['label' => 'Mostra la configurazione', 'description' => 'Stampa un intero file di configurazione oppure il valore di una chiave puntata al suo interno.'],
        'view_clear' => ['label' => 'Svuota la cache delle viste', 'description' => 'Svuota la cache delle viste Blade compilate.'],
        'queue_retry' => ['label' => 'Riprova i job falliti', 'description' => 'Riprova un job fallito per id, oppure tutti i job falliti se passi `all`.'],
        'rederive_fingerprints' => ['label' => 'Ricalcola le impronte', 'description' => "Ricalcola l'impronta di ogni transazione ancora al di sotto dell'attuale versione di normalizzazione. Eseguito da qui, riporta il numero e non scrive nulla."],
        'db_restore' => ['label' => 'Ripristina il database', 'description' => 'Sostituisce il database attuale con il file di backup indicato.'],
        'regenerate_recovery_codes' => ['label' => 'Rigenera i codici di recupero', 'description' => 'Rigenera i 10 codici di recupero monouso di un utente.'],
        'grant_dev' => ['label' => "Concedi l'accesso sviluppatore", 'description' => "Imposta is_developer=true per l'utente indicato."],
        'install' => ['label' => "Esegui l'installazione", 'description' => "Configurazione iniziale idempotente: lo schema del database, i dati di riferimento e l'unico account utente. Rieseguirla su un'installazione già configurata riconferma l'account esistente e lascia la password invariata."],
    ],

    'arg' => [
        'action' => ['label' => 'Azione'],
        'config' => ['label' => 'Chiave di configurazione', 'help' => 'Il file di configurazione o la chiave puntata da stampare, ad esempio `app` o `database.connections.sqlite`.', 'placeholder' => 'app.name'],
        'id' => ['label' => 'Id del job', 'help' => 'Scrivi `all` per riprovare tutti i job falliti, oppure un id per riprovarne uno solo. Lasciato vuoto, non riprova nulla.', 'placeholder' => 'all (o un id specifico)'],
        'queue' => ['label' => 'Nome della coda', 'help' => 'Filtro sulla coda facoltativo; per impostazione predefinita tutte le code.', 'placeholder' => 'default'],
        'path' => ['label' => 'Percorso del file di backup', 'help' => 'Sostituisce il database attuale con il file che si trova nel percorso indicato.', 'placeholder' => '/percorso/di/backup.sqlite'],
        'username' => ['label' => 'Nome utente', 'placeholder' => 'alice'],
    ],
];
