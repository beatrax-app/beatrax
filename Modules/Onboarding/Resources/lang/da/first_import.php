<?php

declare(strict_types=1);

return [
    'eyebrow' => 'Gennemgå & bogfør',
    'h1' => 'Gennemgå alt, hvad vi fandt',

    'lede_counts' => ':transactions fra :sources.',
    'source' => ':count kilde|:count kilder',
    'lede_confirm' => 'Bekræft dine startsaldi, og bogfør derefter.',

    'empty' => 'Intet at gennemgå endnu. Slip et kontoudtog i de tidligere trin for at se dine transaktioner her.',

    'sb_eyebrow_label' => '🧮 STARTSALDI ·',
    'account_detected' => ':count KONTO FUNDET|:count KONTI FUNDET',
    'sb_lede' => 'Vi fandt startsaldoen for hver konto. Bekræft eller redigér, før vi bogfører.',

    'txn' => ':count transaktion|:count transaktioner',
    'to_commit' => 'at bogføre ·',
    'already_imported' => ':count allerede importeret|:count allerede importeret',
    'commit_committing' => 'Bogfører…',
    'commit_count' => 'Bogfør det hele (:count transaktion) →|Bogfør det hele (:count transaktioner) →',
    'commit_empty' => 'Bogfør det hele (—) →',
    'skip' => 'Spring over for nu',

    'errors' => [
        'nothing_to_commit' => 'Intet at bogføre.',
        'commit_failed' => 'Vi kunne ikke bogføre dine kontoudtog. Intet blev ændret — prøv igen.',
    ],

    'section' => [
        'from_prefix' => 'FRA ',
        'from_bank' => 'FRA DIT KONTOUDTOG',
        'from_ics' => 'FRA DINE ICS-KORTUDTOG',
        'from_paypal' => 'FRA PAYPAL',
        'row' => ':count RÆKKE|:count RÆKKER',
        'badge_ready' => '✓ KLAR',
        'badge_empty' => 'TOM',
        'badge_error' => 'SKAL UPLOADES IGEN',
        'error_body' => 'Vi kunne ikke læse alle filerne fra denne kilde. Prøv en anden fil →',
        'partial_body' => 'En af disse filer kunne ikke læses helt og blev derfor udeladt i sin helhed: :reason',
        'empty_body' => 'Dette kontoudtog er tomt.',
        'col_date' => 'Dato',
        'col_type' => 'Type',
        'col_counterparty' => 'Modpart',
        'col_amount' => 'Beløb',
        'load_more' => 'Indlæs flere (:remaining tilbage)',
        'rows_shown' => ':count række vist|:count rækker vist',
    ],
];
