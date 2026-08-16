<?php

declare(strict_types=1);

return [
    'what_heading' => 'Worüber du benachrichtigt wirst',

    'reminders' => [
        'label' => 'Zahlungserinnerungen',
        'help' => 'Bekomme rechtzeitig Bescheid, bevor eine wiederkehrende Zahlung fällig ist.',
    ],

    'lead_days' => [
        'label' => 'Erinnere mich ___ Tage vorher',
        'help' => 'Wie viele Tage vor dem Fälligkeitsdatum die Erinnerung kommt. 1–30 Tage.',
    ],

    'budget_nudges' => [
        'label' => 'Budget-Hinweise',
        'help' => 'Werde informiert, wenn ein Kategoriebudget fast aufgebraucht ist.',
    ],

    'digest' => [
        'label' => 'Deine wöchentliche Übersicht',
        'help' => 'Wie oft du eine Zusammenfassung bekommst, wie du in diesem Zeitraum dastehst.',
        'daily' => 'Täglich',
        'weekly' => 'Wöchentlich',
        'off' => 'Aus',
    ],

    'savings' => [
        'label' => 'Hinweise auf Sparmöglichkeiten',
        'help' => 'Werde informiert, wenn Beatrax einen günstigeren Tarif oder eine Sparmöglichkeit entdeckt.',
    ],

    'when_heading' => 'Wann und wie',

    'quiet_hours' => [
        'label' => 'Ruhezeiten',
        'help' => 'Kein Ton und kein Banner in diesem Zeitfenster — Benachrichtigungen landen trotzdem in deinem Posteingang.',
        'from' => 'Von',
        'to' => 'Bis',
    ],

    'hide_details' => [
        'label' => 'Details in Benachrichtigungen verbergen',
        'help' => 'Beträge und Händlernamen im Benachrichtigungsbanner selbst anzeigen. Schalte das aus, wenn andere deinen Bildschirm sehen könnten.',
    ],

    'save' => 'Benachrichtigungseinstellungen speichern',
    'saved' => 'Gespeichert.',

    'other_devices' => [
        'summary' => 'Andere Geräte',
        'empty' => 'Noch keine anderen Geräte gekoppelt.',
        'unnamed' => 'Unbenanntes Gerät',

        'summary_line' => 'Erinnerungen :reminders · Hinweise :nudges · Übersicht :digest · Sparen :savings',
        'on' => 'an',
        'off' => 'aus',
    ],

    'errors' => [
        'save_failed' => 'Deine Benachrichtigungseinstellungen konnten nicht gespeichert werden. Versuche es erneut.',
    ],
];
