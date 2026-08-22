<?php

declare(strict_types=1);

return [
    'eyebrow' => 'Przegląd i zatwierdzenie',
    'h1' => 'Przejrzyj wszystko, co znaleźliśmy',

    'lede_counts' => ':transactions z :sources.',
    'source' => ':count źródła|:count źródeł|:count źródeł',
    'lede_confirm' => 'Potwierdź salda początkowe, a potem zatwierdź.',

    'empty' => 'Nie ma jeszcze nic do przejrzenia. Upuść wyciąg we wcześniejszych krokach, aby zobaczyć tu swoje transakcje.',

    'sb_eyebrow_label' => '🧮 SALDA POCZĄTKOWE ·',
    'account_detected' => ':count WYKRYTE KONTO|:count WYKRYTE KONTA|:count WYKRYTYCH KONT',
    'sb_lede' => 'Wykryliśmy saldo początkowe dla każdego konta. Potwierdź je lub popraw, zanim zatwierdzimy.',

    'txn' => ':count transakcja|:count transakcje|:count transakcji',
    'to_commit' => 'do zatwierdzenia ·',
    'already_imported' => ':count już zaimportowano|:count już zaimportowano|:count już zaimportowano',
    'commit_committing' => 'Zatwierdzanie…',
    'commit_count' => 'Zatwierdź wszystko (:count transakcja) →|Zatwierdź wszystko (:count transakcje) →|Zatwierdź wszystko (:count transakcji) →',
    'commit_empty' => 'Zatwierdź wszystko (—) →',
    'skip' => 'Pomiń na razie',

    'errors' => [
        'nothing_to_commit' => 'Nie ma czego zatwierdzić.',
        'commit_failed' => 'Nie udało się zatwierdzić Twoich wyciągów. Nic nie zostało zmienione — spróbuj ponownie.',
    ],

    'section' => [
        'from_prefix' => 'Z ',
        'from_bank' => 'Z TWOJEGO WYCIĄGU BANKOWEGO',
        'from_ics' => 'Z TWOICH WYCIĄGÓW KARTY ICS',
        'from_paypal' => 'Z PAYPAL',
        'row' => ':count WIERSZ|:count WIERSZE|:count WIERSZY',
        'badge_ready' => '✓ GOTOWE',
        'badge_empty' => 'PUSTE',
        'badge_error' => 'WYMAGA PONOWNEGO WGRANIA',
        'error_body' => 'Nie udało się odczytać wszystkich plików z tego źródła. Spróbuj innego pliku →',
        'partial_body' => 'Części tego pliku nie udało się odczytać i została pominięta: :reason',
        'empty_body' => 'Ten wyciąg jest pusty.',
        'col_date' => 'Data',
        'col_type' => 'Typ',
        'col_counterparty' => 'Kontrahent',
        'col_amount' => 'Kwota',
        'load_more' => 'Wczytaj więcej (pozostało: :remaining)',
        'rows_shown' => 'pokazany :count wiersz|pokazane :count wiersze|pokazanych :count wierszy',
    ],
];
