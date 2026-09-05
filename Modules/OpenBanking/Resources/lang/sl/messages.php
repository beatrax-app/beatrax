<?php

declare(strict_types=1);

return [

    'page' => [
        'back_link' => 'Nastavitve',
        'heading' => 'Odprto bančništvo',
        'subtitle' => 'Samodejno pridobivaj transakcije iz ASN ali SNS prek Enable Bankinga, zunanjega agregatorja PSD2. Privzeto izklopljeno.',
        'toggle_label' => 'Vklopi odprto bančništvo',
        'toggle_connected' => 'Povezano z banko :bank prek Enable Bankinga.',
        'toggle_off_help' => 'Privzeto izklopljeno. Zahteva enkratno potrditev in vodeno nastavitev.',
        'connect_another' => 'Poveži drugo banko',
        'credentials_unreadable' => 'Poverilnic za odprto bančništvo, shranjenih v tej napravi, ni mogoče prebrati, zato se Beatrax ne more povezati s tvojo banko.',
        'credentials_unreadable_next' => 'Znova opravi vodeno nastavitev, da jih zamenjaš. Transakcije, ki so že uvožene, ostanejo nedotaknjene.',
        'reconfirm_body' => 'Tvoja privolitev je potekla, preden smo lahko dokončali povezavo. Znova potrdi, da dokončaš vklop odprtega bančništva.',
        'reconfirm_button' => 'Znova potrdi za dokončanje',
    ],

    'status_row' => [
        'heading' => 'Odprto bančništvo',
        'manage' => 'Upravljaj odprto bančništvo',
        'not_connected' => 'Nobena banka ni povezana. Poveži jo, da samodejno uvažaš transakcije.',
        'expired' => 'Privolitev je potekla — potrebna je ponovna povezava.',
        'revoked' => 'Tvoja banka je prekinila povezavo — poveži se znova.',
        'connected' => 'Povezano z banko :bank prek Enable Bankinga. Zadnja sinhronizacija :when.',
        'never' => 'nikoli',
    ],

    'transparency' => [
        'aggregator_label' => 'Agregator',
        'bank_label' => 'Banka',
        'consent_status_label' => 'Stanje privolitve',
        'pill_expired' => 'Potekla — poveži znova',
        'pill_expiring' => 'Kmalu poteče',
        'pill_connected' => 'Povezano',
        'pill_revoked' => 'Prekinila tvoja banka — poveži se znova',
        'whats_fetched_label' => 'Kaj se pridobiva',
        'whats_fetched' => 'Knjižene transakcije in stanja, zadnjih 90 dni',
        'last_successful_sync_label' => 'Zadnja uspešna sinhronizacija',
        'never' => 'Nikoli',
        'last_attempt_label' => 'Zadnji poskus',
        'last_attempt_failed' => ':when — neuspešno (:reason)',
        'reason_consent_expired' => 'privolitev je potekla',
        'reason_error' => 'napaka',
        'reason_truncated' => 'ustavljeno predčasno',
        'reason_nothing_imported' => 'ničesar ni bilo mogoče zabeležiti',
        'reason_consent_revoked' => 'prekinila tvoja banka',
        'disconnect_button' => 'Prekini povezavo',
    ],

    'consent_banner' => [
        'heading' => 'Privolitev je potekla — poveži znova',
        'heading_revoked' => 'Tvoja banka je prekinila povezavo',
        'body' => 'Zadnja uspešna sinhronizacija je bila :when. Poveži se znova, da se samodejna sinhronizacija nadaljuje.',
        'body_revoked' => 'Tvoja banka ali Enable Banking je odvzela dostop, zato se je sinhronizacija ustavila. Zadnja uspešna sinhronizacija je bila :when. Poveži se znova, da se nadaljuje.',
        'never' => 'nikoli',
        'reconnect' => 'Poveži znova',
    ],

    'sync' => [
        'review_import' => 'Preglej uvoz',
        'reconnect_first' => 'Najprej se poveži znova',
        'auto_caption' => 'Sinhronizira se samodejno enkrat na dan.',
        'sync_now' => 'Sinhroniziraj zdaj',

        'consent_expired' => 'Privolitev je potekla — poveži znova.',
        'unavailable' => 'Enable Banking začasno ni na voljo. Poskusi znova čez nekaj časa.',
        'new_found' => 'Najdena je :count nova transakcija.|Najdeni sta :count novi transakciji.|Najdene so :count nove transakcije.|Najdenih je :count novih transakcij.',
        'none' => 'Ni novih transakcij.',
        'none_importable' => 'Tvoja banka je poslala transakcije, a nobene ni bilo mogoče zabeležiti. Odpri pregled uvoza, da vidiš zakaj.',
        'in_progress' => 'Sinhronizacija že poteka. Poskusite znova čez trenutek.',
        'truncated' => 'Tvoja banka je imela več transakcij, kot jih ena sinhronizacija lahko prenese, zato se je ta izvedba predčasno ustavila. Nič ni bilo zabeleženo kot sinhronizirano — naslednja sinhronizacija se začne na istem mestu.',
    ],

    'disconnect' => [
        'heading' => 'Prekiniti povezavo z odprtim bančništvom?',
        'body' => 'To odstrani shranjene poverilnice in privolitev za Enable Banking. Samodejna sinhronizacija se takoj ustavi. Transakcije, ki so že uvožene v Beatrax, ostanejo nedotaknjene.',
        'confirm' => 'Prekini povezavo',
        'cancel' => 'Ostani povezan',
    ],

    'ics' => [
        'section_label' => 'Uvoz datoteke — poverilnice se ne shranjujejo',
        'heading' => 'Izpisek kreditne kartice ICS',
        'step_login' => 'Prijavi se',
        'step_download' => 'Prenesi izpisek',
        'pdf_statement' => 'Izpisek PDF',
        'step_drop' => 'Spusti ga spodaj',
        'drop_zone_label' => 'Sem spusti datoteko izpiska',
        'drop_zone_hint' => 'ali poišči datoteko',
        'browse_aria' => 'Poišči datoteko izpiska ICS',
        'import_button' => 'Uvozi izpisek',
        'validation' => [
            'required' => 'Spusti izpisek ICS, ki si ga prenesel z Mijn ICS.',
            'max' => 'Ta datoteka je prevelika. Izpiski ICS v PDF so običajno manjši od 1 MB.',
            'extensions' => 'To ni PDF. Mijn ICS izvaža samo izpiske PDF.',
        ],
        'could_not_read' => 'Datoteke :filename ni bilo mogoče prebrati. Celotna napaka je v /dev/logs.',
    ],

    'warning' => [
        'heading' => 'Preden povežeš zunanjega ponudnika',
        'body' => 'Z vklopom odprtega bančništva s te naprave neposredno pošlješ privolitev za prijavo v banko, nato pa še podatke o transakcijah in stanjih, Enable Bankingu in svoji banki. Beatrax ne upravlja strežnika, ki bi te podatke videl — Enable Banking in tvoja banka pa jih vidita. To se razlikuje od vseh drugih načinov uvoza v Beatraxu, ki podatkov ne pošiljajo nikamor.',
        'acknowledge' => 'Razumem, da bodo moji podatki o transakcijah deljeni z Enable Bankingom in mojo banko.',
        'confirm' => 'Vklopi odprto bančništvo',
        'cancel' => 'Prekliči',
    ],

    'wizard' => [
        'heading' => 'Poveži svojo banko',
        'intro' => 'Beatrax uporablja tvojo lastno aplikacijo Enable Banking, zato tvoje poverilnice nikoli ne pridejo na skupni strežnik. To je enkratna nastavitev za vsako banko.',

        'step1_title' => 'Ustvari svoj lokalni par ključev',
        'step1_body' => 'Beatrax na tej napravi ustvari par ključev RSA. Zasebni ključ je nikoli ne zapusti.',
        'generate_keypair' => 'Ustvari par ključev',
        'public_key_label' => 'Javni ključ',
        'copy_public_key' => 'Kopiraj javni ključ',
        'copied' => 'Kopirano',
        'redirect_uri_label' => 'URI preusmeritve',
        'copy_redirect_uri' => 'Kopiraj URI preusmeritve',

        'step2_title' => 'Registriraj aplikacijo v Enable Bankingu',
        'step2_body' => 'Odpri razvijalski portal Enable Banking, ustvari aplikacijo in prilepi javni ključ ter URI preusmeritve iz 1. koraka.',
        'open_portal' => 'Odpri portal Enable Banking ↗',

        'step3_title' => 'Prilepi ID svoje aplikacije',
        'application_id_label' => 'ID aplikacije',
        'step3_help' => 'Shrani se v lokalno datoteko zunaj zbirke podatkov, ki jo lahko prebereš samo ti. Tvojo aplikacijo predstavi storitvi Enable Banking, zato potuje z vsako zahtevo — tvoj zasebni ključ nikoli.',

        'step4_title' => 'Izberi svojo banko',
        'via_enable_banking' => 'prek Enable Bankinga',
        'other_institution' => 'Druga institucija',
        'institution_id_placeholder' => 'ID institucije',

        'step5_title' => 'Dokončaj privolitev v brskalniku',
        'step5_body' => 'Klikni spodaj, da odpreš prijavni zaslon in zaslon za privolitev svoje banke. Dokončaj prijavo in morebitno dvostopenjsko potrditev, nato te bo Beatrax samodejno pripeljal nazaj sem, da dokončaš vklop odprtega bančništva.',
        // i18n-review: sl · step5_body_touch — the same line for a touch
        // screen; check the verb governs this case.
        'step5_body_touch' => 'Tapni spodaj, da odpreš prijavni zaslon in zaslon za privolitev svoje banke. Dokončaj prijavo in morebitno dvostopenjsko potrditev, nato te bo Beatrax samodejno pripeljal nazaj sem, da dokončaš vklop odprtega bančništva.',

        'cancel' => 'Prekliči',
        'continue' => 'Nadaljuj →',
        'continue_to_bank' => 'Nadaljuj na :bank →',
        'your_bank' => 'svojo banko',

        'errors' => [
            'save_keypair_failed' => 'Para ključev ni bilo mogoče shraniti na disk — preveri dovoljenja mape s skrivnostmi in poskusi znova.',
            'generate_failed' => 'Na tej napravi ni bilo mogoče ustvariti para ključev — preveri svojo nastavitev OpenSSL.',
            'export_failed' => 'Ustvarjenega para ključev ni bilo mogoče izvoziti.',
            'read_public_failed' => 'Ustvarjenega javnega ključa ni bilo mogoče prebrati.',
            'generate_first' => 'Pred nadaljevanjem ustvari par ključev.',
            'paste_application_id' => 'Pred nadaljevanjem prilepi ID aplikacije s portala Enable Banking.',
            'save_application_id_failed' => 'ID-ja aplikacije ni bilo mogoče shraniti na disk — preveri dovoljenja mape s skrivnostmi in poskusi znova.',
            'choose_bank' => 'Pred nadaljevanjem izberi banko.',
        ],
    ],

    'errors' => [
        'wizard_incomplete' => 'Najprej dokončaj čarovnika za nastavitev odprtega bančništva.',
        'no_bank_chosen' => 'Pred povezovanjem izberi banko.',
        'no_consent_url' => 'Enable Banking ni vrnil naslova URL za privolitev.',
        'unparseable_consent_url' => 'Enable Banking je vrnil naslov URL za privolitev, ki ga ni mogoče razčleniti.',
        'non_public_consent_host' => 'Enable Banking je vrnil nejavno ime gostitelja za privolitev.',
        'unsafe_consent_url' => 'Enable Banking je vrnil nevaren naslov URL za privolitev.',
        'no_authorization_code' => 'Povratni klic Enable Bankinga ni vrnil avtorizacijske kode.',
        'no_session_id' => 'Enable Banking ni vrnil ID-ja seje.',
        'bank_not_linked' => 'Ta banka na tej napravi ni povezana. Znova jo poveži, da se sinhronizacija nadaljuje.',
        'oauth_state_mismatch' => 'Ta povezava za povezovanje je potekla ali je bila že uporabljena. Znova začni povezovanje banke.',
    ],
];
