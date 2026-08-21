<?php

declare(strict_types=1);

return [
    'heading' => 'Dispositivi e sincronizzazione',

    'enable_sync' => 'Attiva la sincronizzazione',
    'enable_sync_help' => "Condividi i tuoi dati in modo sicuro tra i dispositivi attendibili. Richiede un blocco dell'app.",

    'app_lock_notice' => "Imposta prima un blocco dell'app per attivare la sincronizzazione.",
    'go_to_app_lock' => "Vai a Blocco dell'app",

    'encrypted_at_rest' => 'Dati crittografati a riposo',
    'encrypted_at_rest_scope' => 'Le note, le descrizioni delle transazioni e i nomi e gli IBAN di chi paghi sono cifrati con la passphrase del blocco app. Gli importi, le date e il nome e l\'IBAN del tuo conto no, e alcuni nomi di esercenti restano in chiaro altrove nel file di database.',
    'on' => 'Attivo',
    'securing' => 'Protezione dei tuoi dati…',
    'do_not_close' => 'Non chiudere questa finestra.',
    'encryption_progress_aria' => 'Avanzamento della crittografia',
    'not_encrypted_offer' => 'I tuoi dati non sono cifrati a riposo. La cifratura nasconde chi paghi se questo dispositivo viene perso o rubato — importi, date e l\'indice di ricerca restano leggibili.',
    'enable_encryption' => 'Attiva la crittografia',

    'your_devices' => 'I tuoi dispositivi',

    // Settings keeps a pointer to the moved surface; the section
    // itself now lives on /sync with the status and sync action.
    'moved_help' => 'Abbinamento, nomi dei dispositivi e crittografia ora si trovano insieme allo stato della sincronizzazione.',
    'moved_cta' => 'Apri Sincronizzazione e dispositivo',
    'device_name' => 'Nome del dispositivo',
    'save' => 'Salva',
    'peer_default_name' => 'Dispositivo abbinato',
    'rename_device' => 'Rinomina dispositivo',
    'this_device' => 'Questo dispositivo',
    'removed' => 'Rimosso',
    'confirmed' => 'Confermato',
    'awaiting_confirmation' => 'In attesa di conferma',
    'safety_number_words' => 'Parole del numero di sicurezza:',
    'paired' => 'Abbinato',
    'remove_aria' => 'Rimuovi :name',
    'remove' => 'Rimuovi',
    'pair_new_device' => 'Abbina un nuovo dispositivo',

    'relay_endpoint' => 'Endpoint del relay',
    'relay_endpoint_help' => 'Facoltativo. Se impostato, i dispositivi offline si sincronizzano tramite questo relay. Lascia vuoto per usare solo la LAN&#8209;diretta.',
    'relay_endpoint_aria' => 'URL endpoint del relay',
    'relay_insecure_warning' => 'Questo endpoint del relay usa HTTP in chiaro. Anche se il relay non decritta mai i tuoi dati, una connessione non sicura espone le dimensioni crittografate e i tempi a chi osserva la rete. Usa un endpoint <strong>https://</strong> per la massima privacy.',

    'enable_at_rest' => 'Attiva la crittografia a riposo',
    'enable_at_rest_body' => "I tuoi dati verranno crittografati con la passphrase di blocco dell'app. Verrà creato automaticamente un backup prima della migrazione.",
    'no_recovery_warning' => "Se perdi la passphrase di blocco dell'app e non hai un backup né un altro dispositivo attendibile, i tuoi dati non potranno essere recuperati.",
    'recover_help' => "Per riottenere l'accesso, riabbina questo dispositivo da un altro dispositivo attendibile oppure usa il tuo backup crittografato indipendente.",
    'amounts_plaintext' => 'Gli importi non sono crittografati a riposo — saldi e totali restano leggibili così i tuoi totali mensili continuano a tornare.',
    'search_plaintext' => "L'indice di ricerca conserva una copia in chiaro del testo di esercente e descrizione così la ricerca full-text continua a funzionare.",
    'keep_unencrypted' => 'Mantieni i dati non crittografati',
    'encryption_enabled' => 'Crittografia attivata',
    'encryption_enabled_body' => 'I tuoi dati ora sono crittografati a riposo.',
    'done_encryption_enabled' => 'Fatto — crittografia attivata',
    'encryption_failed' => 'Configurazione della crittografia non riuscita',
    'encryption_failed_body' => 'I tuoi dati non sono stati modificati. Il tuo backup è stato conservato.',
    'close_no_changes' => 'Chiudi — nessuna modifica',

    'remove_this_device' => 'Rimuovi questo dispositivo',
    'removing' => 'Rimozione:',
    'remove_rotates_key' => 'Rimuovendo questo dispositivo la chiave di crittografia viene ruotata, così non riceverà più alcun aggiornamento.',
    'remove_cannot_erase' => 'Non può cancellare i dati già presenti su quel dispositivo. Se è stato smarrito o rubato, considera esposti tutti i dati che conteneva.',
    'remove_device' => 'Rimuovi dispositivo',
    'keep_device' => 'Mantieni dispositivo',
    'rotating_key' => 'Rotazione della chiave di crittografia…',

    'flash' => [
        'app_lock_first' => "Imposta prima un blocco dell'app per attivare la sincronizzazione.",
        'enable_failed' => "Attivazione della sincronizzazione non riuscita. Assicurati che il blocco dell'app sia attivo e riprova.",
        'cannot_remove_self' => 'Non puoi rimuovere questo dispositivo — è quello che stai usando.',
        'remove_failed' => 'Rimozione del dispositivo non riuscita. Riprova.',
        'app_lock_first_settings' => "Imposta prima un blocco dell'app per modificare le impostazioni di sincronizzazione.",
        'relay_cleared' => 'Endpoint del relay cancellato.',
        'relay_saved' => 'Endpoint del relay salvato.',
        'relay_save_failed' => "Impossibile salvare l'endpoint del relay: :message",
    ],
];
