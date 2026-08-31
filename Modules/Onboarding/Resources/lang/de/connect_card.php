<?php

declare(strict_types=1);

return [
    'eyebrow' => 'Deine Kreditkarte',
    'h1' => 'Hol dir deine monatlichen PDF-Kontoauszüge',
    'lede' => 'Lege alle deine monatlichen PDF-Kontoauszüge ab — wir fassen sie zu einer Vorschau zusammen.',

    'format_group_aria' => 'ICS exportiert nur als PDF',
    'issuer_note' => 'ICS ist bislang der einzige Kartenherausgeber, den wir lesen können, und auch nur sein Kontoauszug auf Niederländisch. Ist deine Karte von einem anderen Herausgeber, überspringe diesen Schritt.',
    'got_it_as' => 'Erhalten als:',
    'badge_only_format' => 'einziges Format',

    'mini' => [
        'login_label' => 'Anmelden',
        'statements_label' => 'Kontoauszüge öffnen',
        'months_label' => 'Monate wählen',
        'months_sub' => 'Eine PDF pro Monat',
        'download_label' => 'Herunterladen',
    ],

    'drop_lead' => 'Lege deine ICS-PDFs hier ab',
    'browse_files' => 'oder suche Dateien aus',
    'queue_aria' => 'PDF-Kontoauszüge in der Warteschlange',

    'skip' => 'Diesen Schritt überspringen',
    'continue' => 'Weiter →',

    'errors' => [
        'required' => 'Lege die monatlichen PDF-Kontoauszüge ab, die du bei Mijn ICS heruntergeladen hast.',
        'min' => 'Lege mindestens einen ICS-PDF-Kontoauszug ab, bevor du weitermachst.',
        'each_required' => 'Lege den monatlichen PDF-Kontoauszug ab, den du bei Mijn ICS heruntergeladen hast.',
        'each_max' => 'Eine deiner Dateien ist zu groß. ICS-PDF-Kontoauszüge sind normalerweise jeweils unter 1 MB.',
        'each_extensions' => 'Eine deiner Dateien ist keine PDF. Mijn ICS exportiert nur PDF — probiere den neuesten monatlichen Kontoauszug.',
        'file_unreadable' => ':filename konnte nicht gelesen werden. Der vollständige Fehler steht in /dev/logs.',
        'none_readable' => 'Wir konnten keine deiner ICS-PDFs lesen. :detail',
        'full_error_in_logs' => 'Der vollständige Fehler steht in /dev/logs.',
    ],
];
