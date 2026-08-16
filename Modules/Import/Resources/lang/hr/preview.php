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
        'iban_not_in_preview' => 'Ovaj IBAN nije dio trenutnog pregleda.',
    ],
];
