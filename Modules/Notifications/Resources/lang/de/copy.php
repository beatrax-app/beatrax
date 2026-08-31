<?php

declare(strict_types=1);

return [
    'title' => [
        'import_finished' => 'Import abgeschlossen',
        'receipts' => 'Neue Belege gefunden',
        'manual_entry' => 'Kassenbuch aktualisiert',
        'migration_finished' => 'Migration abgeschlossen',
        'drift' => 'Eine wiederkehrende Abbuchung hat sich geändert',
        'forecast' => 'Liquiditätsengpass voraus',
        'budget_nudge' => 'Budget fast aufgebraucht',
        'budget_nudge_spent' => 'Budget aufgebraucht',
        'budget_nudge_over' => 'Budget überschritten',
        'savings_prompt' => 'Hier kannst du sparen',
        'ics_statement_ready' => 'Neuer ICS-Kontoauszug verfügbar',
        'payment_reminder_confident' => 'Zahlung fällig am :day (:date)',
        'payment_reminder_hedged' => 'Zahlung fällig etwa am :day (:date)',
        'position_digest_daily' => 'Deine tägliche Übersicht',
        'position_digest_weekly' => 'Deine wöchentliche Übersicht',
    ],

    'body' => [
        'budget_nudge' => ':category — :spent von :budget ausgegeben.',
        'receipts_matched' => ':count Beleg aus deiner E-Mail zugeordnet.|:count Belege aus deiner E-Mail zugeordnet.',
        'import_finished' => ':count Transaktion importiert.|:count Transaktionen importiert.',
        'manual_entry' => ':count Eintrag von Hand hinzugefügt.|:count Einträge von Hand hinzugefügt.',
        'migration_finished' => 'Dein Budget ist übernommen, darunter :count Transaktion.|Dein Budget ist übernommen, darunter :count Transaktionen.',
        'drift' => 'Eine wiederkehrende Abbuchung ist um :amount :direction.',
        'forecast' => 'Dein prognostizierter Saldo fällt am :date unter null.',
        'forecast_buffer' => 'Dein prognostizierter Saldo fällt am :date unter deinen Puffer von :buffer.',
        'ics_statement_ready' => 'Lade ihn aus dem ICS-Portal herunter und lege ihn in Beatrax ab, damit die Ausgaben dieser Karte aktuell bleiben.',
        'payment_reminder_hedged' => ':name — erwartet etwa am :day (:date), :amount.',
        'payment_reminder_confident' => ':name — fällig am :day (:date), :amount.',
    ],

    'drift_direction' => [
        'up' => 'gestiegen',
        'down' => 'gesunken',
    ],

    'digest' => [
        'nothing_notable' => 'Nichts braucht deine Aufmerksamkeit.',
        'flow' => 'Eingang :in, Ausgang :out, netto :net.',
        'over_budget' => 'Bisher :amount über Budget.',
        'payments_due' => ':count Zahlung in diesem Zeitraum fällig.|:count Zahlungen in diesem Zeitraum fällig.',
        'shortfall' => 'Ein Liquiditätsengpass steht bevor.',
    ],
];
