<?php

declare(strict_types=1);

return [
    'page_title' => 'Księga gotówkowa',
    'heading' => 'Księga gotówkowa',
    'intro' => 'Rejestruj ręcznie gotówkę i inne wydatki poza bankiem. Wpisy ręczne trafiają do tej samej księgi co importy — są kategoryzowane, brane pod uwagę przy wykrywaniu cykliczności i wliczane do bieżącego miesiąca.',

    'direction' => 'Kierunek',
    'expense' => 'Wydatek',
    'income' => 'Przychód',

    'amount' => 'Kwota (€)',
    'date' => 'Data',
    'counterparty' => 'Kontrahent',
    'counterparty_placeholder' => 'np. Piekarnia',
    'category' => 'Kategoria',
    'optional' => '(opcjonalnie)',
    'uncategorized' => 'Bez kategorii',
    'note' => 'Notatka',

    'add_entry' => 'Dodaj wpis',
    'manual_entries' => 'Wpisy ręczne',
    'no_entries' => 'Brak wpisów ręcznych.',
    'delete_entry' => 'Usuń wpis',
    'delete' => 'Usuń',
    'delete_confirm' => 'Usunąć ten wpis?',
    'delete_keep' => 'Zachowaj',

    'errors' => [
        'amount_positive' => 'Podaj kwotę większą od zera.',
        'amount_too_large' => 'Ta kwota jest za duża. Sprawdź cyfry.',
        'amount_unreadable' => 'Nie udało się odczytać tej kwoty. Wpisz ją bez separatora tysięcy i z najwyżej dwoma miejscami po przecinku, na przykład :example.',
        'invalid_date' => 'Podaj prawidłową datę.',
    ],

    'toast' => [
        'added' => 'Dodano wpis gotówkowy.',
        'removed' => 'Usunięto wpis gotówkowy.',
    ],
];
