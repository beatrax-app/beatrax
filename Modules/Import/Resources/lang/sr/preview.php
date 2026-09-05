<?php

declare(strict_types=1);

return [
    'page_title' => 'Pregled uvoza',
    'heading' => 'Pregled uvoza',
    'discard' => 'Odbaci uvoz',
    'confirm' => 'Potvrdi uvoz',
    'subtitle' => 'Pregledaj obrađene redove. Ništa se ne čuva u tvojoj glavnoj knjizi dok ne potvrdiš.',

    'already_imported' => 'Ova datoteka je već uvezena.',

    'already_imported_link' => 'Pogledaj rezultat uvoza',

    'expired_html' => 'Pregled je istekao. <a href="/imports/new" class="underline">Ponovo otpremi datoteku</a> za novi pokušaj.',
    'unreadable_html' => 'Pregled nije moguće pročitati. <a href="/imports/new" class="underline">Ponovo otpremi datoteku</a> za novi pokušaj.',

    'save_name' => 'Sačuvaj naziv',
    'account_name_label' => 'Naziv računa',
    'account_placeholder' => 'npr. Glavni štedni račun',
    'rename_aria' => 'Preimenuj ovu drugu stranu',

    'unknown_iban_prefix' => 'Pronašli smo nepoznat IBAN:',

    'unknown_account_prefix' => 'Pronašli smo nepoznat račun:',
    'unknown_iban_suffix' => 'Imenuj ovaj račun.',

    'ics' => [
        'name' => 'ICS kartica',
        'heading' => 'Imenuj svoj ICS kartični račun.',
        'help' => 'Ovo je prvi put da uvoziš ICS podatke. Daj ovoj kartici naziv kako bi se dosledno prikazivala u celoj aplikaciji.',
        'placeholder' => 'npr. ICS kartica',
    ],

    'paypal' => [
        'name' => 'PayPal',
        'heading' => 'Imenuj svoj PayPal račun.',
        'help' => 'Ovo je prvi put da uvoziš PayPal podatke. Daj ovom novčaniku naziv kako bi se dosledno prikazivao u celoj aplikaciji.',
        'placeholder' => 'npr. PayPal',
    ],

    'google_play' => [
        'name' => 'Google Play',
        'heading' => 'Imenuj svoj Google Play nalog.',
        'help' => 'Ovo je prvi put da uvoziš Google Play račun. Daj ovom nalogu naziv kako bi se dosledno prikazivao u celoj aplikaciji.',
        'placeholder' => 'npr. Google Play',
    ],

    'col_date' => 'Datum',
    'col_funding_source' => 'Izvor sredstava',
    'col_counterparty' => 'Druga strana',
    'col_amount' => 'Iznos',
    'col_status' => 'Status',

    'status' => [
        'new' => 'Novo',
        'new_title' => 'Biće dodato u tvoju glavnu knjigu.',
        'duplicate' => 'Duplikat',
        'duplicate_title' => 'Već uvezeno — biće preskočeno.',
        'enriched' => 'Obogaćeno',
        'enriched_title' => 'Postojeći red biće ažuriran jačom referencom izvora.',
        'error' => 'Greška',
    ],

    'rows_shown' => 'Prikazani redovi: :shown od :total',

    'show_more' => 'Prikaži više redova',

    'errors' => [
        'app_locked' => 'Otključajte aplikaciju za uvoz: ključevi za šifrovanje ne mogu da se upotrebe dok je zaključana.',
        'archive_holds_one_message' => 'Ova datoteka je jedna poruka e-pošte, a ne arhiva prijemnog sandučeta, pa pročitana kao arhiva ne sadrži ništa. Otpremi je ponovo sa formatom Poruka e-pošte.',
        'email_file_is_an_archive' => 'Ova datoteka je arhiva prijemnog sandučeta: sadrži više od jedne poruke, a pročitana kao jedna poruka uzela bi samo prvu. Otpremi je ponovo sa formatom Arhiva prijemnog sandučeta.',
        'file_stopped_short' => 'Zaglavlje je odgovaralo, pa je format ispravan. Čitanje je stalo pre kraja fajla. To izaziva jedan nečitljiv red, kao i fajl prevelik za ovaj uređaj. Pokušaj sa kraćim periodom.',
        'file_unreadable' => 'Ovaj fajl nije bilo moguće pročitati.',
        'file_unreadable_detail' => 'Aplikacija nije mogla da pročita ovaj fajl (:code). Potpuni podaci nalaze se u dnevniku aplikacije; navedite ovaj kôd ako prijavljujete problem.',
        'iban_not_in_preview' => 'Ovaj IBAN nije deo trenutnog pregleda.',
        'not_an_email_file' => 'Ova datoteka nije ni poruka e-pošte ni arhiva prijemnog sandučeta, pa u njoj nema šta da se pročita kao potvrda. Izaberi vrstu uvoza i format koji odgovaraju tvojoj datoteci.',
        'pdf_has_no_text_layer' => 'Ovaj PDF ne sadrži tekst — to je skenirani izvod ili njegova fotografija, pa u njemu nema šta da se pročita. Preuzmi sam izvod iz svoje banke ili koristi CSV izvoz.',
        'pdf_password_protected' => 'Ovaj PDF je zaštićen lozinkom, pa ne može da ga otvori nijedan čitač. U svom PDF pregledaču sačuvaj nezaštićenu kopiju i uvezi nju.',
        'pdf_reader_unavailable' => 'Ova verzija aplikacije nema nikakav čitač PDF-a, pa PDF izvod ovde ne može da se otvori. Uvezi ovu datoteku na drugom uređaju ili radije koristi CSV izvoz iz banke.',
        'row_belongs_to_another_statement' => 'Ovaj red pripada transakciji u drugoj datoteci izvoda. Uvezite i taj izvod — dva se čitaju zajedno.',
        'row_unreadable' => 'Ovaj red nije bilo moguće pročitati.',
        'row_unreadable_detail' => 'Aplikacija nije mogla da pročita ovaj red (:code). Potpuni podaci nalaze se u dnevniku aplikacije; navedite ovaj kôd ako prijavljujete problem.',
        'unknown_account' => 'Ovaj red pripada računu kojem još nisi dao naziv.',
    ],

    'receipts' => [
        'heading' => 'Ovaj fajl je pročitan kao e-pošta',
        'saved' => 'Šta je sadržao, navedeno je ispod, a svaka poruka je sačuvana.',
        'none_imported' => 'Ništa od toga nije postalo transakcija, pa u tvoju glavnu knjigu nije dodato ništa.',
        'shown' => 'Prikazane poruke: :shown od :total',
        'no_subject' => 'Bez naslova',

        'state' => [
            'read' => 'Pročitano kao plaćanje — potvrdi ovaj uvoz da uđe u tvoju glavnu knjigu.',
            'not_a_payment' => 'Nije plaćanje. Ova poruka nešto najavljuje umesto da potvrdi plaćanje.',
            'unreadable' => 'Sačuvano. Aplikacija čita račune ovog pošiljaoca, ali u ovoj poruci nije našla iznos, trgovca ni referencu.',
            'unknown_sender' => 'Sačuvano. Aplikacija ne čita račune ovog pošiljaoca, pa iz poruke nije uzela ništa.',
        ],
    ],

    'failed' => [
        'heading' => 'Ovaj fajl nije bilo moguće pročitati',
        'no_rows' => 'U ovom fajlu nisu pronađene transakcije, pa nema šta da se uveze.',
        'nothing_read' => 'Ništa u ovom fajlu nije bilo moguće pročitati kao transakciju, pa nema šta da se uveze.',
        'every_row' => 'Nijedan red ovog fajla nije bilo moguće pročitati, pa nema šta da se uveze. Svaki red je naveden ispod sa razlogom.',
        'likely_cause' => 'Najčešće zaglavlje ne odgovara izvoru koji si izabrao. Proveri banku i format na ekranu za otpremanje ili ponovo preuzmi izvod iz svoje banke.',
        'truncated_heading' => 'Iz ovog fajla bilo je moguće pročitati samo deo',
        'truncated' => 'Čitanje je stalo na pola fajla. Ovaj fajl ne može da se uveze: čuvanje samo pročitanog dela ostavilo bi ostatak perioda da nedostaje, a ništa to ne bi označilo.',
        'truncated_action' => 'Ponovo otpremite fajl ili preuzmite novu kopiju izvoda iz svoje banke.',
        'some_rows' => 'Neke redove nije bilo moguće pročitati. Označeni su ispod i biće preskočeni; potvrdom se uvozi ostatak.',
        'detail_label' => 'Šta je parser prijavio:',
        'rows_read_label' => 'Pročitani redovi',
        'rows_skipped_label' => 'Preskočeni redovi',
    ],
];
