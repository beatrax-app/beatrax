<?php

declare(strict_types=1);

return [
    'page_title' => 'Data a zařízení',
    'heading' => 'Data a zařízení',
    'sync_status' => 'Stav synchronizace',
    'syncing_progress' => 'Synchronizace… :count záznam|Synchronizace… :count záznamy|Synchronizace… :count záznamů',
    'initial_sync_aria' => 'Průběh první synchronizace',
    'no_peers' => 'Spáruj další zařízení a začni synchronizovat.',
    'sync_now' => 'Synchronizovat teď',
    'result' => [
        'synced' => 'Synchronizováno s druhým zařízením.',
        'unreachable' => 'Druhé zařízení není dostupné — zkontroluj, že jsou obě ve stejné síti.',
        'locked' => 'Odemkni aplikaci a spusť synchronizaci.',
        'not_enabled' => 'Synchronizace na tomto zařízení zatím není nastavená.',
        'unreadable' => 'Klíč tohoto zařízení už nejde otevřít. Spáruj znovu a obnov synchronizaci.',
        'paused_on_cellular' => 'Pozastaveno — synchronizace je omezená na Wi-Fi a jsi na mobilních datech.',
    ],
    'background_note' => 'Beatrax naslouchá po celou dobu, co je otevřený, takže spárované zařízení se s tímto může synchronizovat kdykoli. Synchronizovat teď zahájí výměnu dat z této strany.',
    'background_note_phone' => 'Synchronizace proběhne, když klepneš na Synchronizovat teď. Na pozadí běžet nemůže — zámek aplikace drží jediný klíč.',
    'network' => 'Síť',
    'pause_cellular' => 'Pozastavit synchronizaci na mobilních datech',
    'pause_cellular_help' => 'Ve výchozím stavu vypnuto — synchronizace funguje všude. Zapni, když chceš synchronizovat jen přes Wi-Fi.',
];
