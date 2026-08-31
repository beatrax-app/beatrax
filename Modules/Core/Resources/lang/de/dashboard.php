<?php

declare(strict_types=1);

return [
    'page_title' => 'Dashboard',
    'subtitle' => 'Dieser Zeitraum auf einen Blick.',

    'previous_period' => 'Vorheriger Zeitraum',
    'today' => 'Heute',
    'next_period' => 'Nächster Zeitraum',

    'totals_aria' => 'Summen dieses Zeitraums',
    'totals_aria_currency' => 'Summen dieses Zeitraums — :currency',
    'in' => 'Einnahmen',
    'out' => 'Ausgaben',
    'net' => 'Netto',

    'status_tiles_aria' => 'Status-Kacheln',
    'email_scan_health' => 'Status des E-Mail-Scans — :count verbundenes Postfach|Status des E-Mail-Scans — :count verbundene Postfächer',

    'top_spending' => 'Größte Ausgaben',
    'no_expenses' => 'Noch keine kategorisierten Ausgaben.',
    'top_spending_refunded' => 'Nicht gewertet — :amount kam zurück',

    'recent_transactions' => 'Letzte Transaktionen',
    'view_all' => 'Alle anzeigen',
    'nothing_period' => 'Nichts in diesem Zeitraum.',
    'th_date' => 'Datum',
    'th_counterparty' => 'Zahlungspartner',
    'th_category' => 'Kategorie',
    'th_amount' => 'Betrag',
    'uncategorized' => 'Nicht kategorisiert',

    'jump_to_records' => [
        'body' => 'Nichts in diesem Zeitraum. Ihre neuesten Buchungen sind weiterhin da.',
        'action' => 'Zeitraum :period anzeigen',
    ],

    'reauth' => [
        'title' => 'Ein Postfach muss neu verbunden werden.',
        'body' => 'Ein oder mehrere Postfächer wurden abgemeldet — Beatrax kann sie erst wieder scannen, wenn du sie neu verbindest.',
        'link' => 'Zu den Postfächern',
        'dismiss' => 'Ausblenden',
    ],

    'failed_chain' => [
        'title' => 'Kettenauflösung fehlgeschlagen.',
        'body' => 'Bei einem oder mehreren Aufträgen zur Kettenauflösung ist ein Fehler aufgetreten.',
        'link' => 'Queue-Inspektor öffnen',
    ],
];
