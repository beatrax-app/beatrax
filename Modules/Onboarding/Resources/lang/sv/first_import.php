<?php

declare(strict_types=1);

return [
    'eyebrow' => 'Granska & bokför',
    'h1' => 'Granska allt vi hittade',

    'lede_across' => 'transaktioner från',
    'source' => 'källa|källor',
    'lede_confirm' => 'Bekräfta dina ingående saldon och bokför sedan.',

    'empty' => 'Inget att granska ännu. Släpp ett kontoutdrag i de tidigare stegen för att se dina transaktioner här.',

    'sb_eyebrow_label' => '🧮 INGÅENDE SALDON ·',
    'account_detected' => 'KONTO HITTAT|KONTON HITTADE',
    'sb_lede' => 'Vi hittade det ingående saldot för varje konto. Bekräfta eller redigera innan vi bokför.',

    'txn' => 'transaktion|transaktioner',
    'to_commit' => 'att bokföra ·',
    'already_imported' => 'redan importerade',
    'commit_committing' => 'Bokför…',
    'commit_count' => 'Bokför allt (:count transaktion) →|Bokför allt (:count transaktioner) →',
    'commit_empty' => 'Bokför allt (—) →',
    'skip' => 'Hoppa över tills vidare',

    'errors' => [
        'nothing_to_commit' => 'Inget att bokföra.',
        'commit_failed' => 'Vi kunde inte bokföra dina kontoutdrag. Inget ändrades — försök igen.',
    ],

    'section' => [
        'from_prefix' => 'FRÅN ',
        'from_bank' => 'FRÅN DITT KONTOUTDRAG',
        'from_ics' => 'FRÅN DINA ICS-KORTUTDRAG',
        'from_paypal' => 'FRÅN PAYPAL',
        'row' => 'RAD|RADER',
        'badge_ready' => '✓ KLAR',
        'badge_empty' => 'TOM',
        'badge_error' => 'MÅSTE LADDAS UPP IGEN',
        'error_body' => 'Vi kunde inte läsa alla filer för den här källan. Testa en annan fil →',
        'partial_body' => 'En del av filen gick inte att läsa och utelämnades: :reason',
        'empty_body' => 'Det här kontoutdraget är tomt.',
        'col_date' => 'Datum',
        'col_type' => 'Typ',
        'col_counterparty' => 'Motpart',
        'col_amount' => 'Belopp',
        'load_more' => 'Ladda fler (:remaining kvar)',
        'rows_shown' => ':count rad visas|:count rader visas',
    ],
];
