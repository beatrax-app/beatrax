<?php

declare(strict_types=1);

return [
    'heading' => 'Naprave in sinhronizacija',

    'enable_sync' => 'Vklopi sinhronizacijo',
    'enable_sync_help' => 'Varno deli svoje podatke med zaupanja vrednimi napravami. Zahteva zaklepanje aplikacije. Ko je vklopljeno, so tvoji podatki šifrirani, zaklepanja aplikacije pa ni več mogoče izklopiti.',

    'app_lock_notice' => 'Najprej nastavi zaklepanje aplikacije, da vklopiš sinhronizacijo.',
    'go_to_app_lock' => 'Pojdi na Zaklepanje aplikacije',

    'identity_unreadable' => 'Sinhronizacijska identiteta te naprave je nastala z drugim zaklepom aplikacije in se ne odpre več. Dokler je tako, naprava ne more sinhronizirati ali se seznaniti. Če obnoviš varnostno kopijo zbirke podatkov, s katero je nastala, bo spet berljiva.',
    'identity_unreadable_replace_help' => 'Lahko tudi začneš znova: naprava dobi novo identiteto, stara ostane neuporabljena ob strani, prej seznanjene naprave pa je treba seznaniti znova.',
    'identity_unreadable_replace' => 'Ustvari novo identiteto za to napravo',

    'encrypted_at_rest' => 'Podatki šifrirani v mirovanju',
    'encrypted_at_rest_scope' => 'Zapiski, opisi transakcij ter imena in IBAN tistih, ki jim plačuješ, so v knjigi šifrirani z geslom za zaklep aplikacije. Zneski, datumi ter ime in IBAN tvojega računa niso. Iskalni indeks hrani svojo berljivo kopijo tega, komu plačuješ, opisov tvojih transakcij in tvojih davčnih zapiskov, nekatera imena trgovcev pa ostajajo berljiva drugje v datoteki baze podatkov.',
    'on' => 'Vklopljeno',
    'securing' => 'Zavarovanje tvojih podatkov…',
    'do_not_close' => 'Ne zapiraj tega okna.',
    'encryption_progress_aria' => 'Napredek šifriranja',
    'not_encrypted_offer' => 'Tvoji podatki niso šifrirani v mirovanju. Šifriranje skrije, komu plačuješ, če napravo izgubiš ali ti jo ukradejo — zneski, datumi in iskalni indeks ostanejo berljivi.',
    'enable_encryption' => 'Vklopi šifriranje',

    'your_devices' => 'Tvoje naprave',

    'device_name' => 'Ime naprave',
    'save' => 'Shrani',
    'peer_default_name' => 'Seznanjena naprava',
    'rename_device' => 'Preimenuj napravo',
    'rename_device_caption' => 'Preimenuj',
    'this_device' => 'Ta naprava',
    'removed' => 'Odstranjeno',
    'confirmed' => 'Potrjeno',
    'awaiting_confirmation' => 'Čaka potrditev',
    'safety_number_words' => 'Besede varnostne številke:',
    'paired' => 'Seznanjeno',
    'remove_aria' => 'Odstrani :name',
    'remove' => 'Odstrani',
    'pair_new_device' => 'Seznani novo napravo',

    'pairing_waiting' => 'Dokončajte seznanjanje z napravo :name',
    'pairing_waiting_help' => 'Oba zaslona morata prikazovati istih šest besed, preden seznanjanje velja. Znova ga odprite in ju primerjajte.',
    'pairing_waiting_resume' => 'Nadaljuj seznanjanje',
    'pairing_waiting_lock_override' => 'Odklepanje to seznanjanje znova odpre, namesto da bi mu pustilo poteči, zato traja dlje od nastavljenega časa zaklepanja aplikacije. Konča se, ko ga dokončate ali prekličete.',

    'relay_endpoint' => 'Končna točka releja',
    'relay_endpoint_help' => 'Neobvezno. Ko je nastavljena, se naprave brez povezave sinhronizirajo prek tega releja. Pusti prazno samo za neposredno povezavo znotraj LAN&#8209;a.',
    'relay_endpoint_help_phone' => 'Neobvezno. Ko je nastavljena, spremembe potujejo prek tega releja tudi takrat, ko tvoji napravi nista v istem omrežju. Ta naprava jih prevzame, ko sinhroniziraš s tega zaslona — nikoli v ozadju, ker zaklep aplikacije hrani edini ključ. Pusti prazno samo za neposredno povezavo znotraj LAN&#8209;a.',
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
    'encryption_enabled_scope' => 'Zapiski, opisi in to, komu plačuješ, so zdaj šifrirani z geslom za zaklep aplikacije. Zneski, datumi in iskalni indeks ostanejo berljivi.',
    'done_encryption_enabled' => 'Končano — šifriranje je vklopljeno',
    'encryption_failed' => 'Nastavitev šifriranja ni uspela',
    'encryption_failed_body' => 'Tvoji podatki niso bili spremenjeni. Tvoja varnostna kopija je ohranjena.',
    'close_no_changes' => 'Zapri — nič ni bilo spremenjeno',

    'remove_this_device' => 'Odstrani to napravo',
    'removing' => 'Odstranjuje se:',
    'remove_rotates_key' => 'Odstranitev te naprave zamenja šifrirni ključ, zato naprava ne prejme nobenih prihodnjih posodobitev.',
    'remove_cannot_erase' => 'Podatkov, ki so že na tej napravi, ne more izbrisati. Če je bila naprava izgubljena ali ukradena, obravnavaj vse podatke na njej kot razkrite.',
    'remove_is_local' => 'Tvoje druge naprave imajo svoj seznam. Dokler je ne odstraniš tudi tam, se bodo z njo še naprej sinhronizirale.',
    'remove_device' => 'Odstrani napravo',
    'keep_device' => 'Obdrži napravo',
    'rotating_key' => 'Menjava šifrirnega ključa…',

    'flash' => [
        'app_lock_first' => 'Najprej nastavi zaklepanje aplikacije, da vklopiš sinhronizacijo.',
        'enable_failed' => 'Vklop sinhronizacije ni uspel. Preveri, ali je zaklepanje aplikacije aktivno, in poskusi znova.',
        'identity_replaced' => 'Ta naprava ima novo sinhronizacijsko identiteto. Znova seznani druge naprave.',
        'identity_replace_failed' => 'Stare identitete naprave ni bilo mogoče dati ob stran. Poskusi znova.',
        'cannot_remove_self' => 'Te naprave ne moreš odstraniti — na njej trenutno delaš.',
        'remove_failed' => 'Odstranitev naprave ni uspela. Poskusi znova.',
        'app_lock_first_settings' => 'Najprej nastavi zaklepanje aplikacije, da spremeniš nastavitve sinhronizacije.',
        'relay_cleared' => 'Končna točka releja je počiščena.',
        'relay_saved' => 'Končna točka releja je shranjena.',
        'relay_save_failed' => 'Shranjevanje končne točke releja ni uspelo: :message',
    ],
    'app_lock_permanent' => 'Ko so podatki enkrat šifrirani, zaklepanja aplikacije ni več mogoče izklopiti — hrani edini ključ, poti nazaj k nešifriranim podatkom pa ni.',
    'backlog_heading' => 'Čaka na dodajanje',
    'backlog_deferred' => 'Ta naprava je prejela podatke z druge naprave in jih še ni dodala v tvojo evidenco. Nič ni izgubljeno — dodani bodo samodejno, običajno v trenutku.',
    'backlog_awaiting_key' => 'Ta naprava je prejela podatke, za katere še nima ključa. Nič ni izgubljeno. Odpri aplikacijo na povezani napravi, medtem ko je ta odprta, da se lahko povežeta in se ključ pošlje.',
];
