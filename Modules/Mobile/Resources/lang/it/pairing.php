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
    'word_code_aria' => "Inserisci il codice a parole dell'altro dispositivo",
    'submit_code' => 'Invia il codice',
    'cancel' => 'Annulla',

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
        'invalid_code' => "Questo codice non è valido o è scaduto. Chiedi all'altro dispositivo di generarne uno nuovo.",
        'identity_locked' => "L'identità del tuo dispositivo è bloccata. Sblocca l'app e riprova.",
    ],
];
