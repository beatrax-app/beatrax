<?php

declare(strict_types=1);

return [
    'eyebrow' => 'Gjennomgå & bokfør',
    'h1' => 'Gjennomgå alt vi fant',

    'lede_counts' => ':transactions fra :sources.',
    'source' => ':count kilde|:count kilder',
    'lede_confirm' => 'Bekreft de inngående saldoene dine, og bokfør deretter.',

    'empty' => 'Ingenting å gjennomgå ennå. Slipp en kontoutskrift i de tidligere trinnene for å se transaksjonene dine her.',

    'sb_eyebrow_label' => '🧮 INNGÅENDE SALDOER ·',
    'account_detected' => ':count KONTO FUNNET|:count KONTOER FUNNET',
    'sb_lede' => 'Vi fant den inngående saldoen for hver konto. Bekreft eller rediger før vi bokfører.',

    'txn' => ':count transaksjon|:count transaksjoner',
    'to_commit' => 'å bokføre ·',
    'already_imported' => ':count allerede importert|:count allerede importert',
    'commit_committing' => 'Bokfører…',
    'commit_count' => 'Bokfør alt (:count transaksjon) →|Bokfør alt (:count transaksjoner) →',
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
        'row' => ':count RAD|:count RADER',
        'badge_ready' => '✓ KLAR',
        'badge_empty' => 'TOM',
        'badge_error' => 'MÅ LASTES OPP PÅ NYTT',
        'error_body' => 'Vi kunne ikke lese alle filene fra denne kilden. Prøv en annen fil →',
        'left_out' => 'Én fil her ble utelatt, så bare resten blir lagret: :reason|:count filer her ble utelatt, så bare resten blir lagret: :reason',
        'rows_skipped' => 'Noen rader her kunne ikke leses og blir hoppet over: :reason',
        'empty_body' => 'Denne kontoutskriften er tom.',
        'col_date' => 'Dato',
        'col_type' => 'Type',
        'col_counterparty' => 'Motpart',
        'col_amount' => 'Beløp',
        'load_more' => 'Last inn flere (:remaining igjen)',
        'rows_shown' => ':count rad vises|:count rader vises',
    ],
];
