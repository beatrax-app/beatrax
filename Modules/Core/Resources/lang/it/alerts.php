<?php

declare(strict_types=1);

return [
    'banner_aria' => 'Avvisi di sistema',

    'actions' => [
        'download_and_install' => 'Scarica e installa',
        'download_and_install_aria' => "Scarica e installa — segna l'avviso di sistema #:id come risolto",
        'skip_version' => 'Salta questa versione',
        'release_notes' => 'Note di rilascio →',
        'update_now' => 'Aggiorna ora',
        'update_now_aria' => "Aggiorna ora — segna l'avviso di sistema #:id come risolto",
        'remind_later' => 'Ricordamelo più tardi',
        'mark_resolved' => 'Segna come risolto',
        'mark_resolved_aria' => 'Segna come risolto — avviso di sistema #:id',
        'assign_in_budgets' => 'Assegna nei Budget',
        'dismiss' => 'Ignora',
        'dismiss_aria' => 'Ignora — avviso di sistema #:id',
    ],

    'deferred_pass' => [
        'budget-nudges' => 'gli avvisi di budget',
        'daily-triggers' => 'i promemoria giornalieri e il riepilogo',
    ],

    'messages' => [
        'update_available' => 'Aggiornamento disponibile — Beatrax :version. Non viene scaricato nulla finché non scegli di installarlo; Beatrax poi si chiude e si riapre sulla nuova versione.',
        'update_stale' => 'Stai usando la versione :current — la versione :latest è disponibile da 30 giorni. Aggiorna ora.',
        'update_critical' => 'Aggiornamento critico disponibile — la versione :version corregge :summary. Installalo il prima possibile.',
        'backup_corrupt_with_path' => 'Il backup scritto il :timestamp non ha superato il controllo di integrità. Controlla :path. Risolvi prima di fare affidamento sui backup.',
        'backup_corrupt_no_path' => 'Il backup tentato il :timestamp si è interrotto prima di produrre un file — il database di origine non ha superato il controllo di integrità. Risolvi prima di fare affidamento sui backup.',
        'backup_write_failed' => 'Il backup avviato alle :timestamp non è stato completato: il database ha superato i suoi controlli, i file del backup non sono stati scritti. Controlla lo spazio libero e i permessi della cartella dei backup.',
        'backup_restore_failed' => 'Il ripristino avviato alle :timestamp non è stato completato. I tuoi dati precedenti sono stati salvati prima in :snapshot.',

        'backup_overdue' => 'Il backup verificato più recente risale a :hoursh fa. Beatrax fa questo backup da solo, una volta al giorno, mentre l\'app è aperta — non c\'è nulla da eseguire a mano. Se resta così vecchio, l\'app non era aperta quando toccava l\'esecuzione giornaliera.',
        'backup_none_found' => 'Nella cartella dei backup non è stato trovato nessun backup verificato. Beatrax fa questo backup da solo, una volta al giorno, mentre l\'app è aperta — non c\'è nulla da eseguire a mano.',
        'wal_mode_missing' => 'SQLite non è in modalità WAL (attualmente :mode). Le scritture simultanee potrebbero bloccarsi. Esegui <code class="rounded bg-amber-100 px-1 text-amber-900 dark:bg-amber-900 dark:text-amber-200">php artisan beatrax:doctor</code> per assistenza.',
        'synchronous_misconfigured' => 'Il livello synchronous di SQLite è :level (previsto NORMAL/1). La semantica di durabilità potrebbe differire dalla configurazione. Esegui <code class="rounded bg-amber-100 px-1 text-amber-900 dark:bg-amber-900 dark:text-amber-200">php artisan beatrax:doctor</code> per assistenza.',
        'oauth_scrub_set_failed' => 'L’oscuramento dei segreti OAuth non è attivo. I log e gli estratti di audit potrebbero contenere token non oscurati fino al prossimo caricamento riuscito.',
        'oauth_reauth_required' => 'I segreti OAuth sono stati spostati nell’archivio per utente. Autorizza di nuovo Gmail e Microsoft per riprendere la scansione della posta. Il vecchio file dei segreti è stato rinominato in :file per consentire il ripristino.',
        'oauth_reconsent' => 'Ricollega il tuo :provider',
        'auth_recovery_code_consumed' => 'Codice di recupero usato da :username.',
        'auth_recovery_code_failed' => 'Tentativo di codice di recupero non riuscito per :username.',
        'auth_lock_hard_cap_reached' => 'Disconnessione dopo troppi tentativi di PIN non riusciti.',
        'open_banking_reconsent' => 'Ricollega la tua banca',
        'open_banking_nothing_imported' => 'La tua banca ha inviato transazioni, ma Beatrax non è riuscito a registrarne nessuna, quindi nel tuo registro non è arrivato nulla. Apri le impostazioni Open banking per vedere perché.',
        'auth_lock_corrupted_key' => 'Il tuo PIN non può aprire il blocco dell’app su questo dispositivo: la chiave salvata non è leggibile. Accedi con la password del tuo account per impostare un nuovo PIN.',
        'sync_gdk_rewrap_failed' => 'Il re-wrap del portachiavi GDK non è riuscito dopo la modifica della passphrase del blocco app — i dati cifrati potrebbero essere irrecuperabili finché il portachiavi non viene re-wrappato.',
        'worker_crashed' => 'L’elaborazione in background di Beatrax si è interrotta in modo imprevisto. Importazioni e scansioni delle email sono in pausa. Riapri l’app per riavviarla.',
        'auth_lock_key_material_stranded' => 'La cifratura a riposo è attiva per questo account, ma nessun wrap del blocco app conserva più la chiave dei dati, quindi ogni nota, descrizione e dettaglio di controparte cifrato risulta vuoto. Ripristina un backup cifrato creato quando la chiave funzionava ancora, oppure configura di nuovo questo account su un dispositivo che la possiede ancora.',
        'auth_lock_recovery_wrap_stale' => 'La password dell’account è cambiata senza che il wrap di recupero del blocco app venisse re-wrappato, quindi quella password non apre più il blocco. Il PIN sì. Ricollega la password dell’account dalle impostazioni del blocco finché il PIN è ancora noto, altrimenti un PIN dimenticato non lascia nulla dietro di sé.',
        'reconnect_link' => 'Ricollega →',
        'pots_category_link_retired' => 'Il budget a buste ha sostituito i salvadanai collegati a una categoria. :amount da :count salvadanaio archiviato è di nuovo non assegnato e aspetta che tu lo assegni.|Il budget a buste ha sostituito i salvadanai collegati a una categoria. :amount da :count salvadanai archiviati è di nuovo non assegnato e aspetta che tu lo assegni.',
        'notifications_deferred_pass_failed' => "Beatrax non è riuscito a calcolare :pass su questo dispositivo, quindi potrebbero mancarne alcuni. Riprova ogni volta che apri l'app.",
    ],
];
