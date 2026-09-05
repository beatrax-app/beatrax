<?php

declare(strict_types=1);

return [
    'eyebrow' => 'Pregled in potrditev',
    'h1' => 'Preglej vse, kar smo našli',

    'lede_counts' => ':transactions iz :sources.',
    'source' => ':count vira|:count virov|:count virov|:count virov',
    'lede_confirm' => 'Potrdi svoja začetna stanja, nato potrdi uvoz.',

    'empty' => 'Za pregled še ni ničesar. V prejšnjih korakih spusti izpisek, da tu vidiš svoje transakcije.',

    'sb_eyebrow_label' => '🧮 ZAČETNA STANJA ·',
    'account_detected' => ':count NAJDEN RAČUN|:count NAJDENA RAČUNA|:count NAJDENI RAČUNI|:count NAJDENIH RAČUNOV',
    'sb_lede' => 'Zaznali smo začetno stanje za vsak račun. Pred potrditvijo ga potrdi ali uredi.',

    'txn' => ':count transakcija|:count transakciji|:count transakcije|:count transakcij',
    'to_commit' => 'za potrditev ·',
    'already_imported' => ':count že uvoženo|:count že uvoženo|:count že uvoženo|:count že uvoženo',
    'commit_committing' => 'Potrjevanje…',
    'commit_count' => 'Potrdi vse (:count transakcija) →|Potrdi vse (:count transakciji) →|Potrdi vse (:count transakcije) →|Potrdi vse (:count transakcij) →',
    'commit_empty' => 'Potrdi vse (—) →',
    'skip' => 'Zaenkrat preskoči',

    'errors' => [
        'nothing_to_commit' => 'Ni ničesar za potrditev.',
        'commit_failed' => 'Tvojih izpiskov nismo mogli potrditi. Nič ni bilo spremenjeno — poskusi znova.',
    ],

    'section' => [
        'from_prefix' => 'IZ ',
        'from_bank' => 'S TVOJEGA BANČNEGA IZPISKA',
        'from_ics' => 'S TVOJIH IZPISKOV KARTICE ICS',
        'from_paypal' => 'IZ PAYPALA',
        'row' => ':count VRSTICA|:count VRSTICI|:count VRSTICE|:count VRSTIC',
        'badge_ready' => '✓ PRIPRAVLJENO',
        'badge_empty' => 'PRAZNO',
        'badge_error' => 'POTREBEN PONOVEN NALOG',
        'error_body' => 'Nismo mogli prebrati vseh datotek za ta vir. Poskusi z drugo datoteko →',
        'left_out' => 'Ena datoteka tukaj je bila izpuščena, zato bo shranjeno samo ostalo: :reason|:count datoteki tukaj sta bili izpuščeni, zato bo shranjeno samo ostalo: :reason|:count datoteke tukaj so bile izpuščene, zato bo shranjeno samo ostalo: :reason|:count datotek tukaj je bilo izpuščenih, zato bo shranjeno samo ostalo: :reason',
        'rows_skipped' => 'Nekaterih vrstic tukaj ni bilo mogoče prebrati in bodo izpuščene: :reason',
        'empty_body' => 'Ta izpisek je prazen.',
        'col_date' => 'Datum',
        'col_type' => 'Vrsta',
        'col_counterparty' => 'Nasprotna stranka',
        'col_amount' => 'Znesek',
        'load_more' => 'Naloži več (preostalo :remaining)',
        'rows_shown' => 'prikazana :count vrstica|prikazani :count vrstici|prikazane :count vrstice|prikazanih :count vrstic',
    ],
];
