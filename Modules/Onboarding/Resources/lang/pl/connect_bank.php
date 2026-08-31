<?php

declare(strict_types=1);

return [
    'eyebrow' => 'Twój bank',
    'h1' => 'Pobierz wyciąg i upuść go poniżej',
    'lede' => 'Wybierz format, w jakim bank udostępnił wyciąg, i upuść plik. CAMT.053 i MT940 wykrywamy automatycznie.',

    'format_group_aria' => 'Format wyciągu bankowego',
    'got_it_as' => 'Mam go jako:',
    'badge_recommended' => 'zalecane',

    'mini' => [
        'login_label' => 'Zaloguj się',
        'login_sub' => 'Strona Twojego banku',
        'statements_label' => 'Otwórz wyciągi',
        'statements_sub' => 'W menu banku',
        'range_label' => 'Wybierz zakres',
        'range_sub' => 'Ostatnie 90 dni',
        'download_label' => 'Pobierz',
    ],

    'csv_picker_aria' => 'Który bank wyeksportował Twój plik CSV?',
    'csv_picker_from' => 'Z banku:',

    'drop_lead_camt053' => 'Upuść tutaj plik CAMT.053',
    'drop_lead_mt940' => 'Upuść tutaj plik MT940',
    'drop_lead_csv_layout' => 'Upuść tutaj plik CSV z :layout',
    'drop_lead_pick_bank' => 'Wybierz bank, który wyeksportował Twój plik CSV — bez tego nie odczytamy go poprawnie.',
    'drop_lead_default' => 'Upuść tutaj plik wyciągu',
    'browse_file' => 'lub wybierz plik z dysku',

    'format_help_camt053' => 'CAMT.053 to wyciąg w formacie XML — poszukaj go w bankowości internetowej wśród wyciągów lub pobrań.',
    'format_help_mt940' => 'MT940 to wyciąg w zwykłym tekście, oferowany jako .sta lub .940 obok plików XML i CSV.',
    'format_help_csv' => 'CSV to eksport do arkusza. Każdy bank inaczej układa kolumny, więc wybierz pasujący układ. Jeśli twojego nie ma na liście, poproś bank o CAMT.053 lub MT940.',

    'account_name_default' => 'Rachunek bankowy',
    'account_name_layout' => 'Rachunek :layout',

    'file_ready' => '· ✓ gotowe',

    'skip' => 'Pomiń ten krok',
    'continue' => 'Dalej →',

    'errors' => [
        'file_required' => 'Najpierw upuść plik wyciągu w polu powyżej.',
        'file_max' => 'Ten plik jest za duży. Upuść wyciąg mniejszy niż 10 MB.',
        'file_extensions' => 'Ten plik nie wygląda na wyciąg bankowy. Upuść plik CAMT.053 XML, CSV lub MT940.',
        'pick_bank' => 'Zanim przejdziesz dalej, wybierz bank, który wyeksportował Twój plik CSV.',
        'unreadable' => 'Nie udało się odczytać tego pliku. Pełny błąd znajdziesz w /dev/logs.',
    ],
];
