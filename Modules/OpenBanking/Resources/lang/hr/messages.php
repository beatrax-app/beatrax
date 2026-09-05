<?php

declare(strict_types=1);

return [

    'page' => [
        'back_link' => 'Postavke',
        'heading' => 'Otvoreno bankarstvo',
        'subtitle' => 'Automatski dohvaćaj transakcije iz ASN-a ili SNS-a preko Enable Bankinga, PSD2 agregatora treće strane. Prema zadanome isključeno.',
        'toggle_label' => 'Uključi otvoreno bankarstvo',
        'toggle_connected' => 'Povezano s bankom :bank preko Enable Bankinga.',
        'toggle_off_help' => 'Prema zadanome isključeno. Zahtijeva jednokratnu potvrdu i vođeno postavljanje.',
        'connect_another' => 'Poveži drugu banku',
        'credentials_unreadable' => 'Vjerodajnice za otvoreno bankarstvo pohranjene na ovom uređaju nije moguće pročitati, pa se Beatrax ne može povezati s tvojom bankom.',
        'credentials_unreadable_next' => 'Ponovno prođi vođeno postavljanje da ih zamijeniš. Transakcije koje su već uvezene ostaju netaknute.',
        'reconfirm_body' => 'Tvoja je potvrda istekla prije nego što smo dovršili povezivanje. Potvrdi ponovno da dovršiš uključivanje otvorenog bankarstva.',
        'reconfirm_button' => 'Potvrdi ponovno za dovršetak',
    ],

    'status_row' => [
        'heading' => 'Otvoreno bankarstvo',
        'manage' => 'Upravljaj otvorenim bankarstvom',
        'not_connected' => 'Nijedna banka nije povezana. Poveži je da automatski uvoziš transakcije.',
        'expired' => 'Privola je istekla — potrebno je ponovno povezivanje.',
        'revoked' => 'Tvoja banka prekinula je vezu — poveži se ponovno.',
        'connected' => 'Povezano s bankom :bank preko Enable Bankinga. Zadnja sinkronizacija :when.',
        'never' => 'nikad',
    ],

    'transparency' => [
        'aggregator_label' => 'Agregator',
        'bank_label' => 'Banka',
        'consent_status_label' => 'Status privole',
        'pill_expired' => 'Istekla — poveži ponovno',
        'pill_expiring' => 'Uskoro istječe',
        'pill_connected' => 'Povezano',
        'pill_revoked' => 'Prekinula tvoja banka — poveži se ponovno',
        'whats_fetched_label' => 'Što se dohvaća',
        'whats_fetched' => 'Proknjižene transakcije i stanja, zadnjih 90 dana',
        'last_successful_sync_label' => 'Zadnja uspješna sinkronizacija',
        'never' => 'Nikad',
        'last_attempt_label' => 'Zadnji pokušaj',
        'last_attempt_failed' => ':when — neuspješno (:reason)',
        'reason_consent_expired' => 'privola je istekla',
        'reason_error' => 'pogreška',
        'reason_truncated' => 'zaustavljeno prerano',
        'reason_nothing_imported' => 'ništa nije moglo biti zabilježeno',
        'reason_consent_revoked' => 'prekinula tvoja banka',
        'disconnect_button' => 'Prekini vezu',
    ],

    'consent_banner' => [
        'heading' => 'Privola je istekla — poveži ponovno',
        'heading_revoked' => 'Tvoja banka prekinula je vezu',
        'body' => 'Zadnja uspješna sinkronizacija bila je :when. Poveži se ponovno da nastaviš automatsku sinkronizaciju.',
        'body_revoked' => 'Tvoja banka ili Enable Banking povukla je pristup pa se sinkronizacija zaustavila. Posljednja uspješna sinkronizacija bila je :when. Poveži se ponovno da se nastavi.',
        'never' => 'nikad',
        'reconnect' => 'Poveži ponovno',
    ],

    'sync' => [
        'review_import' => 'Pregledaj uvoz',
        'reconnect_first' => 'Prvo se poveži ponovno',
        'auto_caption' => 'Sinkronizira se automatski jednom dnevno.',
        'sync_now' => 'Sinkroniziraj sada',

        'consent_expired' => 'Privola je istekla — poveži ponovno.',
        'unavailable' => 'Enable Banking privremeno nije dostupan. Pokušaj ponovno uskoro.',
        'new_found' => 'Pronađena je :count nova transakcija.|Pronađene su :count nove transakcije.|Pronađeno je :count novih transakcija.',
        'none' => 'Nema novih transakcija.',
        'none_importable' => 'Tvoja banka poslala je transakcije, ali nijedna nije mogla biti zabilježena. Otvori pregled uvoza da vidiš zašto.',
        'in_progress' => 'Sinkronizacija je već u tijeku. Pokušajte ponovno za trenutak.',
        'truncated' => 'Tvoja banka imala je više transakcija nego što ih jedna sinkronizacija može dohvatiti pa je ovo izvođenje prerano zaustavljeno. Ništa nije zabilježeno kao sinkronizirano — sljedeća sinkronizacija kreće s istog mjesta.',
    ],

    'disconnect' => [
        'heading' => 'Prekinuti vezu s otvorenim bankarstvom?',
        'body' => 'Ovo uklanja pohranjene Enable Banking vjerodajnice i privolu. Automatska sinkronizacija odmah prestaje. Transakcije koje su već uvezene u Beatrax ostaju netaknute.',
        'confirm' => 'Prekini vezu',
        'cancel' => 'Ostani povezan',
    ],

    'ics' => [
        'section_label' => 'Uvoz datoteke — vjerodajnice se ne pohranjuju',
        'heading' => 'ICS izvod kreditne kartice',
        'step_login' => 'Prijavi se',
        'step_download' => 'Preuzmi izvod',
        'pdf_statement' => 'PDF izvod',
        'step_drop' => 'Ispusti ga ispod',
        'drop_zone_label' => 'Ovdje ispusti datoteku izvoda',
        'drop_zone_hint' => 'ili potraži datoteku',
        'browse_aria' => 'Potraži datoteku ICS izvoda',
        'import_button' => 'Uvezi izvod',
        'validation' => [
            'required' => 'Ispusti ICS izvod koji si preuzeo s Mijn ICS.',
            'max' => 'Ta je datoteka prevelika. ICS PDF izvodi obično su manji od 1 MB.',
            'extensions' => 'To nije PDF. Mijn ICS izvozi isključivo PDF izvode.',
        ],
        'could_not_read' => 'Nije bilo moguće pročitati :filename. Cijela pogreška je u /dev/logs.',
    ],

    'warning' => [
        'heading' => 'Prije nego što povežeš treću stranu',
        'body' => 'Uključivanjem otvorenog bankarstva s ovog uređaja izravno šalješ privolu za prijavu u banku, a zatim i podatke o transakcijama i stanjima, Enable Bankingu i svojoj banci. Beatrax ne drži poslužitelj koji vidi te podatke — ali Enable Banking i tvoja banka ih vide. To se razlikuje od svake druge metode uvoza u Beatraxu, koja nikamo ne šalje podatke.',
        'acknowledge' => 'Razumijem da će moji podaci o transakcijama biti podijeljeni s Enable Bankingom i mojom bankom.',
        'confirm' => 'Uključi otvoreno bankarstvo',
        'cancel' => 'Odustani',
    ],

    'wizard' => [
        'heading' => 'Poveži svoju banku',
        'intro' => 'Beatrax koristi tvoju vlastitu Enable Banking aplikaciju pa tvoje vjerodajnice nikad ne dotiču zajednički poslužitelj. Ovo je jednokratno postavljanje po banci.',

        'step1_title' => 'Izradi svoj lokalni par ključeva',
        'step1_body' => 'Beatrax na ovom uređaju izrađuje RSA par ključeva. Privatni ključ nikad ga ne napušta.',
        'generate_keypair' => 'Izradi par ključeva',
        'public_key_label' => 'Javni ključ',
        'copy_public_key' => 'Kopiraj javni ključ',
        'copied' => 'Kopirano',
        'redirect_uri_label' => 'URI preusmjeravanja',
        'copy_redirect_uri' => 'Kopiraj URI preusmjeravanja',

        'step2_title' => 'Registriraj aplikaciju u Enable Bankingu',
        'step2_body' => 'Otvori razvojni portal Enable Bankinga, izradi aplikaciju i zalijepi javni ključ i URI preusmjeravanja iz 1. koraka.',
        'open_portal' => 'Otvori Enable Banking portal ↗',

        'step3_title' => 'Zalijepi ID svoje aplikacije',
        'application_id_label' => 'ID aplikacije',
        'step3_help' => 'Pohranjuje se u lokalnu datoteku izvan baze podataka, čitljivu samo tebi. Identificira tvoju aplikaciju prema Enable Bankingu, pa putuje sa svakim zahtjevom — tvoj privatni ključ nikad.',

        'step4_title' => 'Odaberi svoju banku',
        'via_enable_banking' => 'preko Enable Bankinga',
        'other_institution' => 'Druga institucija',
        'institution_id_placeholder' => 'ID institucije',

        'step5_title' => 'Dovrši privolu u pregledniku',
        'step5_body' => 'Klikni ispod da otvoriš zaslon za prijavu i privolu svoje banke. Dovrši prijavu i eventualnu dvofaktorsku potvrdu, nakon čega ćeš se automatski vratiti ovamo da dovršiš uključivanje otvorenog bankarstva.',
        // i18n-review: hr · step5_body_touch — the same line for a touch
        // screen; check the verb governs this case.
        'step5_body_touch' => 'Dodirni ispod da otvoriš zaslon za prijavu i privolu svoje banke. Dovrši prijavu i eventualnu dvofaktorsku potvrdu, nakon čega ćeš se automatski vratiti ovamo da dovršiš uključivanje otvorenog bankarstva.',

        'cancel' => 'Odustani',
        'continue' => 'Nastavi →',
        'continue_to_bank' => 'Nastavi na :bank →',
        'your_bank' => 'svoju banku',

        'errors' => [
            'save_keypair_failed' => 'Par ključeva nije bilo moguće spremiti na disk — provjeri dopuštenja mape s tajnama pa pokušaj ponovno.',
            'generate_failed' => 'Na ovom uređaju nije bilo moguće izraditi par ključeva — provjeri svoju OpenSSL konfiguraciju.',
            'export_failed' => 'Izrađeni par ključeva nije bilo moguće izvesti.',
            'read_public_failed' => 'Izrađeni javni ključ nije bilo moguće pročitati.',
            'generate_first' => 'Prije nastavka izradi par ključeva.',
            'paste_application_id' => 'Prije nastavka zalijepi ID aplikacije s Enable Banking portala.',
            'save_application_id_failed' => 'ID aplikacije nije bilo moguće spremiti na disk — provjeri dopuštenja mape s tajnama pa pokušaj ponovno.',
            'choose_bank' => 'Prije nastavka odaberi banku.',
        ],
    ],

    'errors' => [
        'wizard_incomplete' => 'Prvo dovrši čarobnjak za postavljanje otvorenog bankarstva.',
        'no_bank_chosen' => 'Prije povezivanja odaberi banku.',
        'no_consent_url' => 'Enable Banking nije vratio URL privole.',
        'unparseable_consent_url' => 'Enable Banking je vratio URL privole koji nije moguće raščlaniti.',
        'non_public_consent_host' => 'Enable Banking je vratio nejavno poslužiteljsko ime za privolu.',
        'unsafe_consent_url' => 'Enable Banking je vratio nesiguran URL privole.',
        'no_authorization_code' => 'Povratni poziv Enable Bankinga nije vratio autorizacijski kod.',
        'no_session_id' => 'Enable Banking nije vratio ID sesije.',
        'bank_not_linked' => 'Ta banka nije povezana na ovom uređaju. Ponovno je poveži da se sinkronizacija nastavi.',
        'oauth_state_mismatch' => 'Ova poveznica za povezivanje istekla je ili je već iskorištena. Ponovno pokrenite povezivanje banke.',
    ],
];
