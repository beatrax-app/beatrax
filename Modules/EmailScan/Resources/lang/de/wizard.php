<?php

declare(strict_types=1);

return [
    'gmail_title' => 'Richte deinen Gmail-OAuth-Client ein',
    'microsoft_title' => 'Richte deinen Microsoft-365-OAuth-Client ein',
    'intro' => 'Beatrax nutzt dein eigenes Google-Cloud-Projekt bzw. deine eigene Azure-App-Registrierung, damit deine Zugangsdaten nie auf einen gemeinsamen Server gelangen. Das ist eine einmalige Einrichtung pro Anbieter.',

    'copied' => 'Kopiert',
    'cancel' => 'Abbrechen',
    'save_connect' => 'Speichern und verbinden',

    'secret_help' => 'Wird verschlüsselt in der Datenbank auf diesem Gerät gespeichert. Beatrax sendet es nur an Google oder Microsoft, um dein Zugriffstoken zu holen und zu erneuern — sonst nirgendwohin.',

    'gmail' => [
        'step1_title' => 'Google Cloud Console öffnen',
        'step1_body' => 'Öffne die Google Cloud Console in einem neuen Tab. Melde dich mit dem Google-Konto an, das du scannen willst, und erstelle dann ein neues Projekt (oder wähle ein bestehendes persönliches Projekt).',
        'step1_link' => 'Google Cloud Console öffnen',
        'step2_title' => 'Gmail API aktivieren',
        'step2_body' => 'Suche im neuen Projekt in der API Library nach "Gmail API" und klicke auf Enable. Damit erhält das Projekt die Möglichkeit, Gmail in deinem Namen aufzurufen.',
        'step3_title' => 'OAuth-Zustimmungsbildschirm konfigurieren',
        'step3_body' => 'Öffne APIs & Services → OAuth consent screen. Wähle bei User type "External", trage "Beatrax" als App-Namen ein und deine eigene E-Mail-Adresse als Support- und Entwicklerkontakt. Füge den Scope https://www.googleapis.com/auth/gmail.readonly hinzu. Klicke auf Save and continue und dann auf Back to Dashboard.',
        'step4_title' => 'Zustimmungsbildschirm auf "In production" setzen',
        'step4_body' => 'Klicke auf der Seite OAuth consent screen auf Publish App und bestätige. Das ist erforderlich — sonst laufen die Refresh-Tokens, die Beatrax erhält, nach 7 Tagen ab. Für die Veröffentlichung ist keine Prüfung durch Google nötig, solange du der einzige Nutzer bist.',
        'step4_checkbox' => 'Ich habe den OAuth-Zustimmungsbildschirm auf In production gesetzt',
        'step5_title' => 'OAuth-Client-ID erstellen',
        'step5_body' => 'Öffne Credentials → Create Credentials → OAuth Client ID. Wähle als Anwendungstyp "Web application". Setze den Namen auf "Beatrax". Füge unter "Authorized redirect URIs" die untenstehende URI exakt ein.',
        'step6_title' => 'Client-ID und Client Secret einfügen',
        'client_id_label' => 'Client ID',
        'client_secret_label' => 'Client secret',
    ],

    'microsoft' => [
        'step1_title' => 'Azure Portal öffnen',
        'step1_body' => 'Öffne das Microsoft Entra admin center in einem neuen Tab. Melde dich mit dem Microsoft-Konto an, das du scannen willst.',
        'step1_link' => 'Azure Portal öffnen',
        'step2_title' => 'Neue Anwendung registrieren',
        'step2_body' => 'Öffne App registrations → New registration. Nenne sie "Beatrax". Wähle unter "Supported account types" die Option "Accounts in any organizational directory and personal Microsoft accounts" (damit kannst du persönliche Outlook.com-Postfächer und geschäftliche Microsoft-365-Postfächer mit derselben App verbinden).',
        'step3_title' => 'Redirect-URI hinzufügen',
        'step3_body' => 'Wähle im selben Registrierungsformular unter "Redirect URI" die Plattform "Web" und füge die untenstehende URI exakt ein.',
        'step4_title' => 'Berechtigung Mail.Read erteilen',
        'step4_body' => 'Öffne API permissions → Add a permission → Microsoft Graph → Delegated permissions. Wähle Mail.Read und offline_access. Klicke auf Add permissions. Für ein persönliches Konto musst du keine Administratorzustimmung erteilen.',
        'step5_title' => 'Client Secret erstellen',
        'step5_body' => 'Öffne Certificates & secrets → New client secret. Setze als Beschreibung "Beatrax" und eine Gültigkeit von 24 Monaten. Kopiere den Secret-Wert sofort — Azure zeigt ihn nur einmal an.',
        'step6_title' => 'Application (client) ID und Secret einfügen',
        'client_id_label' => 'Application (client) ID',
        'client_secret_label' => 'Client secret value',
    ],

    'errors' => [
        'pick_provider' => 'Wähle einen Anbieter, bevor du absendest.',
        'microsoft_client_id' => 'Gib die Application (client) ID ein — eine UUID wie 12345678-1234-1234-1234-123456789abc.',
        'microsoft_secret' => 'Gib den Client-Secret-Wert ein, den Azure dir beim Erstellen des Secrets angezeigt hat.',
        'google_client_id' => 'Gib eine Google-OAuth-Client-ID ein, die auf .apps.googleusercontent.com endet.',
        'google_secret' => 'Gib ein Google-OAuth-Client-Secret ein, das mit GOCSPX- beginnt.',
        'google_published' => "Bestätige, dass du deinen OAuth-Zustimmungsbildschirm auf 'In production' gesetzt hast.",
        'write_failed' => 'Dein OAuth-Client konnte nicht gespeichert werden — das Schreiben in die Datenbank auf diesem Gerät ist fehlgeschlagen. Versuch es noch mal.',
    ],
];
