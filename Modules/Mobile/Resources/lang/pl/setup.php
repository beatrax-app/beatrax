<?php

declare(strict_types=1);

return [
    'blocked' => [
        'no_peer' => 'Oczekiwanie, aż drugie urządzenie zakończy potwierdzanie.',
        'no_keys' => 'Oczekiwanie na klucze szyfrowania z drugiego urządzenia.',
        'unreachable' => 'Nie można połączyć się z drugim urządzeniem — sprawdź, czy oba są w tej samej sieci.',
        'reprojecting' => 'Odtwarzanie historii…',
        'retrying' => 'Ponowne łączenie z drugim urządzeniem…',
        'locked' => 'Odblokuj aplikację, aby kontynuować konfigurację.',
    ],
    'step' => [
        'connect' => 'Łączenie z drugim urządzeniem',
        'keys' => 'Odbieranie kluczy szyfrowania',
        'transfer' => 'Przenoszenie historii',
        'rebuild' => 'Odtwarzanie historii',
    ],
    'step_current' => 'bieżący krok',
    'working' => [
        'connect' => 'Nawiązywanie połączenia z drugim urządzeniem…',
        'keys' => 'Odblokowywanie danych…',
        'transfer' => 'Pobieranie historii…',
        'rebuild' => 'Odtwarzanie historii — to może chwilę potrwać.',
    ],
    'page_title' => 'Konfiguracja…',
    'resuming' => 'Wznawianie konfiguracji…',
    'setting_up' => 'Konfigurowanie tego urządzenia…',
    'progress_aria' => 'Postęp konfiguracji',
    'records' => 'Rekordy: :count',
    'records_preparing' => 'Oczekiwanie na drugie urządzenie…',
];
