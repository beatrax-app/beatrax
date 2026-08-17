<?php

declare(strict_types=1);

return [
    'heading' => 'Caselle',
    'intro' => 'Collega le caselle Gmail e Microsoft 365 così Beatrax può scansionarle alla ricerca di ricevute.',

    'connection_canceled' => 'Connessione annullata.',
    'connection_failed' => 'Impossibile completare la connessione.',

    'backfilling' => 'Recupero storico',
    'messages_suffix' => 'messaggi',

    'connect_heading' => 'Collega la tua email',
    'connect_body' => 'Importa le ricevute di PayPal, ICS Cards, Google Play e altri esercenti dando a Beatrax accesso in sola lettura a una o più delle tue caselle.',
    'connect_gmail' => 'Collega Gmail',
    'connect_microsoft' => 'Collega Microsoft 365',
    'readonly_note' => 'Beatrax legge soltanto i messaggi. Non invia, etichetta, sposta né elimina nulla nella tua casella.',

    'month' => '1 mese',
    'months' => ':count mesi',
    'not_scanned_yet' => 'non ancora scansionata',
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

    'retry_seconds' => 'nuovo tentativo tra :ns',
    'retry_minutes' => 'nuovo tentativo tra :nmin',
    'retry_hours' => 'nuovo tentativo tra :nh',

    'reconnect' => 'Ricollega',
    'disconnect' => 'Disconnetti',
    'scan_now' => 'Scansiona ora',
    'scan_in_progress_title' => 'Scansione già in corso',

    'add_another' => "Aggiungi un'altra casella",
    'gmail_card_body' => 'Collega un account Gmail così Beatrax può scansionarlo alla ricerca di ricevute.',
    'microsoft_card_body' => 'Collega un account Microsoft 365 o Outlook.com così Beatrax può scansionarlo alla ricerca di ricevute.',

    'discovered_heading' => 'Mittenti rilevati',
    'discovered_body' => 'Mittenti che sembrano inviare ricevute ma non sono ancora nel tuo elenco di ricevute note. Aggiungi quelli che vuoi far scansionare a Beatrax; ignora gli altri.',
    'last_seen' => 'ultima volta',
    'seen_times' => 'Visto :count volte',
    'add' => 'Aggiungi',
    'add_aria' => 'Aggiungi :email',
    'dismiss' => 'Ignora',
    'dismiss_aria' => 'Ignora :email',

    'toast' => [
        'scan_in_progress' => 'Scansione già in corso.',
        'scan_started' => 'Scansione avviata.',
        'sender_added' => 'Mittente aggiunto.',
        'sender_dismissed' => 'Mittente ignorato.',
    ],
];
