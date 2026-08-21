<?php

declare(strict_types=1);

return [
    'heading' => 'Postfächer',
    'intro' => 'Verbinde Gmail- und Microsoft-365-Postfächer, damit Beatrax sie nach Belegen durchsuchen kann.',

    'connection_canceled' => 'Verbindung abgebrochen.',
    'connection_failed' => 'Die Verbindung konnte nicht abgeschlossen werden.',

    'backfilling' => 'Nachladen aus',
    'messages_suffix' => 'Nachrichten',

    'connect_heading' => 'Verbinde deine E-Mail',
    'connect_body' => 'Importiere Belege von PayPal, ICS Cards, Google Play und anderen Händlern, indem du Beatrax Lesezugriff auf eines oder mehrere deiner Postfächer gibst.',
    'connect_gmail' => 'Gmail verbinden',
    'connect_microsoft' => 'Microsoft 365 verbinden',
    'readonly_note' => 'Beatrax liest nur Nachrichten. Es sendet, kennzeichnet, verschiebt oder löscht nie etwas in deinem Postfach.',

    'months' => ':count Monat|:count Monate',
    'not_scanned_yet' => 'noch nicht gescannt',
    'last_scanned' => 'zuletzt gescannt',
    'window_prefix' => 'Zeitraum:',
    'edit' => 'Bearbeiten',

    'badge' => [
        'idle' => 'Inaktiv',
        'backfilling' => 'Nachladen',
        'scanning' => 'Scannen',
        'rate_limited' => 'Limit erreicht',
        'needs_reauth' => 'Neu anmelden',
        'error' => 'Fehler',
    ],

    'retry_seconds' => 'neuer Versuch in :ns',
    'retry_minutes' => 'neuer Versuch in :nmin',
    'retry_hours' => 'neuer Versuch in :nh',

    'reconnect' => 'Erneut verbinden',
    'disconnect' => 'Trennen',
    'scan_now' => 'Jetzt scannen',
    'scan_in_progress_title' => 'Es läuft bereits ein Scan',

    'add_another' => 'Weiteres Postfach hinzufügen',
    'gmail_card_body' => 'Verbinde ein Gmail-Konto, damit Beatrax es nach Belegen durchsuchen kann.',
    'microsoft_card_body' => 'Verbinde ein Microsoft-365- oder Outlook.com-Konto, damit Beatrax es nach Belegen durchsuchen kann.',

    'discovered_heading' => 'Entdeckte Absender',
    'discovered_body' => 'Absender, die so aussehen, als würden sie Belege schicken, aber noch nicht auf deiner Liste bekannter Belegabsender stehen. Füge die hinzu, die Beatrax durchsuchen soll; verwirf den Rest.',
    'last_seen' => 'zuletzt gesehen',
    'seen_times' => ':count Mal gesehen|:count Mal gesehen',
    'add' => 'Hinzufügen',
    'add_aria' => ':email hinzufügen',
    'dismiss' => 'Verwerfen',
    'dismiss_aria' => ':email verwerfen',

    'toast' => [
        'scan_in_progress' => 'Es läuft bereits ein Scan.',
        'scan_started' => 'Scan gestartet.',
        'sender_added' => 'Absender hinzugefügt.',
        'sender_dismissed' => 'Absender verworfen.',
    ],
];
