<?php

declare(strict_types=1);

return [
    'eyebrow' => 'Przegląd i zatwierdzenie',
    'h1' => 'Przejrzyj wszystko, co znaleźliśmy',

    'lede_across' => 'transakcji z',
    'source' => 'źródła|źródeł|źródeł',
    'lede_confirm' => 'Potwierdź salda początkowe, a potem zatwierdź.',

    'empty' => 'Nie ma jeszcze nic do przejrzenia. Upuść wyciąg we wcześniejszych krokach, aby zobaczyć tu swoje transakcje.',

    'sb_eyebrow_label' => '🧮 SALDA POCZĄTKOWE ·',
    'account_detected' => 'WYKRYTE KONTO|WYKRYTE KONTA|WYKRYTYCH KONT',
    'sb_lede' => 'Wykryliśmy saldo początkowe dla każdego konta. Potwierdź je lub popraw, zanim zatwierdzimy.',

    'txn' => 'transakcja|transakcje|transakcji',
    'to_commit' => 'do zatwierdzenia ·',
    'already_imported' => 'już zaimportowano',
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
        'row' => 'WIERSZ|WIERSZE|WIERSZY',
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
