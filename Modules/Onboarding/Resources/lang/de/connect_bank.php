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
    'drop_lead_asn' => 'Lege deine ASN-CSV hier ab',
    'drop_lead_ing' => 'Lege deine ING-CSV hier ab',
    'drop_lead_pick_bank' => 'Wähle, welche Bank deine CSV exportiert hat — das müssen wir wissen, um sie richtig zu lesen.',
    'drop_lead_default' => 'Lege deine Kontoauszugsdatei hier ab',
    'browse_file' => 'oder suche eine Datei aus',

    'banks_mt940' => 'Unterstützt: ASN, ING, Rabobank, Triodos, SNS, Bunq',
    'banks_csv' => 'Unterstützt: ASN, ING — weitere Formate folgen, sobald Nutzer Beispiele beisteuern.',
    'banks_default' => 'Unterstützt: ASN, ING',

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
