<?php

declare(strict_types=1);

return [
    'eyebrow' => 'Pregled in potrditev',
    'h1' => 'Preglej vse, kar smo našli',

    'lede_across' => 'transakcij iz',
    'source' => 'vir|vira|viri|virov',
    'lede_confirm' => 'Potrdi svoja začetna stanja, nato potrdi uvoz.',

    'empty' => 'Za pregled še ni ničesar. V prejšnjih korakih spusti izpisek, da tu vidiš svoje transakcije.',

    'sb_eyebrow_label' => '🧮 ZAČETNA STANJA ·',
    'account_detected' => 'NAJDEN RAČUN|NAJDENA RAČUNA|NAJDENI RAČUNI|NAJDENIH RAČUNOV',
    'sb_lede' => 'Zaznali smo začetno stanje za vsak račun. Pred potrditvijo ga potrdi ali uredi.',

    'txn' => 'transakcija|transakciji|transakcije|transakcij',
    'to_commit' => 'za potrditev ·',
    'already_imported' => 'že uvoženo',
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
        'row' => 'VRSTICA|VRSTICI|VRSTICE|VRSTIC',
        'badge_ready' => '✓ PRIPRAVLJENO',
        'badge_empty' => 'PRAZNO',
        'badge_error' => 'POTREBEN PONOVEN NALOG',
        'error_body' => 'Nismo mogli prebrati vseh datotek za ta vir. Poskusi z drugo datoteko →',
        'partial_body' => 'Dela te datoteke ni bilo mogoče prebrati in je bil izpuščen: :reason',
        'empty_body' => 'Ta izpisek je prazen.',
        'col_date' => 'Datum',
        'col_type' => 'Vrsta',
        'col_counterparty' => 'Nasprotna stranka',
        'col_amount' => 'Znesek',
        'load_more' => 'Naloži več (preostalo :remaining)',
        'rows_shown' => 'prikazana :count vrstica|prikazani :count vrstici|prikazane :count vrstice|prikazanih :count vrstic',
    ],
];
