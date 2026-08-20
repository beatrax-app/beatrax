<?php

declare(strict_types=1);

return [
    'blocked' => [
        'no_peer' => 'Čeká se, až druhé zařízení dokončí potvrzení.',
        'no_keys' => 'Čeká se na šifrovací klíče z druhého zařízení.',
        'unreachable' => 'Druhé zařízení není dostupné — zkontroluj, že jsou obě ve stejné síti.',
        'reprojecting' => 'Obnovuje se tvá historie…',
        'retrying' => 'Znovu se připojuje k druhému zařízení…',
        'locked' => 'Odemkni aplikaci, ať může nastavení pokračovat.',
        'revoked' => 'Toto zařízení bylo z druhého zařízení odebráno. Spárujte je znovu a synchronizace bude pokračovat.',
    ],
    'step' => [
        'connect' => 'Připojování k druhému zařízení',
        'keys' => 'Příjem šifrovacích klíčů',
        'transfer' => 'Přenos tvé historie',
        'rebuild' => 'Obnovování tvé historie',
    ],
    'step_current' => 'aktuální krok',
    'working' => [
        'connect' => 'Navazuje se spojení s druhým zařízením…',
        'keys' => 'Odemykají se tvá data…',
        'transfer' => 'Žádáme o tvou historii…',
        'rebuild' => 'Obnovuje se tvá historie — může to chvíli trvat.',
    ],
    'page_title' => 'Probíhá nastavení…',
    'resuming' => 'Pokračuje se v nastavení…',
    'setting_up' => 'Nastavuje se toto zařízení…',
    'progress_aria' => 'Průběh nastavení',
    'records' => 'Záznamy: :count',
    'records_preparing' => 'Čeká se na druhé zařízení…',
];
