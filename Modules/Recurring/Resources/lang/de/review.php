<?php

declare(strict_types=1);

return [
    'title' => 'Wiederkehrendes prüfen',
    'subtitle' => 'Bestätige, stelle zurück oder lehne erkannte Vorschläge für wiederkehrende Zahlungen ab.',

    'tabs' => [
        'pending' => 'Offen',
        'rejected' => 'Abgelehnt',
        'cadence_changed' => 'Rhythmus geändert',
    ],

    'bulk' => [
        'aria' => 'Sammelaktionen',
        'selected' => ':count ausgewählt',
        'approve' => ':count bestätigen',
        'reject' => ':count ablehnen',
    ],

    'empty' => [
        'heading' => 'Nichts zu prüfen',
        'pending' => 'Vorschläge für wiederkehrende Zahlungen landen hier, sobald die Erkennung stabile monatliche Cluster findet.',
        'rejected' => 'Abgelehnte Vorschläge erscheinen hier, damit du sie zurückholen kannst, wenn du es dir anders überlegst.',
        'cadence_changed' => 'Bestätigte Reihen, deren Rhythmus sich geändert hat, tauchen hier zur erneuten Prüfung auf.',
    ],

    'next' => 'Nächste',
    'cadence_changed_note' => 'Rhythmus geändert',

    'select_aria' => 'Wiederkehrende Reihe :id auswählen',
    'un_reject' => 'Ablehnung aufheben',
    'approve' => 'Bestätigen',
    'approve_aria' => 'Wiederkehrende Reihe :id bestätigen',
    'reject' => 'Ablehnen',
    'reject_aria' => 'Wiederkehrende Reihe :id ablehnen',
    'snooze' => 'Zurückstellen',
    'snooze_1w' => '1 Woche',
    'snooze_1m' => '1 Monat',
    'snooze_3m' => '3 Monate',
    'edit_name' => 'Namen bearbeiten',
    'new_name_label' => 'Neuer Name für diese Reihe',
    'save' => 'Speichern',

    'toast' => [
        'approved' => 'Bestätigt',
        'rejected' => 'Abgelehnt',
        'snoozed' => 'Zurückgestellt',
        'renamed' => 'Umbenannt',
        'un_rejected' => 'Ablehnung aufgehoben',
        'bulk_approved' => ':count bestätigt',
        'bulk_rejected' => ':count abgelehnt',
    ],
];
