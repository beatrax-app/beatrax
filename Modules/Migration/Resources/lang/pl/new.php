<?php

declare(strict_types=1);

return [
    'page_title' => 'Import z YNAB / Actual',

    'eyebrow' => 'Migracje',
    'heading' => 'Import z YNAB / Actual',
    'intro' => 'Przenieś drzewo kategorii, historię budżetu i transakcje z YNAB4, nowego YNAB albo Actual Budget. Nic nie trafia do księgi, dopóki nie przejrzysz i nie potwierdzisz danych.',
    'reconcile_context' => 'Sprawdzanie aktualizacji względem ostatniego importu — :product.',

    'source_label' => 'Źródło',
    'file_label' => 'Plik',
    'parse_button' => 'Przetwórz eksport',

    'hints' => [
        'ynab4' => 'Wyeksportuj cały budżet jako plik ZIP z menu File → Export w YNAB4.',
        'nynab' => 'Wyeksportuj budżet z nYNAB przez File → Export Budget, a potem spakuj wyeksportowane pliki CSV do archiwum ZIP.',
        'actual' => 'Wyeksportuj budżet jako plik ZIP z Settings → Export data w Actual Budget.',
    ],

    'errors' => [
        'unrecognised' => 'To nie wygląda na eksport YNAB4, nYNAB ani Actual, który da się odczytać. Sprawdź plik i spróbuj ponownie.',
        'file_too_large' => 'Ten plik jest za duży jak na eksport migracyjny.',
    ],
];
