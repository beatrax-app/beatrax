<?php

declare(strict_types=1);

return [
    'what_heading' => 'Worüber du benachrichtigt wirst',
    'background_note' => 'Beatrax bereitet diese vor, solange die App offen ist. Ein geplanter Lauf im Hintergrund kann das nicht — die App-Sperre hält den einzigen Schlüssel —, deshalb wird Fälliges nachgeholt, während du die App weiter benutzt.',
    'background_note_phone' => 'Beatrax bereitet diese vor, solange die App offen ist. Im Hintergrund geht das nicht — die App-Sperre hält den einzigen Schlüssel —, deshalb kommt Fälliges beim nächsten Öffnen der App an.',

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
        'label' => 'Deine Übersicht',
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
        'help' => 'Beträge und Händlernamen im Benachrichtigungsbanner selbst verbergen. Schalte das ein, wenn andere deinen Bildschirm sehen könnten.',
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
