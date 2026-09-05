<?php

declare(strict_types=1);

return [
    'eyebrow' => 'Vaata üle ja kinnita',
    'h1' => 'Vaata üle kõik, mille leidsime',

    'lede_counts' => ':transactions :sources.',
    'source' => ':count allikast|:count allikast',
    'lede_confirm' => 'Kinnita oma algsaldod ja seejärel kinnita kõik.',

    'empty' => 'Veel pole midagi üle vaadata. Lohista eelmistes sammudes väljavõte, et näha siin oma tehinguid.',

    'sb_eyebrow_label' => '🧮 ALGSALDOD ·',
    'account_detected' => ':count TUVASTATUD KONTO|:count TUVASTATUD KONTOT',
    'sb_lede' => 'Tuvastasime iga konto algsaldo. Kinnita või muuda see enne, kui kõik kinnitame.',

    'txn' => ':count tehing|:count tehingut',
    'to_commit' => 'kinnitamiseks ·',
    'already_imported' => ':count juba imporditud|:count juba imporditud',
    'commit_committing' => 'Kinnitan…',
    'commit_count' => 'Kinnita kõik (:count tehing) →|Kinnita kõik (:count tehingut) →',
    'commit_empty' => 'Kinnita kõik (—) →',
    'skip' => 'Jäta praegu vahele',

    'errors' => [
        'nothing_to_commit' => 'Pole midagi kinnitada.',
        'commit_failed' => 'Me ei suutnud sinu väljavõtteid kinnitada. Midagi ei muudetud — proovi uuesti.',
    ],

    'section' => [
        'from_prefix' => 'ALLIKAST ',
        'from_bank' => 'SINU KONTOVÄLJAVÕTTEST',
        'from_ics' => 'SINU ICS KAARDIVÄLJAVÕTETEST',
        'from_paypal' => 'PAYPALIST',
        'row' => ':count RIDA|:count RIDA',
        'badge_ready' => '✓ VALMIS',
        'badge_empty' => 'TÜHI',
        'badge_error' => 'VAJAB UUESTI ÜLESLAADIMIST',
        'error_body' => 'Me ei suutnud lugeda kõiki selle allika faile. Proovi teist faili →',
        'partial_body' => 'Üht neist failidest ei õnnestunud tervikuna lugeda, seega jäeti see täielikult välja: :reason',
        'empty_body' => 'See väljavõte on tühi.',
        'col_date' => 'Kuupäev',
        'col_type' => 'Tüüp',
        'col_counterparty' => 'Vastaspool',
        'col_amount' => 'Summa',
        'load_more' => 'Laadi veel (jäänud :remaining)',
        'rows_shown' => 'kuvatud :count rida|kuvatud :count rida',
    ],
];
