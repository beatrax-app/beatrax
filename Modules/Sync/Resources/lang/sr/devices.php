<?php

declare(strict_types=1);

return [
    'heading' => 'Uređaji i sinhronizacija',

    'enable_sync' => 'Uključi sinhronizaciju',
    'enable_sync_help' => 'Bezbedno deli svoje podatke između pouzdanih uređaja. Zahteva zaključavanje aplikacije.',

    'app_lock_notice' => 'Prvo postavi zaključavanje aplikacije da uključiš sinhronizaciju.',
    'go_to_app_lock' => 'Idi na Zaključavanje aplikacije',

    'encrypted_at_rest' => 'Podaci šifrovani u mirovanju',
    'encrypted_at_rest_scope' => 'Beleške, opisi transakcija i imena i IBAN onih kojima plaćaš šifruju se lozinkom za zaključavanje aplikacije. Iznosi, datumi i naziv i IBAN tvog sopstvenog računa nisu šifrovani, a neka imena trgovaca i dalje stoje u čitljivom obliku na drugim mestima u datoteci baze podataka.',
    'on' => 'Uključeno',
    'securing' => 'Obezbeđivanje tvojih podataka…',
    'do_not_close' => 'Ne zatvaraj ovaj prozor.',
    'encryption_progress_aria' => 'Napredak šifrovanja',
    'not_encrypted_offer' => 'Ваши подаци нису шифровани у мировању. Шифровање скрива коме плаћате ако овај уређај изгубите или вам га украду — износи, датуми и индекс претраге остају читљиви.',
    'enable_encryption' => 'Uključi šifrovanje',

    'your_devices' => 'Tvoji uređaji',

    // Settings keeps a pointer to the moved surface; the section
    // itself now lives on /sync with the status and sync action.
    'moved_help' => 'Uparivanje, nazivi uređaja i šifrovanje sada se nalaze uz stanje sinhronizacije.',
    'moved_cta' => 'Otvori Sinhronizaciju i uređaj',
    'device_name' => 'Naziv uređaja',
    'save' => 'Sačuvaj',
    'peer_default_name' => 'Upareni uređaj',
    'rename_device' => 'Preimenuj uređaj',
    'this_device' => 'Ovaj uređaj',
    'removed' => 'Uklonjeno',
    'confirmed' => 'Potvrđeno',
    'awaiting_confirmation' => 'Čeka potvrdu',
    'safety_number_words' => 'Reči bezbednosnog broja:',
    'paired' => 'Upareno',
    'remove_aria' => 'Ukloni :name',
    'remove' => 'Ukloni',
    'pair_new_device' => 'Upari novi uređaj',

    'relay_endpoint' => 'Krajnja tačka releja',
    'relay_endpoint_help' => 'Opciono. Kad je postavljena, uređaji van mreže se sinhronizuju preko ovog releja. Ostavi prazno samo za direktnu vezu unutar LAN&#8209;a.',
    'relay_endpoint_aria' => 'URL krajnje tačke releja',
    'relay_insecure_warning' => 'Ova krajnja tačka releja koristi običan HTTP. Iako relej nikad ne dešifruje tvoje podatke, nebezbedna veza otkriva posmatračima mreže veličine šifrovanih podataka i vreme slanja. Za najbolju privatnost koristi <strong>https://</strong> krajnju tačku.',

    'enable_at_rest' => 'Uključi šifrovanje u mirovanju',
    'enable_at_rest_body' => 'Tvoji podaci će biti šifrovani lozinkom za zaključavanje aplikacije. Rezervna kopija pre migracije napraviće se automatski.',
    'no_recovery_warning' => 'Ako izgubiš lozinku za zaključavanje aplikacije, a nemaš rezervnu kopiju ni drugi pouzdan uređaj, tvoje podatke nije moguće vratiti.',
    'recover_help' => 'Da vratiš pristup, ponovo upari ovaj uređaj sa drugog pouzdanog uređaja ili iskoristi svoju zasebnu šifrovanu rezervnu kopiju.',
    'amounts_plaintext' => 'Iznosi nisu šifrovani u mirovanju — stanja i zbirovi ostaju čitljivi da bi se tvoji mesečni zbirovi i dalje ispravno računali.',
    'search_plaintext' => 'Indeks pretrage čuva nešifrovanu kopiju naziva trgovca i opisa da bi pretraga celog teksta i dalje radila.',
    'keep_unencrypted' => 'Zadrži podatke nešifrovane',
    'encryption_enabled' => 'Šifrovanje je uključeno',
    'encryption_enabled_body' => 'Tvoji podaci su sada šifrovani u mirovanju.',
    'done_encryption_enabled' => 'Gotovo — šifrovanje je uključeno',
    'encryption_failed' => 'Podešavanje šifrovanja nije uspelo',
    'encryption_failed_body' => 'Tvoji podaci nisu promenjeni. Tvoja rezervna kopija je sačuvana.',
    'close_no_changes' => 'Zatvori — ništa nije promenjeno',

    'remove_this_device' => 'Ukloni ovaj uređaj',
    'removing' => 'Uklanja se:',
    'remove_rotates_key' => 'Uklanjanje ovog uređaja menja ključ za šifrovanje pa uređaj više ne prima nikakva ažuriranja.',
    'remove_cannot_erase' => 'Time se ne brišu podaci koji su već na tom uređaju. Ako je uređaj izgubljen ili ukraden, sve podatke koje je sadržao smatraj otkrivenim.',
    'remove_device' => 'Ukloni uređaj',
    'keep_device' => 'Zadrži uređaj',
    'rotating_key' => 'Menjanje ključa za šifrovanje…',

    'flash' => [
        'app_lock_first' => 'Prvo postavi zaključavanje aplikacije da uključiš sinhronizaciju.',
        'enable_failed' => 'Uključivanje sinhronizacije nije uspelo. Proveri da li je zaključavanje aplikacije aktivno pa probaj ponovo.',
        'cannot_remove_self' => 'Ne možeš da ukloniš ovaj uređaj — na njemu upravo radiš.',
        'remove_failed' => 'Uklanjanje uređaja nije uspelo. Probaj ponovo.',
        'app_lock_first_settings' => 'Prvo postavi zaključavanje aplikacije da promeniš podešavanja sinhronizacije.',
        'relay_cleared' => 'Krajnja tačka releja je uklonjena.',
        'relay_saved' => 'Krajnja tačka releja je sačuvana.',
        'relay_save_failed' => 'Čuvanje krajnje tačke releja nije uspelo: :message',
    ],
];
