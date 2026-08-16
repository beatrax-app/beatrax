<?php

declare(strict_types=1);

return [
    'page_title' => 'Zarządzanie: :name · Beatrax',
    'heading' => 'Zarządzanie: :name',
    'subtitle' => 'Podejrzyj, zresetuj lub wygeneruj ponownie kody tego użytkownika.',

    'set_password' => [
        'heading' => 'Ustaw nowe hasło dla tego użytkownika',
        'description' => 'Przy następnym logowaniu pojawi się prośba o wybranie hasła.',
        'open' => 'Ustaw nowe hasło dla tego użytkownika',
        'body' => 'Ustaw nowe hasło dla użytkownika :name. Przy następnym logowaniu pojawi się prośba o wybranie hasła.',
        'label' => 'Nowe hasło',
        'submit' => 'Ustaw hasło',
        'cancel' => 'Anuluj',
    ],

    'regenerate' => [
        'heading' => 'Wygeneruj ponownie kody odzyskiwania dla tego użytkownika',
        'description' => 'Stare kody przestaną być ważne.',
        'open' => 'Wygeneruj ponownie kody odzyskiwania dla tego użytkownika',
        'body' => 'Dotychczasowe niewykorzystane kody przestaną działać. Nowych 10 kodów zobaczysz tylko raz — zapisz je i przekaż dalej.',
        'confirm_label' => 'Wpisz nazwę użytkownika, aby kontynuować',
        'submit' => 'Wygeneruj kody',
        'keep' => 'Zachowaj obecne kody',
        'download' => 'Pobierz jako .txt',
    ],

    'error_min_length' => 'Użyj co najmniej 12 znaków.',
    'password_set' => 'Hasło ustawione dla użytkownika :name. Przy następnym logowaniu pojawi się prośba o wybranie hasła.',
    'codes_regenerated' => 'Wygenerowano dziesięć nowych kodów odzyskiwania dla użytkownika :name.',
];
