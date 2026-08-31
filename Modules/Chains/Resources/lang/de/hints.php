<?php

declare(strict_types=1);

return [
    'page_title' => 'Ketten-Hinweise',
    'heading' => 'Hinweise',
    'back_to_review' => '← Zurück zur Prüfwarteschlange',
    'subtitle' => 'Kandidaten, die ein Matcher ohne passendes Gegenstück gemeldet hat. Ein Abrechnungshinweis verschwindet von selbst, sobald die fehlenden Buchungen eintreffen; der Rest bleibt, bis du ihn hier verwirfst.',

    'empty_heading' => 'Keine Hinweise zu prüfen',
    'empty_body' => 'Wenn ein Matcher eine Kette findet, die er nicht automatisch auflösen konnte, taucht sie hier auf.',

    'no_counterparty' => '(kein Zahlungspartner)',
    'unknown_account' => '(unbekanntes Konto)',

    'dismiss' => 'Ausblenden',
    'dismiss_aria' => 'Hinweis :id ausblenden',
    'dismissed' => 'Hinweis ausgeblendet.',

    'kind' => [
        'ics_bulk_settle' => 'iDEAL-Sammelabrechnung (außerhalb der Toleranz)',
        'funded_by_card_hint' => 'Mit Karte finanziert (Hinweis)',
        'refund_of_hint' => 'Rückerstattung (Hinweis)',
    ],

    'evidence' => [
        'tolerance' => 'Toleranz: :tolerance',
        'tolerance_used' => [
            'amount_5eur' => 'innerhalb des festen Spielraums',
            'percent_2' => 'innerhalb des prozentualen Spielraums',
            'exceeded' => 'außerhalb des Spielraums',
            'refund_after_close' => 'Erstattung nach Abschluss',
        ],
        'delta_overpaid' => 'Zu viel gezahlt: :amount',
        'delta_underpaid' => 'Zu wenig gezahlt: :amount',
        'delta_balanced' => 'Geht genau auf',
        'covered' => 'Abgedeckte Transaktionen: :count',
        'statement' => 'Kartenabrechnung #:id',
        'card_last4' => 'Karte endend auf :last4',
        'original_reference' => 'Ursprüngliche Bestellreferenz: :reference',
    ],
];
