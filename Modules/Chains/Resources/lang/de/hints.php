<?php

declare(strict_types=1);

return [
    'page_title' => 'Ketten-Hinweise',
    'heading' => 'Hinweise',
    'back_to_review' => '← Zurück zur Prüfwarteschlange',
    'subtitle' => 'Kandidaten, die ein Matcher ohne passendes Gegenstück geliefert hat. Jeder Hinweis löst sich beim nächsten Kettendurchlauf von selbst auf — oder du blendest ihn hier aus, sobald du entschieden hast, dass das nicht passiert.',

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
];
