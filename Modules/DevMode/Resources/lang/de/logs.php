<?php

declare(strict_types=1);

return [
    'heading' => 'Logs',
    'subtitle' => 'Live-Tail der heutigen Laravel-Logdatei mit doppelter Schwärzung beim Schreiben und beim Streamen.',
    'truncate' => 'Leeren',
    'truncate_confirm' => 'Die heutige Logdatei leeren? Das lässt sich nicht rückgängig machen.',
    'truncate_title' => 'Die heutige Logdatei leeren (behält die Inode, damit der Tailer sauber weiterläuft)',
    'filters_aria' => 'Log-Filter',
    'severity_aria' => 'Schweregrad-Filter',
    'channel_placeholder' => 'Kanalfilter…',
    'channel_aria' => 'Kanalfilter',
    'contains_placeholder' => 'Sichtbare durchsuchen…',
    'contains_aria' => 'Enthält-Filter',
    'pause' => 'Pausieren',
    'resume' => 'Fortsetzen',
    'waiting' => 'Warten auf Logzeilen…',
    'copy' => 'Kopieren',
    'copy_title' => 'Vollständigen Eintrag kopieren',
    'copy_title_copied' => 'Kopiert',
    'copy_aria' => 'Log-Eintrag kopieren',
    'copy_aria_copied' => 'In die Zwischenablage kopiert',
    'dismiss' => 'Ausblenden',
    'dismiss_title' => 'Aus der Ansicht ausblenden (ändert die Logdatei nicht)',
    'dismiss_aria' => 'Log-Eintrag aus der Ansicht ausblenden',
    'totals' => [
        'showing' => 'Zeigt :shown von :count empfangenen Zeile (Pufferlimit :cap)|Zeigt :shown von :count empfangenen Zeilen (Pufferlimit :cap)',
        'lines_today' => ':count Zeile heute|:count Zeilen heute',
        'lines_today_capped' => 'über :count Zeile heute|über :count Zeilen heute',
        'today' => 'heute',
        'all_files' => ':size über :count Tagesdatei|:size über :count Tagesdateien',
    ],

    'status' => [
        'poll_interrupted' => 'Log-Abfrage unterbrochen. Neuer Versuch…',
        'paused' => 'Pausiert.',
        'copy_failed_prefix' => 'Kopieren fehlgeschlagen: ',
        'clipboard_unavailable' => 'Zwischenablage nicht verfügbar',
    ],

    'toast' => [
        'truncated' => 'Log geleert — :size freigegeben.',
        'nothing' => 'Nichts zu leeren.',
    ],
];
