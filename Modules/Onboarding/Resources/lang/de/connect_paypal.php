<?php

declare(strict_types=1);

return [
    'eyebrow' => 'Dein PayPal-Konto',
    'h1' => 'Verbinde dein PayPal-Konto',

    'lede_html' => 'Lege deinen PayPal-Umsatzexport ab — eine Zeile pro Transaktion, nicht die Saldoübersicht. PayPal benennt seine Berichte in der Sprache deines Kontos, und bislang lesen wir das niederländische Paar: <em lang="nl">Rapport Transactiegegevens</em>, nicht <span lang="nl">Saldorapport</span>. Kommt deiner in einer anderen Sprache, stelle PayPal vor dem Herunterladen auf Niederländisch um.',

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

    'drop_lead' => 'Lege deinen Umsatzexport hier ab',
    'browse_file' => 'oder suche eine Datei aus',

    'file_ready' => '· ✓ bereit',

    'skip' => 'Diesen Schritt überspringen',
    'continue' => 'Weiter →',

    'errors' => [
        'required' => 'Lege zuerst deinen PayPal-Umsatzexport in das Feld.',
        'max' => 'Diese Datei ist zu groß. Ein PayPal-Umsatzexport liegt normalerweise deutlich unter 10 MB.',
        'extensions' => 'Diese Datei sieht nicht nach einer PayPal-CSV aus. Lade den Umsatzexport — eine Zeile pro Transaktion, nicht die Saldoübersicht — als CSV herunter.',
        'unreadable' => 'Diese Datei konnte nicht gelesen werden. Der vollständige Fehler steht in /dev/logs.',
    ],
];
