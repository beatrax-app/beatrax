<?php

declare(strict_types=1);

return [
    'page_title' => 'Aus YNAB / Actual importieren',

    'eyebrow' => 'Migrationen',
    'heading' => 'Aus YNAB / Actual importieren',
    'intro' => 'Übernimm deinen Kategorienbaum, deine Budgethistorie und deine Transaktionen aus YNAB4, dem neuen YNAB oder Actual Budget. Bis du alles geprüft und bestätigt hast, wird nichts in dein Hauptbuch geschrieben.',
    'reconcile_context' => 'Es wird auf Änderungen gegenüber deinem letzten :product-Import geprüft.',

    'source_label' => 'Quelle',
    'file_label' => 'Datei',
    'parse_button' => 'Export einlesen',

    'hints' => [
        'ynab4' => 'Exportiere dein vollständiges Budget als ZIP-Datei über das Menü File → Export in YNAB4.',
        'nynab' => 'Exportiere dein Budget aus nYNAB über File → Export Budget und packe die exportierten CSV-Dateien anschließend in ein ZIP.',
        'actual' => 'Exportiere dein Budget als ZIP-Datei über Settings → Export data in Actual Budget.',
    ],

    'errors' => [
        'unrecognised' => 'Das sieht nicht nach einem YNAB4-, nYNAB- oder Actual-Export aus, den wir lesen können. Prüfe die Datei und versuch es noch mal.',
        'file_too_large' => 'Diese Datei ist zu groß für einen Migrationsexport.',
        'archive_reader_unavailable' => 'Diese Version der App hat keinen ZIP-Leser, der diesen Export öffnen kann, hier lässt er sich also nicht lesen. Importiere ihn stattdessen in der Desktop-App, oder pack den Export mit gewöhnlicher Komprimierung neu.',
        'internal_detail' => 'Die App konnte diesen Export nicht lesen (:code). Die vollständigen Angaben stehen im App-Protokoll; nenne diesen Code, wenn du ein Problem meldest.',
    ],
];
