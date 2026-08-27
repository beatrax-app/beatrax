<?php

declare(strict_types=1);

return [

    'page' => [
        'back_link' => 'Podešavanja',
        'heading' => 'Otvoreno bankarstvo',
        'subtitle' => 'Automatski preuzimaj transakcije iz ASN-a ili SNS-a preko Enable Bankinga, PSD2 agregatora treće strane. Podrazumevano isključeno.',
        'toggle_label' => 'Uključi otvoreno bankarstvo',
        'toggle_connected' => 'Povezano sa bankom :bank preko Enable Bankinga.',
        'toggle_off_help' => 'Podrazumevano isključeno. Zahteva jednokratnu potvrdu i vođeno podešavanje.',
        'reconfirm_body' => 'Tvoja potvrda je istekla pre nego što smo završili povezivanje. Potvrdi ponovo da završiš uključivanje otvorenog bankarstva.',
        'reconfirm_button' => 'Potvrdi ponovo da završiš',
    ],

    'status_row' => [
        'heading' => 'Otvoreno bankarstvo',
        'manage' => 'Upravljaj otvorenim bankarstvom',
        'not_connected' => 'Nijedna banka nije povezana. Poveži je da automatski uvoziš transakcije.',
        'expired' => 'Saglasnost je istekla — potrebno je ponovno povezivanje.',
        'connected' => 'Povezano sa bankom :bank preko Enable Bankinga. Poslednja sinhronizacija :when.',
        'never' => 'nikad',
    ],

    'transparency' => [
        'aggregator_label' => 'Agregator',
        'bank_label' => 'Banka',
        'consent_status_label' => 'Status saglasnosti',
        'pill_expired' => 'Istekla — poveži ponovo',
        'pill_expiring' => 'Uskoro ističe',
        'pill_connected' => 'Povezano',
        'whats_fetched_label' => 'Šta se preuzima',
        'whats_fetched' => 'Proknjižene transakcije i stanja, poslednjih 90 dana',
        'last_successful_sync_label' => 'Poslednja uspešna sinhronizacija',
        'never' => 'Nikad',
        'last_attempt_label' => 'Poslednji pokušaj',
        'last_attempt_failed' => ':when — neuspešno (:reason)',
        'reason_consent_expired' => 'saglasnost je istekla',
        'reason_error' => 'greška',
        'disconnect_button' => 'Prekini vezu',
    ],

    'consent_banner' => [
        'heading' => 'Saglasnost je istekla — poveži ponovo',
        'body' => 'Poslednja uspešna sinhronizacija bila je :when. Poveži se ponovo da nastaviš automatsku sinhronizaciju.',
        'never' => 'nikad',
        'reconnect' => 'Poveži ponovo',
    ],

    'sync' => [
        'review_import' => 'Pregledaj uvoz',
        'reconnect_first' => 'Prvo se poveži ponovo',
        'auto_caption' => 'Sinhronizuje se automatski jednom dnevno.',
        'sync_now' => 'Sinhronizuj sada',

        'consent_expired' => 'Saglasnost je istekla — poveži ponovo.',
        'unavailable' => 'Enable Banking privremeno nije dostupan. Probaj ponovo uskoro.',
        'new_found' => 'Pronađena je :count nova transakcija.|Pronađene su :count nove transakcije.|Pronađeno je :count novih transakcija.',
        'none' => 'Nema novih transakcija.',
    ],

    'disconnect' => [
        'heading' => 'Prekinuti vezu sa otvorenim bankarstvom?',
        'body' => 'Ovo uklanja sačuvane Enable Banking akreditive i saglasnost. Automatska sinhronizacija odmah prestaje. Transakcije koje su već uvezene u Beatrax ostaju netaknute.',
        'confirm' => 'Prekini vezu',
        'cancel' => 'Ostani povezan',
    ],

    'ics' => [
        'section_label' => 'Uvoz datoteke — akreditivi se ne čuvaju',
        'heading' => 'ICS izvod kreditne kartice',
        'step_login' => 'Prijavi se',
        'step_download' => 'Preuzmi izvod',
        'pdf_statement' => 'PDF izvod',
        'step_drop' => 'Prevuci ga ispod',
        'drop_zone_label' => 'Ovde prevuci datoteku izvoda',
        'drop_zone_hint' => 'ili potraži datoteku',
        'browse_aria' => 'Potraži datoteku ICS izvoda',
        'import_button' => 'Uvezi izvod',
        'validation' => [
            'required' => 'Prevuci ICS izvod koji si preuzeo sa Mijn ICS.',
            'max' => 'Ta datoteka je prevelika. ICS PDF izvodi su obično manji od 1 MB.',
            'extensions' => 'To nije PDF. Mijn ICS izvozi isključivo PDF izvode.',
        ],
        'could_not_read' => 'Nije bilo moguće pročitati :filename. Cela greška je u /dev/logs.',
    ],

    'warning' => [
        'heading' => 'Pre nego što povežeš treću stranu',
        'body' => 'Uključivanjem otvorenog bankarstva sa ovog uređaja direktno šalješ saglasnost za prijavu u banku, a zatim i podatke o transakcijama i stanjima, Enable Bankingu i svojoj banci. Beatrax nema server koji vidi te podatke — ali Enable Banking i tvoja banka ih vide. To se razlikuje od svakog drugog načina uvoza u Beatraxu, koji nikuda ne šalje podatke.',
        'acknowledge' => 'Razumem da će moji podaci o transakcijama biti podeljeni sa Enable Bankingom i mojom bankom.',
        'confirm' => 'Uključi otvoreno bankarstvo',
        'cancel' => 'Otkaži',
    ],

    'wizard' => [
        'heading' => 'Poveži svoju banku',
        'intro' => 'Beatrax koristi tvoju sopstvenu Enable Banking aplikaciju pa tvoji akreditivi nikad ne dodiruju zajednički server. Ovo je jednokratno podešavanje po banci.',

        'step1_title' => 'Napravi svoj lokalni par ključeva',
        'step1_body' => 'Beatrax na ovom uređaju pravi RSA par ključeva. Privatni ključ ga nikad ne napušta.',
        'generate_keypair' => 'Napravi par ključeva',
        'public_key_label' => 'Javni ključ',
        'copy_public_key' => 'Kopiraj javni ključ',
        'copied' => 'Kopirano',
        'redirect_uri_label' => 'URI preusmeravanja',
        'copy_redirect_uri' => 'Kopiraj URI preusmeravanja',

        'step2_title' => 'Registruj aplikaciju u Enable Bankingu',
        'step2_body' => 'Otvori razvojni portal Enable Bankinga, napravi aplikaciju i nalepi javni ključ i URI preusmeravanja iz 1. koraka.',
        'open_portal' => 'Otvori Enable Banking portal ↗',

        'step3_title' => 'Nalepi ID svoje aplikacije',
        'application_id_label' => 'ID aplikacije',
        'step3_help' => 'Ovo se čuva u lokalnoj datoteci izvan baze podataka, sa ograničenim dozvolama, i nikad ne napušta ovaj uređaj.',

        'step4_title' => 'Izaberi svoju banku',
        'via_enable_banking' => 'preko Enable Bankinga',
        'other_institution' => 'Druga institucija',
        'institution_id_placeholder' => 'ID institucije',

        'step5_title' => 'Završi saglasnost u pregledaču',
        'step5_body' => 'Klikni ispod da otvoriš ekran za prijavu i saglasnost svoje banke. Završi prijavu i eventualnu dvofaktorsku potvrdu, nakon čega ćeš se automatski vratiti ovde da završiš uključivanje otvorenog bankarstva.',

        'cancel' => 'Otkaži',
        'continue' => 'Nastavi →',
        'continue_to_bank' => 'Nastavi na :bank →',
        'your_bank' => 'svoju banku',

        'errors' => [
            'save_keypair_failed' => 'Par ključeva nije bilo moguće sačuvati na disk — proveri dozvole fascikle sa tajnama pa probaj ponovo.',
            'generate_failed' => 'Na ovom uređaju nije bilo moguće napraviti par ključeva — proveri svoju OpenSSL konfiguraciju.',
            'export_failed' => 'Napravljeni par ključeva nije bilo moguće izvesti.',
            'read_public_failed' => 'Napravljeni javni ključ nije bilo moguće pročitati.',
            'generate_first' => 'Pre nastavka napravi par ključeva.',
            'paste_application_id' => 'Pre nastavka nalepi ID aplikacije sa Enable Banking portala.',
            'save_application_id_failed' => 'ID aplikacije nije bilo moguće sačuvati na disk — proveri dozvole fascikle sa tajnama pa probaj ponovo.',
            'choose_bank' => 'Pre nastavka izaberi banku.',
        ],
    ],

    'alert' => [
        'reconsent' => 'Poveži svoju banku ponovo',
    ],

    'errors' => [
        'wizard_incomplete' => 'Prvo završi čarobnjak za podešavanje otvorenog bankarstva.',
        'no_bank_chosen' => 'Pre povezivanja izaberi banku.',
        'no_consent_url' => 'Enable Banking nije vratio URL saglasnosti.',
        'unparseable_consent_url' => 'Enable Banking je vratio URL saglasnosti koji nije moguće raščlaniti.',
        'non_public_consent_host' => 'Enable Banking je vratio nejavno ime hosta za saglasnost.',
        'unsafe_consent_url' => 'Enable Banking je vratio nebezbedan URL saglasnosti.',
        'no_authorization_code' => 'Povratni poziv Enable Bankinga nije vratio autorizacioni kod.',
        'no_session_id' => 'Enable Banking nije vratio ID sesije.',
        'oauth_state_mismatch' => 'Ova veza za povezivanje je istekla ili je već iskorišćena. Ponovo započnite povezivanje banke.',
    ],
];
