<?php

declare(strict_types=1);

return [
    'title' => [
        'import_finished' => 'Import abgeschlossen',
        'receipts' => 'Neue Belege gefunden',
        'drift' => 'Eine wiederkehrende Abbuchung hat sich geändert',
        'forecast' => 'Liquiditätsengpass voraus',
        'budget_nudge' => 'Budget fast aufgebraucht',
        'savings_prompt' => 'Es gibt einen günstigeren Tarif',
        'ics_statement_ready' => 'Neuer ICS-Kontoauszug verfügbar',
        'payment_reminder_confident' => 'Zahlung fällig am :day',
        'payment_reminder_hedged' => 'Zahlung fällig etwa am :day',
        'position_digest_daily' => 'Deine tägliche Übersicht',
        'position_digest_weekly' => 'Deine wöchentliche Übersicht',
    ],

    'body' => [
        'budget_nudge' => ':category — :spent von :budget ausgegeben.',
        'receipts_matched' => ':count Beleg aus deiner E-Mail zugeordnet.|:count Belege aus deiner E-Mail zugeordnet.',
        'import_finished' => ':count Transaktion importiert.|:count Transaktionen importiert.',
        'drift' => 'Eine wiederkehrende Abbuchung ist um :delta :currency :direction.',
        'forecast' => 'Dein prognostizierter Saldo fällt in den nächsten 30 Tagen unter null.',
        'ics_statement_ready' => 'Lade ihn aus dem ICS-Portal herunter und lege ihn in Beatrax ab, damit die Ausgaben dieser Karte aktuell bleiben.',
        'payment_reminder_hedged' => ':name — erwartet etwa am :day, :amount.',
        'payment_reminder_confident' => ':name — fällig am :day (:date), :amount.',
        'savings_prompt' => ':message (:monthly/Mon.)',
    ],

    'drift_direction' => [
        'up' => 'gestiegen',
        'down' => 'gesunken',
    ],

    'digest' => [
        'nothing_notable' => 'Nichts braucht deine Aufmerksamkeit.',
        'flow' => 'Eingang :in, Ausgang :out, netto :net.',
        'over_budget' => 'Bisher :amount über Budget.',
        'payments_due' => '1 Zahlung in diesem Zeitraum fällig.|:count Zahlungen in diesem Zeitraum fällig.',
        'shortfall' => 'Ein Liquiditätsengpass steht bevor.',
    ],
];
