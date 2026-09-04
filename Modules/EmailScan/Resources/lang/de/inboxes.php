<?php

declare(strict_types=1);

return [
    'heading' => 'Postfächer',
    'intro' => 'Verbinde Gmail- und Microsoft-365-Postfächer, damit Beatrax sie nach Belegen durchsuchen kann.',
    'intro_phone' => 'Das Durchsuchen von Postfächern läuft in der Desktop-App, nicht auf diesem Telefon.',

    'phone_heading' => 'Dieses Telefon durchsucht keine Postfächer',
    'phone_body' => 'Verbinde Gmail oder Microsoft 365 in der Desktop-App — die dort gefundenen Belege kommen per Synchronisierung hier an.',
    'connection_canceled' => 'Verbindung abgebrochen.',
    'connection_failed' => 'Die Verbindung konnte nicht abgeschlossen werden.',

    'backfilling' => 'Nachladen aus',
    'backfill_progress' => ':fetched / ~:count Nachricht|:fetched / ~:count Nachrichten',

    'connect_heading' => 'Verbinde deine E-Mail',
    'connect_body' => 'Importiere Belege von PayPal, ICS Cards, Google Play und anderen Händlern, indem du Beatrax Lesezugriff auf eines oder mehrere deiner Postfächer gibst.',
    'connect_body_phone' => 'Belege von PayPal, ICS Cards, Google Play und anderen Händlern importiert die Desktop-App aus den Postfächern, denen du ihr Lesezugriff gibst. Dieses Telefon zeigt, was dieser Import findet.',
    'connect_gmail' => 'Gmail verbinden',
    'connect_microsoft' => 'Microsoft 365 verbinden',
    'readonly_note' => 'Beatrax liest nur Nachrichten. Es sendet, kennzeichnet, verschiebt oder löscht nie etwas in deinem Postfach.',

    'months' => ':count Monat|:count Monate',
    'not_scanned_yet' => 'noch nicht gescannt',
    'not_scanned_yet_phone' => 'auf diesem Telefon nicht durchsucht',
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

    'error_detail' => 'Der letzte Scan wurde nicht abgeschlossen. Versuchen Sie „Jetzt scannen“ oder verbinden Sie dieses Postfach erneut.',
    'oauth_state_mismatch' => 'Dieser Verbindungslink ist abgelaufen oder wurde bereits verwendet. Starte die Verbindung neu.',
    'oauth_no_code' => 'Dein E-Mail-Anbieter hat dich ohne den Code zurückgeschickt, den Beatrax zum Abschließen braucht, also wurde kein Postfach verbunden. Starte die Verbindung neu.',
    'oauth_grant_refused' => 'Dein E-Mail-Anbieter hat die Beatrax erteilte Berechtigung abgelehnt — sie ist abgelaufen oder wurde zurückgezogen. Starte die Verbindung neu und bestätige sie.',
    'oauth_exchange_failed' => 'Dein E-Mail-Anbieter hat die Verbindung nicht abgeschlossen, also wurde kein Postfach hinzugefügt. Versuche es in ein paar Minuten erneut.',
    'oauth_not_saved' => 'Die Verbindung ließ sich auf diesem Gerät nicht speichern, also wurde kein Postfach hinzugefügt. Versuche es erneut — schlägt es weiter fehl, hält das App-Protokoll fest, was es gestoppt hat.',
    'oauth_no_offline_access_google' => 'Google hat die dauerhafte Berechtigung nicht erteilt, die Beatrax braucht, also würde dieses Postfach binnen einer Stunde aufhören zu scannen. Veröffentliche deinen OAuth-Zustimmungsbildschirm für die Produktion und verbinde dann erneut.',
    'oauth_no_offline_access' => 'Dein E-Mail-Anbieter hat die dauerhafte Berechtigung nicht erteilt, die Beatrax braucht, also würde dieses Postfach binnen einer Stunde aufhören zu scannen. Verbinde erneut und erlaube den Offlinezugriff, wenn du gefragt wirst.',
    'oauth_no_offline_access_google_phone' => 'Google hat die dauerhafte Berechtigung nicht erteilt, die Beatrax braucht, also wurde kein Postfach verbunden. Veröffentliche deinen OAuth-Zustimmungsbildschirm für die Produktion und verbinde dann erneut — das Durchsuchen selbst läuft in der Desktop-App.',
    'oauth_no_offline_access_phone' => 'Dein E-Mail-Anbieter hat die dauerhafte Berechtigung nicht erteilt, die Beatrax braucht, also wurde kein Postfach verbunden. Verbinde erneut und erlaube den Offlinezugriff, wenn du gefragt wirst — das Durchsuchen selbst läuft in der Desktop-App.',

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
    'gmail_card_body_phone' => 'Gmail wird von der Desktop-App durchsucht. Ein hier verbundenes Konto wird nie von selbst durchsucht.',
    'microsoft_card_body_phone' => 'Microsoft 365 und Outlook.com werden von der Desktop-App durchsucht. Ein hier verbundenes Konto wird nie von selbst durchsucht.',

    'discovered_heading' => 'Entdeckte Absender',

    'known_sender' => [
        'ics_statements' => 'ICS Cards (Kontoauszüge)',
    ],
    'discovered_body' => 'Absender, die so aussehen, als würden sie Belege schicken, aber noch nicht auf deiner Liste bekannter Belegabsender stehen. Füge die hinzu, die Beatrax durchsuchen soll; verwirf den Rest.',
    'last_seen' => 'zuletzt gesehen',
    'seen_times' => ':count Mal gesehen|:count Mal gesehen',
    'add' => 'Hinzufügen',
    'add_aria' => ':email hinzufügen',
    'dismiss' => 'Verwerfen',
    'dismiss_aria' => ':email verwerfen',

    'toast' => [
        'reconnect_first' => 'Verbinde dieses Postfach neu, bevor du scannst.',
        'scan_in_progress' => 'Es läuft bereits ein Scan.',
        'scan_started' => 'Scan gestartet.',
        'sender_added' => 'Absender hinzugefügt.',
        'sender_dismissed' => 'Absender verworfen.',
    ],
];
