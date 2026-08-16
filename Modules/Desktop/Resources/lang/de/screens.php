<?php

declare(strict_types=1);

return [
    'welcome' => [
        'page_title' => 'Willkommen',
        'heading' => 'Willkommen bei Beatrax',
        'subtitle' => 'Dein rein lokales Finanz-Dashboard ist bereit. Lege dein erstes Konto an, um zu starten.',
        'get_started' => 'Loslegen',
    ],

    'setup' => [
        'page_title' => 'Wird eingerichtet…',
        'pending_heading' => 'Wird eingerichtet…',
        'pending_body' => 'Beatrax bereitet deine Daten vor. Das dauert nur einen Moment.',
        'failed_body' => 'Die Einrichtung konnte nicht abgeschlossen werden. Starte Beatrax neu; wenn es weiter fehlschlägt, steht der Grund im Log.',
        'ready_heading' => 'Bereit',
        'ready_body' => 'Einrichtung abgeschlossen. Weiter…',
    ],

    'staging' => [
        'page_title' => 'Datei empfangen',
        'heading_prefix' => 'Datei empfangen: ',
        'button_label' => 'Import starten',
        'csv_subtitle' => 'Ein Bank- oder PayPal-Export — starte den Import, um ihn zu prüfen und zu bestätigen.',
        'eml_subtitle' => 'Ein E-Mail-Beleg — starte den Import, um ihn an seine Transaktion anzuhängen.',
        'empty_heading' => 'Wir konnten diese Datei nicht öffnen',
        'empty_body' => 'Beatrax konnte die geöffnete Datei nicht lesen. Versuche stattdessen, sie über die Import-Seite zu importieren.',
        'open_imports' => 'Importe öffnen',
    ],

    'close' => [
        'title' => 'Beatrax weiterlaufen lassen?',
        'body' => 'Beim Schließen des Fensters kannst du Beatrax entweder ganz beenden oder es still in der Menüleiste weiterlaufen lassen, damit geplante E-Mail-Scans weiterlaufen.',
        'button_quit' => 'Beatrax beenden',
        'button_keep_in_tray' => 'In der Menüleiste weiterlaufen lassen',
        'checkbox_remember' => 'Meine Wahl merken',
    ],
];
