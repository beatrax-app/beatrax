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

    'save_name' => 'Sačuvaj naziv',
    'account_name_label' => 'Naziv računa',
    'account_placeholder' => 'npr. Glavni štedni račun',
    'rename_aria' => 'Preimenuj ovu drugu stranu',

    'unknown_iban_prefix' => 'Pronašli smo nepoznat IBAN:',
    'unknown_iban_suffix' => 'Imenuj ovaj račun.',

    'ics' => [
        'heading' => 'Imenuj svoj ICS kartični račun.',
        'help' => 'Ovo je prvi put da uvoziš ICS podatke. Daj ovoj kartici naziv kako bi se dosledno prikazivala u celoj aplikaciji.',
        'placeholder' => 'npr. ICS kartica',
    ],

    'paypal' => [
        'heading' => 'Imenuj svoj PayPal račun.',
        'help' => 'Ovo je prvi put da uvoziš PayPal podatke. Daj ovom novčaniku naziv kako bi se dosledno prikazivao u celoj aplikaciji.',
        'placeholder' => 'npr. PayPal',
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

    'chain' => [
        'heading' => 'Razrešavanje lanaca…',
        'pending' => 'U redu čekanja. Razrešavanje lanaca uskoro počinje.',
        'running' => 'Povezivanje lanaca finansiranja i razlaganje poravnanja sa izvoda.',
        'failed_prefix' => 'Razrešavanje lanaca nije uspelo:',
        'failed_detail' => 'detalji su u dnevniku poslova',
        'open_horizon' => 'Otvori Horizon',
        'failed_suffix' => 'za ponovni pokušaj ili pregled.',
    ],

    'errors' => [
        'app_locked' => 'Otključajte aplikaciju za uvoz: ključevi za šifrovanje ne mogu da se upotrebe dok je zaključana.',
        'file_unreadable' => 'Ovaj fajl nije bilo moguće pročitati.',
        'iban_not_in_preview' => 'Ovaj IBAN nije deo trenutnog pregleda.',
        'pdf_reader_unavailable' => 'PDF izvodi zahtevaju program pdftotext, koji ovde nije instaliran. Uvezi ovu datoteku na računaru koji ga ima ili radije koristi CSV izvoz iz banke.',
        'row_unreadable' => 'Ovaj red nije bilo moguće pročitati.',
        'unknown_account' => 'Ovaj red pripada računu kojem još nisi dao naziv.',
    ],

    'failed' => [
        'heading' => 'Ovaj fajl nije bilo moguće pročitati',
        'no_rows' => 'U ovom fajlu nisu pronađene transakcije, pa nema šta da se uveze.',
        'nothing_read' => 'Ništa u ovom fajlu nije bilo moguće pročitati kao transakciju, pa nema šta da se uveze.',
        'every_row' => 'Nijedan red ovog fajla nije bilo moguće pročitati, pa nema šta da se uveze. Svaki red je naveden ispod sa razlogom.',
        'likely_cause' => 'Najčešće zaglavlje ne odgovara izvoru koji si izabrao. Proveri banku i format na ekranu za otpremanje ili ponovo preuzmi izvod iz svoje banke.',
        'truncated_heading' => 'Iz ovog fajla bilo je moguće pročitati samo deo',
        'truncated' => 'Čitanje je stalo na pola fajla. Sve posle te tačke nije pročitano i neće biti uvezeno.',
        'some_rows' => 'Neke redove nije bilo moguće pročitati. Označeni su ispod i biće preskočeni; potvrdom se uvozi ostatak.',
        'detail_label' => 'Šta je parser prijavio:',
        'rows_read_label' => 'Pročitani redovi',
        'rows_skipped_label' => 'Preskočeni redovi',
    ],
];
