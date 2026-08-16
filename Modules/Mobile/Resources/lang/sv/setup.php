<?php

declare(strict_types=1);

return [
    'blocked' => [
        'no_peer' => 'Väntar på att den andra enheten ska bli klar med bekräftelsen.',
        'no_keys' => 'Väntar på krypteringsnycklarna från den andra enheten.',
        'unreachable' => 'Det går inte att nå den andra enheten — kontrollera att båda är på samma nätverk.',
        'reprojecting' => 'Bygger om din historik…',
        'retrying' => 'Återansluter till den andra enheten…',
        'locked' => 'Lås upp appen för att fortsätta konfigurationen.',
    ],
    'step' => [
        'connect' => 'Ansluter till din andra enhet',
        'keys' => 'Tar emot krypteringsnycklar',
        'transfer' => 'Överför din historik',
        'rebuild' => 'Bygger om din historik',
    ],
    'step_current' => 'aktuellt steg',
    'working' => [
        'connect' => 'Kontaktar din andra enhet…',
        'keys' => 'Låser upp dina data…',
        'transfer' => 'Begär din historik…',
        'rebuild' => 'Bygger om din historik — det kan ta en stund.',
    ],
    'page_title' => 'Konfigurerar…',
    'resuming' => 'Återupptar konfigurationen…',
    'setting_up' => 'Konfigurerar den här enheten…',
    'progress_aria' => 'Förlopp för konfigurationen',
    'records' => ':applied av :expected poster',
    'records_preparing' => 'Väntar på den andra enheten…',
];
