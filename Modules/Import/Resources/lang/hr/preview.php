<?php

declare(strict_types=1);

return [
    'page_title' => 'Pregled uvoza',
    'heading' => 'Pregled uvoza',
    'discard' => 'Odbaci uvoz',
    'confirm' => 'Potvrdi uvoz',
    'subtitle' => 'Pregledaj obrađene retke. Ništa se ne sprema u tvoju glavnu knjigu dok ne potvrdiš.',

    'expired_html' => 'Pregled je istekao. <a href="/imports/new" class="underline">Ponovno učitaj datoteku</a> za novi pokušaj.',

    'save_name' => 'Spremi naziv',
    'account_name_label' => 'Naziv računa',
    'account_placeholder' => 'npr. Glavni štedni račun',
    'rename_aria' => 'Preimenuj ovu protustranku',

    'unknown_iban_prefix' => 'Pronašli smo nepoznat IBAN:',
    'unknown_iban_suffix' => 'Imenuj ovaj račun.',

    'ics' => [
        'heading' => 'Imenuj svoj ICS kartični račun.',
        'help' => 'Ovo je prvi put da uvoziš ICS podatke. Daj ovoj kartici naziv kako bi se dosljedno prikazivala u cijeloj aplikaciji.',
        'placeholder' => 'npr. ICS kartica',
    ],

    'paypal' => [
        'heading' => 'Imenuj svoj PayPal račun.',
        'help' => 'Ovo je prvi put da uvoziš PayPal podatke. Daj ovom novčaniku naziv kako bi se dosljedno prikazivao u cijeloj aplikaciji.',
        'placeholder' => 'npr. PayPal',
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

    'chain' => [
        'heading' => 'Rješavanje lanaca…',
        'pending' => 'U redu čekanja. Rješavanje lanaca uskoro počinje.',
        'running' => 'Povezivanje lanaca financiranja i razlaganje namira s izvoda.',
        'failed_prefix' => 'Rješavanje lanaca nije uspjelo:',
        'unknown_error' => 'došlo je do nepoznate pogreške',
        'open_horizon' => 'Otvori Horizon',
        'failed_suffix' => 'za ponovni pokušaj ili pregled.',
    ],

    'errors' => [
        'app_locked' => 'Otključajte aplikaciju za uvoz: ključ trgovca ne može se izračunati dok je zaključana.',
        'file_unreadable' => 'Ovu datoteku nije bilo moguće pročitati.',
        'iban_not_in_preview' => 'Ovaj IBAN nije dio trenutnog pregleda.',
        'row_unreadable' => 'Ovaj redak nije bilo moguće pročitati.',
        'unknown_account' => 'Ovaj redak pripada računu kojem još nisi dao naziv.',
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
