<?php

declare(strict_types=1);

return [
    'heading' => 'Dispozitive și sincronizare',

    'enable_sync' => 'Activează sincronizarea',
    'enable_sync_help' => 'Partajează-ți datele în siguranță între dispozitivele de încredere. Necesită o blocare a aplicației.',

    'app_lock_notice' => 'Setează mai întâi o blocare a aplicației pentru a activa sincronizarea.',
    'go_to_app_lock' => 'Mergi la blocarea aplicației',

    'encrypted_at_rest' => 'Date criptate în repaus',
    'encrypted_at_rest_scope' => 'Notele, descrierile tranzacțiilor și numele și IBAN-urile celor cărora le plătești sunt criptate cu parola de blocare a aplicației. Sumele, datele și numele și IBAN-ul propriului tău cont nu sunt, iar unele nume de comercianți rămân în text clar în alte locuri din fișierul bazei de date.',
    'on' => 'Activat',
    'securing' => 'Se securizează datele tale…',
    'do_not_close' => 'Nu închide această fereastră.',
    'encryption_progress_aria' => 'Progresul criptării',
    'not_encrypted_offer' => 'Datele tale nu sunt criptate în repaus. Configurează criptarea pentru a le proteja dacă acest dispozitiv este pierdut sau furat.',
    'enable_encryption' => 'Activează criptarea',

    'your_devices' => 'Dispozitivele tale',

    'moved_help' => 'Împerecherea, numele dispozitivelor și criptarea se află acum lângă starea sincronizării.',
    'moved_cta' => 'Deschide Sincronizare și dispozitiv',
    'device_name' => 'Numele dispozitivului',
    'save' => 'Salvează',
    'peer_default_name' => 'Dispozitiv împerecheat',
    'rename_device' => 'Redenumește dispozitivul',
    'this_device' => 'Acest dispozitiv',
    'removed' => 'Eliminat',
    'confirmed' => 'Confirmat',
    'awaiting_confirmation' => 'Se așteaptă confirmarea',
    'safety_number_words' => 'Cuvintele numărului de siguranță:',
    'paired' => 'Împerecheat',
    'remove_aria' => 'Elimină :name',
    'remove' => 'Elimină',
    'pair_new_device' => 'Împerechează un dispozitiv nou',

    'relay_endpoint' => 'Punctul final al releului',
    'relay_endpoint_help' => 'Opțional. Când este setat, dispozitivele offline se sincronizează prin acest releu. Lasă gol pentru doar LAN&#8209;direct.',
    'relay_endpoint_aria' => 'URL-ul punctului final al releului',
    'relay_insecure_warning' => 'Acest punct final al releului folosește HTTP simplu. Deși releul nu îți decriptează niciodată datele, o conexiune nesecurizată expune dimensiunile criptate și momentele transferurilor celor care observă rețeaua. Folosește un punct final <strong>https://</strong> pentru cea mai bună confidențialitate.',

    'enable_at_rest' => 'Activează criptarea în repaus',
    'enable_at_rest_body' => 'Datele tale vor fi criptate folosind fraza de acces a blocării aplicației. O copie de rezervă dinaintea migrării va fi creată automat.',
    'no_recovery_warning' => 'Dacă pierzi fraza de acces a blocării aplicației și nu ai nicio copie de rezervă sau alt dispozitiv de încredere, datele tale nu pot fi recuperate.',
    'recover_help' => 'Pentru a recăpăta accesul, împerechează din nou acest dispozitiv de pe alt dispozitiv de încredere sau folosește propria copie de rezervă criptată.',
    'amounts_plaintext' => 'Sumele nu sunt criptate în repaus — soldurile și totalurile rămân lizibile, astfel încât totalurile tale lunare să se adune în continuare corect.',
    'search_plaintext' => 'Indexul de căutare păstrează o copie în text simplu a textului comerciantului și al descrierii, ca să funcționeze în continuare căutarea în tot textul.',
    'keep_unencrypted' => 'Păstrează datele necriptate',
    'encryption_enabled' => 'Criptare activată',
    'encryption_enabled_body' => 'Datele tale sunt acum criptate în repaus.',
    'done_encryption_enabled' => 'Gata — criptare activată',
    'encryption_failed' => 'Configurarea criptării a eșuat',
    'encryption_failed_body' => 'Datele tale nu au fost modificate. Copia ta de rezervă a fost păstrată.',
    'close_no_changes' => 'Închide — nicio modificare făcută',

    'remove_this_device' => 'Elimină acest dispozitiv',
    'removing' => 'Se elimină:',
    'remove_rotates_key' => 'Eliminarea acestui dispozitiv rotește cheia de criptare, astfel încât el nu mai primește actualizări viitoare.',
    'remove_cannot_erase' => 'Nu poate șterge datele aflate deja pe acel dispozitiv. Dacă acest dispozitiv a fost pierdut sau furat, tratează toate datele pe care le-a avut ca fiind expuse.',
    'remove_device' => 'Elimină dispozitivul',
    'keep_device' => 'Păstrează dispozitivul',
    'rotating_key' => 'Se rotește cheia de criptare…',

    'flash' => [
        'app_lock_first' => 'Setează mai întâi o blocare a aplicației pentru a activa sincronizarea.',
        'enable_failed' => 'Activarea sincronizării a eșuat. Asigură-te că blocarea aplicației este activă și încearcă din nou.',
        'cannot_remove_self' => 'Nu poți elimina acest dispozitiv — este cel pe care îl folosești.',
        'remove_failed' => 'Eliminarea dispozitivului a eșuat. Încearcă din nou.',
        'app_lock_first_settings' => 'Setează mai întâi o blocare a aplicației pentru a schimba setările de sincronizare.',
        'relay_cleared' => 'Punctul final al releului a fost șters.',
        'relay_saved' => 'Punctul final al releului a fost salvat.',
        'relay_save_failed' => 'Salvarea punctului final al releului a eșuat: :message',
    ],
];
