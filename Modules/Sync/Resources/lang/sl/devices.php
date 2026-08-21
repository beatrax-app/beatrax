<?php

declare(strict_types=1);

return [
    'heading' => 'Naprave in sinhronizacija',

    'enable_sync' => 'Vklopi sinhronizacijo',
    'enable_sync_help' => 'Varno deli svoje podatke med zaupanja vrednimi napravami. Zahteva zaklepanje aplikacije.',

    'app_lock_notice' => 'Najprej nastavi zaklepanje aplikacije, da vklopiš sinhronizacijo.',
    'go_to_app_lock' => 'Pojdi na Zaklepanje aplikacije',

    'encrypted_at_rest' => 'Podatki šifrirani v mirovanju',
    'encrypted_at_rest_scope' => 'Zapiski, opisi transakcij ter imena in IBAN tistih, ki jim plačuješ, so šifrirani z geslom za zaklep aplikacije. Zneski, datumi ter ime in IBAN tvojega računa niso, nekatera imena trgovcev pa ostajajo berljiva drugje v datoteki baze podatkov.',
    'on' => 'Vklopljeno',
    'securing' => 'Zavarovanje tvojih podatkov…',
    'do_not_close' => 'Ne zapiraj tega okna.',
    'encryption_progress_aria' => 'Napredek šifriranja',
    'not_encrypted_offer' => 'Tvoji podatki niso šifrirani v mirovanju. Nastavi šifriranje, da jih zaščitiš, če napravo izgubiš ali ti jo ukradejo.',
    'enable_encryption' => 'Vklopi šifriranje',

    'your_devices' => 'Tvoje naprave',

    // Settings keeps a pointer to the moved surface; the section
    // itself now lives on /sync with the status and sync action.
    'moved_help' => 'Seznanjanje, imena naprav in šifriranje so zdaj pri stanju sinhronizacije.',
    'moved_cta' => 'Odpri Sinhronizacijo in napravo',
    'device_name' => 'Ime naprave',
    'save' => 'Shrani',
    'peer_default_name' => 'Seznanjena naprava',
    'rename_device' => 'Preimenuj napravo',
    'this_device' => 'Ta naprava',
    'removed' => 'Odstranjeno',
    'confirmed' => 'Potrjeno',
    'awaiting_confirmation' => 'Čaka potrditev',
    'safety_number_words' => 'Besede varnostne številke:',
    'paired' => 'Seznanjeno',
    'remove_aria' => 'Odstrani :name',
    'remove' => 'Odstrani',
    'pair_new_device' => 'Seznani novo napravo',

    'relay_endpoint' => 'Končna točka releja',
    'relay_endpoint_help' => 'Neobvezno. Ko je nastavljena, se naprave brez povezave sinhronizirajo prek tega releja. Pusti prazno samo za neposredno povezavo znotraj LAN&#8209;a.',
    'relay_endpoint_aria' => 'URL končne točke releja',
    'relay_insecure_warning' => 'Ta končna točka releja uporablja navaden HTTP. Čeprav rele tvojih podatkov nikoli ne dešifrira, nezavarovana povezava opazovalcem omrežja razkrije velikosti šifriranih podatkov in čas pošiljanja. Za najboljšo zasebnost uporabi končno točko <strong>https://</strong>.',

    'enable_at_rest' => 'Vklopi šifriranje v mirovanju',
    'enable_at_rest_body' => 'Tvoji podatki bodo šifrirani z geslom za zaklepanje aplikacije. Varnostna kopija pred selitvijo bo ustvarjena samodejno.',
    'no_recovery_warning' => 'Če izgubiš geslo za zaklepanje aplikacije in nimaš varnostne kopije ali druge zaupanja vredne naprave, tvojih podatkov ni mogoče obnoviti.',
    'recover_help' => 'Za obnovitev dostopa to napravo znova seznani z druge zaupanja vredne naprave ali uporabi svojo ločeno šifrirano varnostno kopijo.',
    'amounts_plaintext' => 'Zneski v mirovanju niso šifrirani — stanja in seštevki ostanejo berljivi, da se tvoji mesečni seštevki še naprej pravilno izidejo.',
    'search_plaintext' => 'Iskalni indeks hrani nešifrirano kopijo imena trgovca in opisa, da iskanje po celotnem besedilu še naprej deluje.',
    'keep_unencrypted' => 'Obdrži podatke nešifrirane',
    'encryption_enabled' => 'Šifriranje je vklopljeno',
    'encryption_enabled_body' => 'Tvoji podatki so zdaj šifrirani v mirovanju.',
    'done_encryption_enabled' => 'Končano — šifriranje je vklopljeno',
    'encryption_failed' => 'Nastavitev šifriranja ni uspela',
    'encryption_failed_body' => 'Tvoji podatki niso bili spremenjeni. Tvoja varnostna kopija je ohranjena.',
    'close_no_changes' => 'Zapri — nič ni bilo spremenjeno',

    'remove_this_device' => 'Odstrani to napravo',
    'removing' => 'Odstranjuje se:',
    'remove_rotates_key' => 'Odstranitev te naprave zamenja šifrirni ključ, zato naprava ne prejme nobenih prihodnjih posodobitev.',
    'remove_cannot_erase' => 'Podatkov, ki so že na tej napravi, ne more izbrisati. Če je bila naprava izgubljena ali ukradena, obravnavaj vse podatke na njej kot razkrite.',
    'remove_device' => 'Odstrani napravo',
    'keep_device' => 'Obdrži napravo',
    'rotating_key' => 'Menjava šifrirnega ključa…',

    'flash' => [
        'app_lock_first' => 'Najprej nastavi zaklepanje aplikacije, da vklopiš sinhronizacijo.',
        'enable_failed' => 'Vklop sinhronizacije ni uspel. Preveri, ali je zaklepanje aplikacije aktivno, in poskusi znova.',
        'cannot_remove_self' => 'Te naprave ne moreš odstraniti — na njej trenutno delaš.',
        'remove_failed' => 'Odstranitev naprave ni uspela. Poskusi znova.',
        'app_lock_first_settings' => 'Najprej nastavi zaklepanje aplikacije, da spremeniš nastavitve sinhronizacije.',
        'relay_cleared' => 'Končna točka releja je počiščena.',
        'relay_saved' => 'Končna točka releja je shranjena.',
        'relay_save_failed' => 'Shranjevanje končne točke releja ni uspelo: :message',
    ],
];
