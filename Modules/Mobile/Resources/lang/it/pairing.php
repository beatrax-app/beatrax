<?php

declare(strict_types=1);

return [
    'peer_default_name' => 'Dispositivo abbinato',
    'page_title' => 'Abbina un dispositivo',

    'scan_heading' => 'Abbina questo dispositivo',
    'scan_subtitle' => "Inquadra con la fotocamera il codice mostrato sull'altro dispositivo.",
    'camera_permission_pending' => "L'accesso alla fotocamera è disattivato. Consentilo per Beatrax nelle impostazioni del dispositivo, poi riprova.",
    'open_camera' => 'Apri la fotocamera',
    'opening_camera' => "In attesa dell'accesso alla fotocamera…",
    'close_camera' => 'Chiudi la fotocamera',
    'viewfinder_aria' => "Mirino della fotocamera — inquadra il codice sull'altro dispositivo",
    'viewfinder_idle' => "La fotocamera è spenta. Aprila per scansionare il codice mostrato sull'altro dispositivo.",
    'scan_prompt' => "Scansiona il codice sull'altro dispositivo",
    'enter_code_instead' => 'Inserisci il codice manualmente',

    'enter_heading' => 'Inserisci il codice',
    'camera_off' => "L'accesso alla fotocamera è disattivato. Inserisci invece il codice dell'altro dispositivo.",
    'camera_off_no_search' => 'L’accesso alla fotocamera è disattivato e cercare l’altro dispositivo sulla rete non funziona ancora su iPhone — un codice digitato non ha quindi nulla con cui trovarlo. Riattiva l’accesso alla fotocamera per Beatrax nelle impostazioni del dispositivo e scansiona il codice dell’altro dispositivo.',
    'no_search' => 'Cercare l’altro dispositivo sulla rete non funziona ancora su iPhone, quindi un codice digitato non ha nulla da trovare. Scansiona invece il codice con la fotocamera — la fotocamera non deve cercare sulla rete.',
    'word_code_aria' => "Inserisci il codice a parole dell'altro dispositivo",
    'submit_code' => 'Invia il codice',
    'cancel' => 'Annulla',
    'skip_import' => 'Continua senza importare',

    'confirm_heading' => "Confronta queste parole con l'altro dispositivo",
    'safety_words_aria' => 'Parole del numero di sicurezza: :words',
    'confirm_body' => 'Entrambi i dispositivi devono mostrare esattamente le stesse parole. Se differiscono, tocca Annulla — potrebbe essere in corso un attacco man-in-the-middle.',
    'awaiting_peer' => "In attesa della conferma dell'altro dispositivo...",
    'confirm_match' => 'Conferma — corrispondono',

    'success_heading' => 'Dispositivo abbinato',
    'success_body' => 'Questo dispositivo è ora attendibile. I tuoi dati si sincronizzeranno appena ti connetti.',
    'done' => 'Fatto',

    'errors' => [
        'relay_unreachable' => "Impossibile raggiungere l'altro dispositivo. Assicurati che siano entrambi sulla stessa rete e che la sincronizzazione sia attiva sul desktop.",
        'no_road_home' => "Questo dispositivo non può cercare sulla rete e il codice che hai scansionato non contiene alcun indirizzo per raggiungere l'altro dispositivo. Chiedigli di mostrare un nuovo codice e scansiona quello.",
        'invalid_code' => "Questo codice non è valido o è scaduto. Chiedi all'altro dispositivo di generarne uno nuovo.",
        'code_incomplete' => 'Questo codice non è completo. Confrontalo con l\'altro dispositivo e inseriscilo per intero.',
        'code_not_accepted' => 'Nessun dispositivo su questa rete ha accettato il codice. Controlla il codice e che l’altro dispositivo lo stia ancora mostrando.',
        'no_peer_answered' => 'Niente su questa rete ha risposto a quel codice. Controlla che la sincronizzazione sia attiva sull’altro dispositivo, oppure scansiona il suo codice con la fotocamera — la fotocamera non deve cercare sulla rete.',
        'no_peer_answered_ios' => 'Niente su questa rete ha risposto a quel codice. Cercare l’altro dispositivo sulla rete non funziona ancora su iPhone, quindi scansiona il suo codice con la fotocamera.',
        'no_peer_answered_camera_off' => 'Niente su questa rete ha risposto a quel codice. Cercare l’altro dispositivo sulla rete non funziona ancora su iPhone e l’accesso alla fotocamera è disattivato: riattiva l’accesso alla fotocamera per Beatrax nelle impostazioni del dispositivo e scansiona il codice dell’altro dispositivo.',
        'rate_limited' => 'Troppi tentativi. Aspetta un minuto e riprova.',
        'identity_locked' => "L'identità del tuo dispositivo è bloccata. Sblocca l'app e riprova.",
        'identity_needs_lock' => "Configura prima il blocco dell'app — protegge l'identità del tuo dispositivo.",
        'safety_number_changed' => 'L\'altro dispositivo è cambiato mentre confrontavi. Ricontrolla le parole qui sotto prima di confermare.',
    ],
];
