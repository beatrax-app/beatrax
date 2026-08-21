<?php

declare(strict_types=1);

return [
    'heading' => 'Uređaji i sinkronizacija',

    'enable_sync' => 'Uključi sinkronizaciju',
    'enable_sync_help' => 'Sigurno dijeli svoje podatke među pouzdanim uređajima. Zahtijeva zaključavanje aplikacije.',

    'app_lock_notice' => 'Prvo postavi zaključavanje aplikacije da uključiš sinkronizaciju.',
    'go_to_app_lock' => 'Idi na Zaključavanje aplikacije',

    'encrypted_at_rest' => 'Podaci šifrirani u mirovanju',
    'encrypted_at_rest_help' => 'Tvoji podaci osigurani su zaporkom za zaključavanje aplikacije.',
    'on' => 'Uključeno',
    'securing' => 'Osiguravanje tvojih podataka…',
    'do_not_close' => 'Ne zatvaraj ovaj prozor.',
    'encryption_progress_aria' => 'Napredak šifriranja',
    'not_encrypted_offer' => 'Tvoji podaci nisu šifrirani u mirovanju. Postavi šifriranje da ih zaštitiš ako uređaj bude izgubljen ili ukraden.',
    'enable_encryption' => 'Uključi šifriranje',

    'your_devices' => 'Tvoji uređaji',

    // Settings keeps a pointer to the moved surface; the section
    // itself now lives on /sync with the status and sync action.
    'moved_help' => 'Uparivanje, nazivi uređaja i šifriranje sada se nalaze uz stanje sinkronizacije.',
    'moved_cta' => 'Otvori Sinkronizaciju i uređaj',
    'device_name' => 'Naziv uređaja',
    'save' => 'Spremi',
    'peer_default_name' => 'Upareni uređaj',
    'rename_device' => 'Preimenuj uređaj',
    'this_device' => 'Ovaj uređaj',
    'removed' => 'Uklonjeno',
    'confirmed' => 'Potvrđeno',
    'awaiting_confirmation' => 'Čeka potvrdu',
    'safety_number_words' => 'Riječi sigurnosnog broja:',
    'paired' => 'Upareno',
    'remove_aria' => 'Ukloni :name',
    'remove' => 'Ukloni',
    'pair_new_device' => 'Upari novi uređaj',

    'relay_endpoint' => 'Krajnja točka releja',
    'relay_endpoint_help' => 'Neobavezno. Kad je postavljena, uređaji izvan mreže sinkroniziraju se preko ovog releja. Ostavi prazno samo za izravnu vezu unutar LAN&#8209;a.',
    'relay_endpoint_aria' => 'URL krajnje točke releja',
    'relay_insecure_warning' => 'Ova krajnja točka releja koristi običan HTTP. Iako relej nikad ne dešifrira tvoje podatke, nesigurna veza otkriva promatračima mreže veličine šifriranih podataka i vrijeme slanja. Za najbolju privatnost upotrijebi <strong>https://</strong> krajnju točku.',

    'enable_at_rest' => 'Uključi šifriranje u mirovanju',
    'enable_at_rest_body' => 'Tvoji podaci bit će šifrirani zaporkom za zaključavanje aplikacije. Sigurnosna kopija prije migracije izradit će se automatski.',
    'no_recovery_warning' => 'Ako izgubiš zaporku za zaključavanje aplikacije, a nemaš sigurnosnu kopiju ni drugi pouzdani uređaj, tvoje podatke nije moguće vratiti.',
    'recover_help' => 'Za povrat pristupa ponovno upari ovaj uređaj s drugog pouzdanog uređaja ili upotrijebi svoju zasebnu šifriranu sigurnosnu kopiju.',
    'amounts_plaintext' => 'Iznosi nisu šifrirani u mirovanju — stanja i zbrojevi ostaju čitljivi kako bi se tvoji mjesečni zbrojevi i dalje ispravno računali.',
    'search_plaintext' => 'Indeks pretraživanja čuva nešifriranu kopiju naziva trgovca i opisa kako bi pretraživanje cijelog teksta i dalje radilo.',
    'keep_unencrypted' => 'Zadrži podatke nešifrirane',
    'encryption_enabled' => 'Šifriranje je uključeno',
    'encryption_enabled_body' => 'Tvoji podaci sada su šifrirani u mirovanju.',
    'done_encryption_enabled' => 'Gotovo — šifriranje je uključeno',
    'encryption_failed' => 'Postavljanje šifriranja nije uspjelo',
    'encryption_failed_body' => 'Tvoji podaci nisu promijenjeni. Tvoja sigurnosna kopija je sačuvana.',
    'close_no_changes' => 'Zatvori — ništa nije promijenjeno',

    'remove_this_device' => 'Ukloni ovaj uređaj',
    'removing' => 'Uklanja se:',
    'remove_rotates_key' => 'Uklanjanje ovog uređaja mijenja ključ za šifriranje pa uređaj više ne prima nikakva ažuriranja.',
    'remove_cannot_erase' => 'Time se ne brišu podaci koji su već na tom uređaju. Ako je uređaj izgubljen ili ukraden, sve podatke koje je sadržavao smatraj otkrivenima.',
    'remove_device' => 'Ukloni uređaj',
    'keep_device' => 'Zadrži uređaj',
    'rotating_key' => 'Mijenjanje ključa za šifriranje…',

    'flash' => [
        'app_lock_first' => 'Prvo postavi zaključavanje aplikacije da uključiš sinkronizaciju.',
        'enable_failed' => 'Uključivanje sinkronizacije nije uspjelo. Provjeri je li zaključavanje aplikacije aktivno pa pokušaj ponovno.',
        'cannot_remove_self' => 'Ne možeš ukloniti ovaj uređaj — na njemu upravo radiš.',
        'remove_failed' => 'Uklanjanje uređaja nije uspjelo. Pokušaj ponovno.',
        'app_lock_first_settings' => 'Prvo postavi zaključavanje aplikacije da promijeniš postavke sinkronizacije.',
        'relay_cleared' => 'Krajnja točka releja je uklonjena.',
        'relay_saved' => 'Krajnja točka releja je spremljena.',
        'relay_save_failed' => 'Spremanje krajnje točke releja nije uspjelo: :message',
    ],
];
