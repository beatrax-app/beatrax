<?php

declare(strict_types=1);

return [
    'eyebrow' => 'Gennemgå & bogfør',
    'h1' => 'Gennemgå alt, hvad vi fandt',

    'lede_across' => 'transaktioner fra',
    'source' => 'kilde|kilder',
    'lede_confirm' => 'Bekræft dine startsaldi, og bogfør derefter.',

    'empty' => 'Intet at gennemgå endnu. Slip et kontoudtog i de tidligere trin for at se dine transaktioner her.',

    'sb_eyebrow_label' => '🧮 STARTSALDI ·',
    'account_detected' => 'KONTO FUNDET|KONTI FUNDET',
    'sb_lede' => 'Vi fandt startsaldoen for hver konto. Bekræft eller redigér, før vi bogfører.',

    'txn' => 'transaktion|transaktioner',
    'to_commit' => 'at bogføre ·',
    'already_imported' => 'allerede importeret',
    'commit_committing' => 'Bogfører…',
    'commit_count' => 'Bogfør det hele (:count transaktioner) →',
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
        'row' => 'RÆKKE|RÆKKER',
        'badge_ready' => '✓ KLAR',
        'badge_empty' => 'TOM',
        'badge_error' => 'SKAL UPLOADES IGEN',
        'badge_filtered' => 'ALLEREDE IMPORTERET',
        'error_body' => 'Vi kunne ikke læse alle filerne fra denne kilde. Prøv en anden fil →',
        'empty_body' => 'Dette kontoudtog er tomt.',
        'filtered_body' => 'Dette kontoudtog er allerede importeret et andet sted — vi har udeladt det.',
        'col_date' => 'Dato',
        'col_type' => 'Type',
        'col_counterparty' => 'Modpart',
        'col_amount' => 'Beløb',
        'load_more' => 'Indlæs flere (:remaining tilbage)',
        'rows_shown' => ':count rækker vist',
    ],
];
