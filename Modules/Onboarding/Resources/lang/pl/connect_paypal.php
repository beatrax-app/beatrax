<?php

declare(strict_types=1);

return [
    'eyebrow' => 'Twoje konto PayPal',
    'h1' => 'Połącz swoje konto PayPal',

    'lede_html' => 'Upuść eksport operacji z PayPal — jeden wiersz na transakcję, a nie podsumowanie salda. PayPal nazywa swoje raporty w języku twojego konta, a na razie czytamy niderlandzką parę: <em lang="nl">Rapport Transactiegegevens</em>, nie <span lang="nl">Saldorapport</span>. Jeśli twój wychodzi w innym języku, przed pobraniem przełącz PayPal na niderlandzki.',

    'format_group_aria' => 'PayPal eksportuje wyłącznie do CSV',
    'got_it_as' => 'Mam go jako:',
    'badge_only_format' => 'jedyny format',

    'mini' => [
        'login_label' => 'Zaloguj się',
        'custom_label' => 'Wyciągi niestandardowe',
        'range_label' => 'Wybierz zakres',
        'range_sub' => 'Ostatnie 12 miesięcy',
        'download_label' => 'Pobierz jako CSV',
    ],

    'drop_lead' => 'Upuść tutaj swój eksport operacji',
    'browse_file' => 'lub wybierz plik z dysku',

    'file_ready' => '· ✓ gotowe',

    'skip' => 'Pomiń ten krok',
    'continue' => 'Dalej →',

    'errors' => [
        'required' => 'Najpierw upuść w tym polu eksport operacji z PayPal.',
        'max' => 'Ten plik jest za duży. Eksport operacji z PayPal ma zwykle znacznie mniej niż 10 MB.',
        'extensions' => 'Ten plik nie wygląda na plik CSV z PayPal. Pobierz eksport operacji — jeden wiersz na transakcję, a nie podsumowanie salda — w formacie CSV.',
        'unreadable' => 'Nie udało się odczytać tego pliku. Pełny błąd znajdziesz w /dev/logs.',
    ],
];
