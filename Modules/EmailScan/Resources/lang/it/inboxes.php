<?php

declare(strict_types=1);

return [
    'heading' => 'Caselle',
    'intro' => 'Collega le caselle Gmail e Microsoft 365 così Beatrax può scansionarle alla ricerca di ricevute.',
    'intro_phone' => "La scansione delle caselle avviene nell'app desktop, non su questo telefono.",

    'phone_heading' => 'Questo telefono non scansiona le caselle',
    'phone_body' => "Collega Gmail o Microsoft 365 nell'app desktop: le ricevute che trova arrivano qui tramite sincronizzazione.",
    'connection_canceled' => 'Connessione annullata.',
    'connection_failed' => 'Impossibile completare la connessione.',

    'backfilling' => 'Recupero storico',
    'backfill_progress' => ':fetched / ~:count messaggio|:fetched / ~:count messaggi',

    'connect_heading' => 'Collega la tua email',
    'connect_body' => 'Importa le ricevute di PayPal, ICS Cards, Google Play e altri esercenti dando a Beatrax accesso in sola lettura a una o più delle tue caselle.',
    'connect_body_phone' => "Le ricevute di PayPal, ICS Cards, Google Play e altri esercenti le importa l'app desktop, dalle caselle a cui le dai accesso in sola lettura. Questo telefono mostra ciò che quell'importazione trova.",
    'connect_gmail' => 'Collega Gmail',
    'connect_microsoft' => 'Collega Microsoft 365',
    'readonly_note' => 'Beatrax legge soltanto i messaggi. Non invia, etichetta, sposta né elimina nulla nella tua casella.',

    'months' => ':count mese|:count mesi',
    'not_scanned_yet' => 'non ancora scansionata',
    'not_scanned_yet_phone' => 'non scansionata su questo telefono',
    'last_scanned' => 'ultima scansione',
    'window_prefix' => 'Finestra:',
    'edit' => 'Modifica',

    'badge' => [
        'idle' => 'Inattiva',
        'backfilling' => 'Recupero storico',
        'scanning' => 'Scansione',
        'rate_limited' => 'Limite di frequenza',
        'needs_reauth' => 'Riautenticazione necessaria',
        'error' => 'Errore',
    ],

    'error_detail' => "L'ultima scansione non è stata completata. Prova «Scansiona ora» o ricollega questa casella.",
    'oauth_state_mismatch' => 'Questo link di connessione è scaduto o è già stato usato. Ricomincia il collegamento.',
    'oauth_client_missing' => "La configurazione una tantum per quel provider di posta non è stata completata su questo dispositivo, quindi non c'è ancora nulla con cui collegarsi. Premi di nuovo Collega per completarla.",
    'oauth_no_code' => 'Il tuo provider di posta ti ha rimandato indietro senza il codice che serve a Beatrax per concludere, quindi non è stata collegata nessuna casella. Ricomincia il collegamento.',
    'oauth_grant_refused' => 'Il tuo provider di posta ha rifiutato il permesso concesso a Beatrax: è scaduto o è stato revocato. Ricomincia il collegamento e concedilo.',
    'oauth_exchange_failed' => 'Il tuo provider di posta non ha completato il collegamento, quindi non è stata aggiunta nessuna casella. Riprova tra qualche minuto.',
    'oauth_not_saved' => "Non è stato possibile salvare il collegamento su questo dispositivo, quindi non è stata aggiunta nessuna casella. Riprova — se continua a fallire, il log dell'app registra che cosa lo ha bloccato.",
    'oauth_no_offline_access_google' => "Google non ha concesso il permesso duraturo che serve a Beatrax, quindi questa casella smetterebbe di essere analizzata entro un'ora. Pubblica la tua schermata di consenso OAuth in produzione, poi ricollega.",
    'oauth_no_offline_access' => "Il tuo provider di posta non ha concesso il permesso duraturo che serve a Beatrax, quindi questa casella smetterebbe di essere analizzata entro un'ora. Ricollega e consenti l'accesso offline quando ti viene chiesto.",
    'oauth_no_offline_access_google_phone' => "Google non ha concesso il permesso duraturo che serve a Beatrax, quindi nessuna casella è stata collegata. Pubblica la tua schermata di consenso OAuth in produzione, poi ricollega: la scansione vera e propria avviene nell'app desktop.",
    'oauth_no_offline_access_phone' => "Il tuo provider di posta non ha concesso il permesso duraturo che serve a Beatrax, quindi nessuna casella è stata collegata. Ricollega e consenti l'accesso offline quando ti viene chiesto: la scansione vera e propria avviene nell'app desktop.",

    'retry_seconds' => 'nuovo tentativo tra :ns',
    'retry_minutes' => 'nuovo tentativo tra :nmin',
    'retry_hours' => 'nuovo tentativo tra :nh',

    'reconnect' => 'Ricollega',
    'disconnect' => 'Disconnetti',
    'disconnect_confirm' => 'Disconnettere :email? Questo rimuove le credenziali salvate di questa casella, la sua cronologia di scansione e i mittenti che hai aggiunto o ignorato. Le ricevute già archiviate in Beatrax non vengono modificate. Ricollegandola riparte una scansione da zero.',
    'scan_now' => 'Scansiona ora',
    'scan_in_progress_title' => 'Scansione già in corso',

    'add_another' => "Aggiungi un'altra casella",
    'gmail_card_body' => 'Collega un account Gmail così Beatrax può scansionarlo alla ricerca di ricevute.',
    'microsoft_card_body' => 'Collega un account Microsoft 365 o Outlook.com così Beatrax può scansionarlo alla ricerca di ricevute.',
    'gmail_card_body_phone' => "Gmail viene scansionato dall'app desktop. Collegalo lì — questo telefono mostra ciò che trova.",
    'microsoft_card_body_phone' => "Microsoft 365 e Outlook.com vengono scansionati dall'app desktop. Collegali lì — questo telefono mostra ciò che trova.",

    'discovered_heading' => 'Mittenti rilevati',

    'known_sender' => [
        'ics_statements' => 'ICS Cards (estratti conto)',
    ],
    'discovered_body' => 'Mittenti che sembrano inviare ricevute ma non sono ancora nel tuo elenco di ricevute note. Aggiungi quelli che vuoi far scansionare a Beatrax; ignora gli altri.',
    'last_seen' => 'ultima volta',
    'seen_times' => 'Visto :count volta|Visto :count volte',
    'add' => 'Aggiungi',
    'add_aria' => 'Aggiungi :email',
    'dismiss' => 'Ignora',
    'dismiss_aria' => 'Ignora :email',

    'toast' => [
        'reconnect_first' => 'Riconnetti questa casella prima della scansione.',
        'scan_in_progress' => 'Scansione già in corso.',
        'scan_started' => 'Scansione avviata.',
        'sender_added' => 'Mittente aggiunto.',
        'sender_dismissed' => 'Mittente ignorato.',
    ],
];
