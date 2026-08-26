<?php

declare(strict_types=1);

return [
    'sensitivity_label' => 'Warnempfindlichkeit',
    'sensitivity_help' => 'Wie schnell Beatrax eine Abbuchung für diesen Händler oder diese Kategorie als ungewöhnlich einstuft, von 1 bis 100. Höher markiert mehr.',

    'min_amount_label' => 'Mindestbetrag der Abbuchung',
    'min_amount_help' => 'Ignoriere Auffälligkeiten bei Abbuchungen unter diesem Betrag. Gespeichert in Cent (:symbol) — 1000 bedeutet :example.',

    'save' => 'Anomalie-Einstellungen speichern',
    'saved' => 'Gespeichert.',

    'suppression' => [
        'summary' => 'Unterdrückungsregeln',
        'empty' => 'Noch keine Unterdrückungsregeln. Sobald du eine Abbuchung als erwartet markierst, erscheint hier eine Regel.',
        'remove' => 'Entfernen',
        'remove_aria' => 'Unterdrückungsregel entfernen',
        'removed_toast' => 'Regel entfernt',
    ],

    'unknown_merchant' => 'Unbekannter Händler',

    'detectors' => [
        'large' => 'Große Abbuchung',
        'first_time' => 'Zum ersten Mal',
        'duplicate' => 'Duplikat',
    ],

    'errors' => [
        'sensitivity_range' => 'Die Empfindlichkeit muss zwischen 1 und 100 liegen.',
        'min_amount_negative' => 'Der Mindestbetrag der Abbuchung darf nicht negativ sein.',
    ],
];
