<?php

declare(strict_types=1);

return [
    'eyebrow' => 'Bekijken & vastleggen',
    'h1' => 'Bekijk alles wat we vonden',

    'lede_counts' => ':transactions over :sources.',
    'source' => ':count bron|:count bronnen',
    'lede_confirm' => 'Bevestig je beginsaldo’s en leg dan vast.',

    'empty' => 'Nog niets om te bekijken. Zet een afschrift neer bij de vorige stappen om je transacties hier te zien.',

    'sb_eyebrow_label' => '🧮 BEGINSALDO’S ·',
    'account_detected' => ':count REKENING GEDETECTEERD|:count REKENINGEN GEDETECTEERD',
    'sb_lede' => 'We hebben het beginsaldo voor elke rekening gedetecteerd. Bevestig of bewerk het voordat we vastleggen.',

    'txn' => ':count transactie|:count transacties',
    'to_commit' => 'om vast te leggen ·',
    'already_imported' => ':count al geïmporteerd|:count al geïmporteerd',
    'commit_committing' => 'Bezig met vastleggen…',
    'commit_count' => 'Leg alles vast (:count transactie) →|Leg alles vast (:count transacties) →',
    'commit_empty' => 'Leg alles vast (—) →',
    'skip' => 'Voorlopig overslaan',

    'errors' => [
        'nothing_to_commit' => 'Niets om vast te leggen.',
        'commit_failed' => 'We konden je afschriften niet vastleggen. Er is niets gewijzigd — probeer het opnieuw.',
    ],

    'section' => [
        'from_prefix' => 'VAN ',
        'from_bank' => 'VAN JE BANKAFSCHRIFT',
        'from_ics' => 'VAN JE ICS-KAARTAFSCHRIFTEN',
        'from_paypal' => 'VAN PAYPAL',
        'row' => ':count RIJ|:count RIJEN',
        'badge_ready' => '✓ KLAAR',
        'badge_empty' => 'LEEG',
        'badge_error' => 'OPNIEUW UPLOADEN NODIG',
        'error_body' => 'We konden niet alle bestanden voor deze bron lezen. Probeer een ander bestand →',
        'partial_body' => 'Een van deze bestanden kon niet volledig worden gelezen, dus is het helemaal weggelaten: :reason',
        'empty_body' => 'Dit afschrift is leeg.',
        'col_date' => 'Datum',
        'col_type' => 'Type',
        'col_counterparty' => 'Tegenpartij',
        'col_amount' => 'Bedrag',
        'load_more' => 'Meer laden (:remaining resterend)',
        'rows_shown' => ':count rij getoond|:count rijen getoond',
    ],
];
