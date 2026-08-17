<?php

declare(strict_types=1);

return [
    'eyebrow' => 'Gjennomgå & bokfør',
    'h1' => 'Gjennomgå alt vi fant',

    'lede_across' => 'transaksjoner fra',
    'source' => 'kilde|kilder',
    'lede_confirm' => 'Bekreft de inngående saldoene dine, og bokfør deretter.',

    'empty' => 'Ingenting å gjennomgå ennå. Slipp en kontoutskrift i de tidligere trinnene for å se transaksjonene dine her.',

    'sb_eyebrow_label' => '🧮 INNGÅENDE SALDOER ·',
    'account_detected' => 'KONTO FUNNET|KONTOER FUNNET',
    'sb_lede' => 'Vi fant den inngående saldoen for hver konto. Bekreft eller rediger før vi bokfører.',

    'txn' => 'transaksjon|transaksjoner',
    'to_commit' => 'å bokføre ·',
    'already_imported' => 'allerede importert',
    'commit_committing' => 'Bokfører…',
    'commit_count' => 'Bokfør alt (:count transaksjoner) →',
    'commit_empty' => 'Bokfør alt (—) →',
    'skip' => 'Hopp over for nå',

    'errors' => [
        'nothing_to_commit' => 'Ingenting å bokføre.',
        'commit_failed' => 'Vi kunne ikke bokføre kontoutskriftene dine. Ingenting ble endret — prøv igjen.',
    ],

    'section' => [
        'from_prefix' => 'FRA ',
        'from_bank' => 'FRA KONTOUTSKRIFTEN DIN',
        'from_ics' => 'FRA ICS-KORTUTSKRIFTENE DINE',
        'from_paypal' => 'FRA PAYPAL',
        'row' => 'RAD|RADER',
        'badge_ready' => '✓ KLAR',
        'badge_empty' => 'TOM',
        'badge_error' => 'MÅ LASTES OPP PÅ NYTT',
        'badge_filtered' => 'ALLEREDE IMPORTERT',
        'error_body' => 'Vi kunne ikke lese alle filene fra denne kilden. Prøv en annen fil →',
        'empty_body' => 'Denne kontoutskriften er tom.',
        'filtered_body' => 'Denne kontoutskriften er allerede importert et annet sted — vi utelot den.',
        'col_date' => 'Dato',
        'col_type' => 'Type',
        'col_counterparty' => 'Motpart',
        'col_amount' => 'Beløp',
        'load_more' => 'Last inn flere (:remaining igjen)',
        'rows_shown' => ':count rader vises',
    ],
];
