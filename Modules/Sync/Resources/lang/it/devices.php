<?php

declare(strict_types=1);

return [
    'heading' => 'Dispositivi e sincronizzazione',

    'enable_sync' => 'Attiva la sincronizzazione',
    'enable_sync_help' => 'Condividi i tuoi dati in modo sicuro tra i dispositivi attendibili. Richiede un blocco dell\'app. Una volta attivo, i tuoi dati sono cifrati e il blocco dell\'app non può più essere disattivato.',

    'app_lock_notice' => "Imposta prima un blocco dell'app per attivare la sincronizzazione.",
    'go_to_app_lock' => "Vai a Blocco dell'app",

    'identity_unreadable' => 'L\'identità di sincronizzazione di questo dispositivo è stata creata con un altro blocco app e non si apre più. Finché è così, questo dispositivo non può sincronizzare né associarsi. Ripristinare il backup del database con cui è stata creata la rende di nuovo leggibile.',
    'identity_unreadable_replace_help' => 'Puoi anche ricominciare: questo dispositivo riceve una nuova identità, quella vecchia resta da parte inutilizzata e i dispositivi già associati vanno associati di nuovo.',
    'identity_unreadable_replace' => 'Crea una nuova identità per questo dispositivo',

    'encrypted_at_rest' => 'Dati crittografati a riposo',
    'encrypted_at_rest_scope' => 'Le note, le descrizioni delle transazioni e i nomi e gli IBAN di chi paghi sono cifrati nel registro con la passphrase del blocco app. Gli importi, le date e il nome e l\'IBAN del tuo conto no. L\'indice di ricerca conserva una propria copia leggibile di chi paghi, delle descrizioni delle tue transazioni e delle tue note fiscali, e alcuni nomi di esercenti restano in chiaro altrove nel file di database.',
    'on' => 'Attivo',
    'securing' => 'Protezione dei tuoi dati…',
    'do_not_close' => 'Non chiudere questa finestra.',
    'encryption_progress_aria' => 'Avanzamento della crittografia',
    'not_encrypted_offer' => 'I tuoi dati non sono cifrati a riposo. La cifratura nasconde chi paghi se questo dispositivo viene perso o rubato — importi, date e l\'indice di ricerca restano leggibili.',
    'enable_encryption' => 'Attiva la crittografia',

    'your_devices' => 'I tuoi dispositivi',

    'device_name' => 'Nome del dispositivo',
    'save' => 'Salva',
    'peer_default_name' => 'Dispositivo abbinato',
    'rename_device' => 'Rinomina dispositivo',
    'rename_device_caption' => 'Rinomina',
    'this_device' => 'Questo dispositivo',
    'removed' => 'Rimosso',
    'confirmed' => 'Confermato',
    'awaiting_confirmation' => 'In attesa di conferma',
    'safety_number_words' => 'Parole del numero di sicurezza:',
    'paired' => 'Abbinato',
    'remove_aria' => 'Rimuovi :name',
    'remove' => 'Rimuovi',
    'pair_new_device' => 'Abbina un nuovo dispositivo',

    'pairing_waiting' => 'Completa l’abbinamento con :name',
    'pairing_waiting_help' => 'Entrambi gli schermi devono mostrare le stesse sei parole perché l’abbinamento valga. Riaprilo per confrontarle.',
    'pairing_waiting_resume' => 'Continua l’abbinamento',
    'pairing_waiting_lock_override' => 'Sbloccare riapre questo abbinamento invece di lasciarlo scadere, quindi dura più del timeout di blocco che hai impostato. Termina quando lo completi o lo annulli.',

    'relay_endpoint' => 'Endpoint del relay',
    'relay_endpoint_help' => 'Facoltativo. Se impostato, i dispositivi offline si sincronizzano tramite questo relay. Lascia vuoto per usare solo la LAN&#8209;diretta.',
    'relay_endpoint_help_phone' => 'Facoltativo. Se impostato, le modifiche viaggiano su questo relay anche quando i tuoi dispositivi non sono sulla stessa rete. Questo dispositivo le ritira quando sincronizzi da questa schermata — mai in background, perché il blocco app custodisce l\'unica chiave. Lascia vuoto per usare solo la LAN&#8209;diretta.',
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
    'encryption_enabled_scope' => 'Le note, le descrizioni e chi paghi ora sono cifrati con la passphrase del blocco app. Gli importi, le date e l\'indice di ricerca restano leggibili.',
    'done_encryption_enabled' => 'Fatto — crittografia attivata',
    'encryption_failed' => 'Configurazione della crittografia non riuscita',
    'encryption_failed_body' => 'I tuoi dati non sono stati modificati. Il tuo backup è stato conservato.',
    'close_no_changes' => 'Chiudi — nessuna modifica',

    'remove_this_device' => 'Rimuovi questo dispositivo',
    'removing' => 'Rimozione:',
    'remove_rotates_key' => 'Rimuovendo questo dispositivo la chiave di crittografia viene ruotata, così non riceverà più alcun aggiornamento.',
    'remove_cannot_erase' => 'Non può cancellare i dati già presenti su quel dispositivo. Se è stato smarrito o rubato, considera esposti tutti i dati che conteneva.',
    'remove_is_local' => 'Gli altri tuoi dispositivi hanno un elenco proprio. Finché non lo rimuovi anche lì, continueranno a sincronizzarsi con esso.',
    'remove_device' => 'Rimuovi dispositivo',
    'keep_device' => 'Mantieni dispositivo',
    'rotating_key' => 'Rotazione della chiave di crittografia…',

    'flash' => [
        'app_lock_first' => "Imposta prima un blocco dell'app per attivare la sincronizzazione.",
        'enable_failed' => "Attivazione della sincronizzazione non riuscita. Assicurati che il blocco dell'app sia attivo e riprova.",
        'identity_replaced' => 'Questo dispositivo ha una nuova identità di sincronizzazione. Associa di nuovo gli altri dispositivi.',
        'identity_replace_failed' => 'Non è stato possibile mettere da parte la vecchia identità del dispositivo. Riprova.',
        'cannot_remove_self' => 'Non puoi rimuovere questo dispositivo — è quello che stai usando.',
        'remove_failed' => 'Rimozione del dispositivo non riuscita. Riprova.',
        'app_lock_first_settings' => "Imposta prima un blocco dell'app per modificare le impostazioni di sincronizzazione.",
        'relay_cleared' => 'Endpoint del relay cancellato.',
        'relay_saved' => 'Endpoint del relay salvato.',
        'relay_save_failed' => "Impossibile salvare l'endpoint del relay: :message",
    ],
    'app_lock_permanent' => 'Una volta cifrati i dati, il blocco app non può più essere disattivato — custodisce l\'unica chiave, e non si torna in chiaro.',
    'backlog_heading' => 'In attesa di essere aggiunti',
    'backlog_deferred' => 'Questo dispositivo ha ricevuto dati da un altro dispositivo e non li ha ancora aggiunti alla tua contabilità. Non si perde nulla: vengono applicati automaticamente, di solito in un attimo.',
    'backlog_awaiting_key' => 'Questo dispositivo ha ricevuto dati di cui non ha ancora la chiave. Non si perde nulla. Apri l\'app sul dispositivo abbinato mentre questo è aperto, così i due possono collegarsi e la chiave può essere inviata.',
];
