<?php

declare(strict_types=1);

return [
    'page_title' => 'Importa da un altro dispositivo',

    'heading' => 'Importa da un altro dispositivo',
    'subtitle' => 'Configura questo telefono con un account e un blocco propri, poi abbinalo al tuo altro dispositivo per importare la cronologia.',

    'username' => 'Nome utente',
    'password' => 'Password',
    'password_help' => 'Almeno 12 caratteri — non esiste il ripristino della password, solo i codici di recupero.',
    'confirm_password' => 'Conferma password',

    'requirements_aria' => 'Requisiti della password',
    'req_length' => 'Almeno 12 caratteri',
    'req_match' => 'Le due password coincidono',
    'req_met' => '(soddisfatto)',
    'req_unmet' => '(non ancora soddisfatto)',

    'pin' => "PIN di blocco dell'app",
    'pin_help' => '6-10 cifre — sblocca questo dispositivo.',
    'confirm_pin' => 'Conferma PIN',
    'continue' => 'Continua',

    'failed_heading' => 'La configurazione non è stata completata',
    'failed_body' => 'Il tuo account è stato creato, ma non siamo riusciti a completare la configurazione di questo dispositivo. Puoi riprovare senza rischi.',
    'try_again' => 'Riprova',

    'recovery_heading' => 'Salva questi codici di recupero',
    'recovery_body' => 'Stampali o salvali in un posto sicuro. Non verranno mostrati di nuovo.',
    'already_heading' => 'Questo dispositivo è già configurato',
    'already_body' => "Il tuo account esiste già su questo dispositivo. Continua con l'abbinamento per collegarlo agli altri dispositivi.",
    'recovery_download' => 'Scarica come .txt',
    'recovery_copy' => 'Copia i codici',
    'recovery_copied' => 'Copiato',
    'recovery_copy_failed' => 'Copia non riuscita. Annotare i codici.',
    'recovery_saved' => 'Salvato nei tuoi download.',
    'recovery_share_title' => 'Codici di recupero Beatrax',
    'recovery_share_message' => 'Conservali in un luogo sicuro.',
    'recovery_save_failed' => 'Impossibile salvare il file. Annotare i codici.',
    'recovery_confirm' => 'Ho salvato questi codici in un posto sicuro.',
    'continue_to_pairing' => "Continua con l'abbinamento",

    'errors' => [
        'username_required' => 'Il nome utente è obbligatorio.',
        'passwords_mismatch' => 'Le password non coincidono.',
        'password_length' => 'Usa almeno 12 caratteri.',
        'pin_length' => 'Il PIN deve avere almeno 6 cifre.',
        'pins_mismatch' => 'I PIN non coincidono. Riprova.',
        'session_expired' => 'La sessione è scaduta prima che la configurazione finisse. Inserisci di nuovo il PIN e la password.',
        'retry_failed' => 'Non è stato ancora possibile completare la configurazione di questo dispositivo. Riprova.',
        'account_failed' => "Impossibile creare l'account.",
    ],
];
