<?php

declare(strict_types=1);

return [
    'page_title' => 'Pregled uvoza',
    'heading' => 'Pregled uvoza',
    'discard' => 'Odbaci uvoz',
    'confirm' => 'Potvrdi uvoz',
    'subtitle' => 'Pregledaj obrađene redove. Ništa se ne čuva u tvojoj glavnoj knjizi dok ne potvrdiš.',

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
        'unknown_error' => 'došlo je do nepoznate greške',
        'open_horizon' => 'Otvori Horizon',
        'failed_suffix' => 'za ponovni pokušaj ili pregled.',
    ],

    'errors' => [
        'iban_not_in_preview' => 'Ovaj IBAN nije deo trenutnog pregleda.',
    ],
];
