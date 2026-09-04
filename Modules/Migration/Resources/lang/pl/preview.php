<?php

declare(strict_types=1);

return [
    'page_title' => 'Podgląd importu',

    'heading' => 'Podgląd importu',
    'subtitle' => 'Sprawdź, co się zmieni. Nic nie zostanie zapisane, dopóki nie potwierdzisz.',

    'stats' => [
        'category' => 'Kategorie',
        'account' => 'Konta',
        'payee' => 'Kontrahenci',
        'transaction' => 'Transakcje',
        'budget' => 'Miesiące budżetowe',
    ],

    'all_clean' => 'Wszystko zmapowane bez zastrzeżeń — nie ma tu nic do rozstrzygnięcia.',

    'nothing_staged' => 'Ten eksport nie zawierał niczego do zaimportowania — nie ma tu czego potwierdzać.',

    // i18n-review: pl · discarded — Polish marks gender in the past tense, so
    // the reader's own act is written impersonally as "odrzucono" where all 25
    // sibling locales name the reader. Confirm it still reads as their doing.
    'discarded' => 'Ten import odrzucono, więc w podglądzie nie ma już czego pokazywać.',
    'discarded_link' => 'Rozpocznij nowy import',

    'groups' => [
        'conflict' => 'Wymaga Twojej decyzji',
        'extra' => 'Nieimportowane',
    ],

    'keep_or_take_aria' => 'Zachowaj lokalne lub przyjmij źródłowe — :label',
    'keep_local' => 'Zachowaj lokalne',
    'take_source' => 'Przyjmij źródłowe',

    'footer_note' => 'Spowoduje to utworzenie lub aktualizację pokazanych wyżej pozycji w Twoich kategoriach, budżetach i księdze.',
    'discard_button' => 'Odrzuć import',
    'discard_confirm' => 'Odrzucić ten import? Wszystko, co odczytano z Twojego pliku eksportu, zostanie tu usunięte, a odzyskanie tego oznacza ponowne wgranie i przetworzenie całego pliku. Do księgi nic jeszcze nie trafiło.',
    'confirm_button' => 'Potwierdź import',
];
