<?php

declare(strict_types=1);

return [
    'eyebrow' => 'Dein PayPal-Konto',
    'h1' => 'Verbinde dein PayPal-Konto',

    'lede_html' => 'Zieh deinen PayPal-Export mit Transaktionsdetails hierher — in einem niederländischen PayPal-Konto heißt er <em lang="nl">Rapport Transactiegegevens</em>. Der Saldobericht (<span lang="nl">Saldorapport</span>) funktioniert nicht — wir brauchen Daten pro Vorgang.',

    'format_group_aria' => 'PayPal exportiert nur als CSV',
    'got_it_as' => 'Erhalten als:',
    'badge_only_format' => 'einziges Format',

    'mini' => [
        'login_label' => 'Anmelden',
        'custom_label' => 'Eigene Kontoauszüge',
        'range_label' => 'Zeitraum wählen',
        'range_sub' => 'Letzte 12 Monate',
        'download_label' => 'Als CSV herunterladen',
    ],

    'drop_lead' => 'Zieh deine CSV mit Transaktionsdetails hierher',
    'browse_file' => 'oder suche eine Datei aus',

    'file_ready' => '· ✓ bereit',

    'skip' => 'Diesen Schritt überspringen',
    'continue' => 'Weiter →',

    'errors' => [
        'required' => 'Zieh zuerst deine CSV „Rapport Transactiegegevens“ von PayPal in das Feld.',
        'max' => 'Diese Datei ist zu groß. Exporte des Typs „Rapport Transactiegegevens“ von PayPal liegen normalerweise deutlich unter 10 MB.',
        'extensions' => 'Diese Datei sieht nicht nach einer PayPal-CSV aus. Lade bei PayPal „Rapport Transactiegegevens“ (nicht den Saldobericht „Saldorapport“) als CSV herunter.',
        'unreadable' => 'Diese Datei konnte nicht gelesen werden. Der vollständige Fehler steht in /dev/logs.',
    ],
];
