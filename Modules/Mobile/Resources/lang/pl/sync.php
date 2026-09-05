<?php

declare(strict_types=1);

return [
    'page_title' => 'Dane i urządzenia',
    'heading' => 'Dane i urządzenia',
    'sync_status' => 'Stan synchronizacji',
    'syncing_progress' => 'Synchronizacja… :count rekord|Synchronizacja… :count rekordy|Synchronizacja… :count rekordów',
    'initial_sync_aria' => 'Postęp pierwszej synchronizacji',
    'no_peers' => 'Sparuj drugie urządzenie, aby rozpocząć synchronizację.',
    'sync_now' => 'Synchronizuj teraz',
    'result' => [
        'synced' => 'Zsynchronizowano z drugim urządzeniem.',
        'unreachable' => 'Nie udało się połączyć z drugim urządzeniem — sprawdź, czy oba są w tej samej sieci.',
        'locked' => 'Odblokuj aplikację, aby zsynchronizować.',
        'not_enabled' => 'Synchronizacja nie jest jeszcze skonfigurowana na tym urządzeniu.',
        'unreadable' => 'Klucz na tym urządzeniu już się nie otwiera. Sparuj ponownie, aby wznowić synchronizację.',
        'paused_on_cellular' => 'Wstrzymano — synchronizacja jest ograniczona do Wi-Fi, a korzystasz z danych mobilnych.',
    ],
    'background_note' => 'Beatrax nasłuchuje przez cały czas, gdy jest otwarty, więc sparowane urządzenie może zsynchronizować się z tym w dowolnej chwili. Synchronizuj teraz rozpoczyna wymianę danych z tej strony.',
    'background_note_phone' => 'Synchronizacja odbywa się po dotknięciu Synchronizuj teraz. Nie może działać w tle — blokada aplikacji przechowuje jedyny klucz.',
    'network' => 'Sieć',
    'pause_cellular' => 'Wstrzymaj synchronizację w sieci komórkowej',
    'pause_cellular_help' => 'Domyślnie wyłączone — synchronizacja działa wszędzie. Włącz, aby synchronizować tylko przez Wi-Fi.',
];
