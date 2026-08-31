<?php

declare(strict_types=1);

return [
    'page_title' => 'Księga gotówkowa',
    'heading' => 'Księga gotówkowa',
    'intro' => 'Rejestruj ręcznie gotówkę i inne wydatki poza bankiem. Wpisy ręczne trafiają do tej samej księgi co importy — są kategoryzowane, wiązane z kontrahentem, brane pod uwagę przy wykrywaniu cykliczności i wliczane do bieżącego miesiąca.',

    'direction' => 'Kierunek',
    'expense' => 'Wydatek',
    'income' => 'Przychód',

    'amount' => 'Kwota (:symbol)',
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
    'delete_entry_caption' => 'Usuń',
    'delete' => 'Usuń',
    'delete_confirm' => 'Usunąć ten wpis?',
    'delete_keep' => 'Zachowaj',

    'errors' => [
        'amount_positive' => 'Podaj kwotę większą od zera.',
        'amount_too_large' => 'Ta kwota jest za duża. Sprawdź cyfry.',
        'amount_unreadable' => 'Nie udało się odczytać kwoty. Podaj ją z maksymalnie :decimals miejscem po przecinku, na przykład :example.|Nie udało się odczytać kwoty. Podaj ją z maksymalnie :decimals miejscami po przecinku, na przykład :example.|Nie udało się odczytać kwoty. Podaj ją z maksymalnie :decimals miejscami po przecinku, na przykład :example.',
        'amount_unreadable_whole' => 'Nie udało się odczytać kwoty. Ta waluta nie ma części dziesiętnych, więc podaj liczbę całkowitą, na przykład :example.',
        'invalid_date' => 'Podaj prawidłową datę.',
        'not_recorded' => 'Wpis nie został zapisany. Spróbuj dodać go ponownie.',
    ],

    'toast' => [
        'added' => 'Dodano wpis gotówkowy.',
        'removed' => 'Usunięto wpis gotówkowy.',
        'reconciled_locked' => 'Ta transakcja jest uzgodniona. Cofnij uzgodnienie, aby wprowadzić zmiany.',
    ],
];
