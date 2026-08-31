<?php

declare(strict_types=1);

return [
    'page_title' => 'Daten & Geräte',
    'heading' => 'Daten & Geräte',
    'sync_status' => 'Sync-Status',
    'syncing_progress' => 'Wird synchronisiert… :count Datensatz|Wird synchronisiert… :count Datensätze',
    'initial_sync_aria' => 'Fortschritt der ersten Synchronisierung',
    'no_peers' => 'Koppele ein anderes Gerät, um mit dem Synchronisieren zu beginnen.',
    'sync_now' => 'Jetzt synchronisieren',
    'result' => [
        'synced' => 'Mit deinem anderen Gerät synchronisiert.',
        'unreachable' => 'Dein anderes Gerät ist nicht erreichbar — prüfe, ob beide im selben Netzwerk sind.',
        'locked' => 'Entsperre die App, um zu synchronisieren.',
        'not_enabled' => 'Die Synchronisierung ist auf diesem Gerät noch nicht eingerichtet.',
        'unreadable' => 'Der Schlüssel auf diesem Gerät lässt sich nicht mehr öffnen. Koppel erneut, um weiter zu synchronisieren.',
        'paused_on_cellular' => 'Pausiert — die Synchronisierung ist auf WLAN beschränkt und du bist im Mobilfunknetz.',
    ],
    'background_note' => 'Synchronisiert wird, wenn du auf Jetzt synchronisieren tippst. Im Hintergrund geht das nicht — die App-Sperre hält den einzigen Schlüssel.',
    'network' => 'Netzwerk',
    'pause_cellular' => 'Synchronisierung im Mobilfunknetz pausieren',
    'pause_cellular_help' => 'Standardmäßig aus — die Synchronisierung funktioniert überall. Schalte sie ein, um nur über WLAN zu synchronisieren.',
];
