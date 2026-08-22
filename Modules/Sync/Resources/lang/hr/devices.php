<?php

declare(strict_types=1);

return [
    'heading' => 'Uređaji i sinkronizacija',

    'enable_sync' => 'Uključi sinkronizaciju',
    'enable_sync_help' => 'Sigurno dijeli svoje podatke među pouzdanim uređajima. Zahtijeva zaključavanje aplikacije. Kada je uključeno, podaci se šifriraju i zaključavanje aplikacije više se ne može isključiti.',

    'app_lock_notice' => 'Prvo postavi zaključavanje aplikacije da uključiš sinkronizaciju.',
    'go_to_app_lock' => 'Idi na Zaključavanje aplikacije',

    'identity_unreadable' => 'Identitet sinkronizacije ovog uređaja nastao je uz drugo zaključavanje aplikacije i više se ne otvara. Dok je tako, uređaj ne može sinkronizirati ni upariti. Vraćanjem sigurnosne kopije baze podataka s kojom je nastao ponovno postaje čitljiv.',
    'identity_unreadable_replace_help' => 'Možeš i početi ispočetka: uređaj dobiva novi identitet, stari ostaje neiskorišten sa strane, a ranije uparene uređaje treba upariti ponovno.',
    'identity_unreadable_replace' => 'Stvori novi identitet za ovaj uređaj',

    'encrypted_at_rest' => 'Podaci šifrirani u mirovanju',
    'encrypted_at_rest_scope' => 'Bilješke, opisi transakcija te imena i IBAN onih kojima plaćaš šifriraju se u knjizi zaporkom za zaključavanje aplikacije. Iznosi, datumi te naziv i IBAN tvojeg vlastitog računa nisu šifrirani. Indeks pretraživanja čuva vlastitu čitljivu kopiju toga kome plaćaš, opisa tvojih transakcija i tvojih poreznih bilješki, a neka imena trgovaca ostaju čitljiva na drugim mjestima u datoteci baze podataka.',
    'on' => 'Uključeno',
    'securing' => 'Osiguravanje tvojih podataka…',
    'do_not_close' => 'Ne zatvaraj ovaj prozor.',
    'encryption_progress_aria' => 'Napredak šifriranja',
    'not_encrypted_offer' => 'Tvoji podaci nisu šifrirani u mirovanju. Šifriranje skriva kome plaćaš ako ovaj uređaj izgubiš ili ti ga ukradu — iznosi, datumi i indeks pretraživanja ostaju čitljivi.',
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

    'pairing_waiting' => 'Dovršite uparivanje s :name',
    'pairing_waiting_help' => 'Oba zaslona moraju prikazivati istih šest riječi prije nego što uparivanje vrijedi. Ponovno ga otvorite kako biste ih usporedili.',
    'pairing_waiting_resume' => 'Nastavi uparivanje',
    'pairing_waiting_lock_override' => 'Otključavanje ponovno otvara ovo uparivanje umjesto da mu pusti da istekne, pa traje dulje od vremena zaključavanja aplikacije koje ste postavili. Završava kad ga dovršite ili otkažete.',

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
    'encryption_enabled_scope' => 'Bilješke, opisi i to kome plaćaš sada su šifrirani zaporkom za zaključavanje aplikacije. Iznosi, datumi i indeks pretraživanja ostaju čitljivi.',
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
        'identity_replaced' => 'Ovaj uređaj ima novi identitet sinkronizacije. Ponovno upari svoje druge uređaje.',
        'identity_replace_failed' => 'Stari identitet uređaja nije se mogao odložiti sa strane. Pokušaj ponovno.',
        'cannot_remove_self' => 'Ne možeš ukloniti ovaj uređaj — na njemu upravo radiš.',
        'remove_failed' => 'Uklanjanje uređaja nije uspjelo. Pokušaj ponovno.',
        'app_lock_first_settings' => 'Prvo postavi zaključavanje aplikacije da promijeniš postavke sinkronizacije.',
        'relay_cleared' => 'Krajnja točka releja je uklonjena.',
        'relay_saved' => 'Krajnja točka releja je spremljena.',
        'relay_save_failed' => 'Spremanje krajnje točke releja nije uspjelo: :message',
    ],
    'app_lock_permanent' => 'Kada su podaci jednom šifrirani, zaključavanje aplikacije više se ne može isključiti — drži jedini ključ, a povratka na nešifrirano nema.',
    'backlog_heading' => 'Čeka dodavanje',
    'backlog_deferred' => 'Ovaj uređaj primio je podatke s drugog uređaja i još ih nije dodao u tvoju evidenciju. Ništa se ne gubi — bit će dodani automatski, obično u trenutku.',
    'backlog_awaiting_key' => 'Ovaj uređaj primio je podatke za koje još nema ključ. Ništa se ne gubi. Otvori aplikaciju na uparenom uređaju dok je ovaj otvoren, kako bi se mogli povezati i ključ poslati.',
];
