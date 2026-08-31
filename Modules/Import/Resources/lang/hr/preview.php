<?php

declare(strict_types=1);

return [
    'page_title' => 'Pregled uvoza',
    'heading' => 'Pregled uvoza',
    'discard' => 'Odbaci uvoz',
    'confirm' => 'Potvrdi uvoz',
    'subtitle' => 'Pregledaj obrađene retke. Ništa se ne sprema u tvoju glavnu knjigu dok ne potvrdiš.',

    'already_imported' => 'Ova datoteka već je uvezena.',

    'already_imported_link' => 'Pogledaj rezultat uvoza',

    'expired_html' => 'Pregled je istekao. <a href="/imports/new" class="underline">Ponovno učitaj datoteku</a> za novi pokušaj.',

    'save_name' => 'Spremi naziv',
    'account_name_label' => 'Naziv računa',
    'account_placeholder' => 'npr. Glavni štedni račun',
    'rename_aria' => 'Preimenuj ovu protustranku',

    'unknown_iban_prefix' => 'Pronašli smo nepoznat IBAN:',

    'unknown_account_prefix' => 'Pronašli smo nepoznat račun:',
    'unknown_iban_suffix' => 'Imenuj ovaj račun.',

    'ics' => [
        'name' => 'ICS kartica',
        'heading' => 'Imenuj svoj ICS kartični račun.',
        'help' => 'Ovo je prvi put da uvoziš ICS podatke. Daj ovoj kartici naziv kako bi se dosljedno prikazivala u cijeloj aplikaciji.',
        'placeholder' => 'npr. ICS kartica',
    ],

    'paypal' => [
        'name' => 'PayPal',
        'heading' => 'Imenuj svoj PayPal račun.',
        'help' => 'Ovo je prvi put da uvoziš PayPal podatke. Daj ovom novčaniku naziv kako bi se dosljedno prikazivao u cijeloj aplikaciji.',
        'placeholder' => 'npr. PayPal',
    ],

    'google_play' => [
        'name' => 'Google Play',
        'heading' => 'Imenuj svoj Google Play račun.',
        'help' => 'Ovo je prvi put da uvoziš Google Play račun. Daj ovom računu naziv kako bi se dosljedno prikazivao u cijeloj aplikaciji.',
        'placeholder' => 'npr. Google Play',
    ],

    'col_date' => 'Datum',
    'col_funding_source' => 'Izvor sredstava',
    'col_counterparty' => 'Protustranka',
    'col_amount' => 'Iznos',
    'col_status' => 'Status',

    'status' => [
        'new' => 'Novo',
        'new_title' => 'Bit će dodano u tvoju glavnu knjigu.',
        'duplicate' => 'Duplikat',
        'duplicate_title' => 'Već uvezeno — bit će preskočeno.',
        'enriched' => 'Obogaćeno',
        'enriched_title' => 'Postojeći redak bit će ažuriran jačom referencom izvora.',
        'error' => 'Pogreška',
    ],

    'rows_shown' => 'Prikazani redci: :shown od :total',

    'show_more' => 'Prikaži više redaka',

    'errors' => [
        'app_locked' => 'Otključajte aplikaciju za uvoz: ključevi za šifriranje ne mogu se upotrijebiti dok je zaključana.',
        'archive_holds_one_message' => 'Ova datoteka je jedna poruka e-pošte, a ne arhiva pretinca e-pošte, pa pročitana kao arhiva ne sadrži ništa. Prenesi je ponovno s formatom Poruka e-pošte.',
        'email_file_is_an_archive' => 'Ova datoteka je arhiva pretinca e-pošte: sadrži više od jedne poruke, a pročitana kao jedna poruka uzela bi samo prvu. Prenesi je ponovno s formatom Arhiva pretinca e-pošte.',
        'file_stopped_short' => 'Zaglavlje je odgovaralo, pa je format ispravan. Čitanje je stalo prije kraja datoteke. To izaziva jedan nečitljiv redak, kao i datoteka prevelika za ovaj uređaj. Pokušaj s kraćim razdobljem.',
        'file_unreadable' => 'Ovu datoteku nije bilo moguće pročitati.',
        'file_unreadable_detail' => 'Aplikacija nije mogla pročitati ovu datoteku (:code). Potpuni podaci nalaze se u zapisniku aplikacije; navedite ovaj kôd ako prijavljujete problem.',
        'iban_not_in_preview' => 'Ovaj IBAN nije dio trenutnog pregleda.',
        'not_an_email_file' => 'Ova datoteka nije ni poruka e-pošte ni arhiva pretinca e-pošte, pa se u njoj nema što pročitati kao potvrda. Odaberi vrstu uvoza i format koji odgovaraju tvojoj datoteci.',
        'pdf_has_no_text_layer' => 'Ovaj PDF ne sadrži tekst — to je skenirani izvod ili njegova fotografija, pa se u njemu nema što pročitati. Preuzmi sam izvod iz svoje banke ili upotrijebi CSV izvoz.',
        'pdf_password_protected' => 'Ovaj PDF zaštićen je lozinkom, pa ga nijedan čitač ne može otvoriti. U svojem PDF pregledniku spremi nezaštićenu kopiju i uvezi nju.',
        'pdf_reader_unavailable' => 'Ova verzija aplikacije nema nikakav čitač PDF-a, pa se PDF izvod ovdje ne može otvoriti. Uvezi ovu datoteku na drugom uređaju ili radije upotrijebi CSV izvoz iz banke.',
        'row_belongs_to_another_statement' => 'Ovaj redak pripada transakciji u drugoj datoteci izvatka. Uvezite i taj izvadak — dva se čitaju zajedno.',
        'row_unreadable' => 'Ovaj redak nije bilo moguće pročitati.',
        'row_unreadable_detail' => 'Aplikacija nije mogla pročitati ovaj redak (:code). Potpuni podaci nalaze se u zapisniku aplikacije; navedite ovaj kôd ako prijavljujete problem.',
        'unknown_account' => 'Ovaj redak pripada računu kojem još nisi dao naziv.',
    ],

    'receipts' => [
        'heading' => 'Ova datoteka pročitana je kao e-pošta',
        'saved' => 'Što je sadržavala, navedeno je niže, a svaka poruka je spremljena.',
        'none_imported' => 'Ništa od toga nije postalo transakcija, pa u tvoju glavnu knjigu nije dodano ništa.',
        'shown' => 'Prikazane poruke: :shown od :total',
        'no_subject' => 'Bez predmeta',

        'state' => [
            'read' => 'Pročitano kao plaćanje — potvrdi ovaj uvoz da uđe u tvoju glavnu knjigu.',
            'not_a_payment' => 'Nije plaćanje. Ova poruka nešto najavljuje umjesto da potvrđuje plaćanje.',
            'unreadable' => 'Spremljeno. Aplikacija čita račune ovog pošiljatelja, ali u ovoj poruci nije našla iznos, trgovca ni referencu.',
            'unknown_sender' => 'Spremljeno. Aplikacija ne čita račune ovog pošiljatelja, pa iz poruke nije uzela ništa.',
        ],
    ],

    'failed' => [
        'heading' => 'Ovu datoteku nije bilo moguće pročitati',
        'no_rows' => 'U ovoj datoteci nisu pronađene transakcije, pa nema što uvesti.',
        'nothing_read' => 'Ništa u ovoj datoteci nije bilo moguće pročitati kao transakciju, pa nema što uvesti.',
        'every_row' => 'Nijedan redak ove datoteke nije bilo moguće pročitati, pa nema što uvesti. Svaki je redak naveden ispod s razlogom.',
        'likely_cause' => 'Najčešće zaglavlje ne odgovara izvoru koji si odabrao. Provjeri banku i format na zaslonu za prijenos ili ponovno preuzmi izvadak iz svoje banke.',
        'truncated_heading' => 'Iz ove datoteke bilo je moguće pročitati samo dio',
        'truncated' => 'Čitanje je stalo na pola datoteke. Sve nakon te točke nije pročitano i neće biti uvezeno.',
        'some_rows' => 'Neke retke nije bilo moguće pročitati. Označeni su ispod i bit će preskočeni; potvrdom se uvozi ostatak.',
        'detail_label' => 'Što je parser prijavio:',
        'rows_read_label' => 'Pročitani retci',
        'rows_skipped_label' => 'Preskočeni retci',
    ],
];
