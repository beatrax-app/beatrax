<?php

declare(strict_types=1);

return [
    'heading_named' => 'Kette für :name',
    'heading' => 'Kette',

    'unresolved_heading' => 'Kette noch nicht aufgelöst',
    'unresolved_body' => 'Die Kettenauflösung läuft noch. Öffne die Prüfwarteschlange oder aktualisiere gleich noch einmal.',

    'none_heading' => 'Keine Finanzierungskette gefunden',
    'none_body' => 'Für diese Transaktion wurde keine Finanzierungskette erkannt. Wenn du eine erwartet hast, lege aus der Prüfwarteschlange einen Kandidaten an.',

    'none_beyond_leg' => 'Über dieses Glied hinaus wurde keine Finanzierungskette gefunden.',

    'covers_charges' => 'Deckt :count ICS-Abbuchungen ab',
    'no_ics_charges' => 'Keine ICS-Abbuchungen in dieser Abrechnung',
    'show_more_fanout' => ':count weitere anzeigen · :shown von :total',

    'confirm' => 'Bestätigen',
    'reject' => 'Ablehnen',
    'confirm_aria' => 'Kettenverknüpfung :id bestätigen',
    'reject_aria' => 'Kettenverknüpfung :id ablehnen',

    'confidence_aria' => [
        'deterministic' => 'Zuverlässigkeit: deterministische Übereinstimmung',
        'confirmed' => 'Zuverlässigkeit: bestätigt',
        'candidate' => 'Zuverlässigkeit: Kandidat; Prüfung erforderlich',
    ],
];
