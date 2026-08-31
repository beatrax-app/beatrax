<?php

declare(strict_types=1);

return [
    'eyebrow' => 'Deine Bank',
    'h1' => 'Hol dir einen Kontoauszug und lege ihn unten ab',
    'lede' => 'Wähle das Format, das dir deine Bank gegeben hat, und lege die Datei ab. CAMT.053 und MT940 erkennen wir automatisch.',

    'format_group_aria' => 'Format des Kontoauszugs',
    'got_it_as' => 'Erhalten als:',
    'badge_recommended' => 'empfohlen',

    'mini' => [
        'login_label' => 'Anmelden',
        'login_sub' => 'Die Website deiner Bank',
        'statements_label' => 'Kontoauszüge öffnen',
        'statements_sub' => 'Im Menü deiner Bank',
        'range_label' => 'Zeitraum wählen',
        'range_sub' => 'Letzte 90 Tage',
        'download_label' => 'Herunterladen',
    ],

    'csv_picker_aria' => 'Welche Bank hat deine CSV exportiert?',
    'csv_picker_from' => 'Von:',

    'drop_lead_camt053' => 'Lege deine CAMT.053-Datei hier ab',
    'drop_lead_mt940' => 'Lege deine MT940-Datei hier ab',
    'drop_lead_csv_layout' => 'Lege deine :layout-CSV hier ab',
    'drop_lead_pick_bank' => 'Wähle, welche Bank deine CSV exportiert hat — das müssen wir wissen, um sie richtig zu lesen.',
    'drop_lead_default' => 'Lege deine Kontoauszugsdatei hier ab',
    'browse_file' => 'oder suche eine Datei aus',

    'format_help_camt053' => 'CAMT.053 ist ein Kontoauszug im XML-Format — im Online-Banking unter Kontoauszüge oder Downloads zu finden.',
    'format_help_mt940' => 'MT940 ist ein Kontoauszug als reiner Text, angeboten als .sta oder .940 neben den XML- und CSV-Downloads.',
    'format_help_csv' => 'CSV ist der Tabellen-Export. Jede Bank ordnet die Spalten anders an, wähle also das passende Layout. Steht deins nicht in der Liste, bitte deine Bank stattdessen um CAMT.053 oder MT940.',

    'account_name_default' => 'Bankkonto',
    'account_name_layout' => ':layout-Konto',

    'file_ready' => '· ✓ bereit',

    'skip' => 'Diesen Schritt überspringen',
    'continue' => 'Weiter →',

    'errors' => [
        'file_required' => 'Lege zuerst deine Kontoauszugsdatei in das Feld.',
        'file_max' => 'Diese Datei ist zu groß. Lege einen Kontoauszug unter 10 MB ab.',
        'file_extensions' => 'Diese Datei sieht nicht nach einem Kontoauszug aus. Lege eine CAMT.053-XML-, CSV- oder MT940-Datei ab.',
        'pick_bank' => 'Wähle vor dem Weitermachen, welche Bank deine CSV exportiert hat.',
        'unreadable' => 'Diese Datei konnte nicht gelesen werden. Der vollständige Fehler steht in /dev/logs.',
    ],
];
