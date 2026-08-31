<?php

declare(strict_types=1);

return [

    'page' => [
        'back_link' => 'Einstellungen',
        'heading' => 'Open Banking',
        'subtitle' => 'Transaktionen automatisch von ASN oder SNS über Enable Banking abrufen, einen externen PSD2-Aggregator. Standardmäßig aus.',
        'toggle_label' => 'Open Banking aktivieren',
        'toggle_connected' => 'Mit :bank über Enable Banking verbunden.',
        'toggle_off_help' => 'Standardmäßig aus. Erfordert eine einmalige Bestätigung und eine geführte Einrichtung.',
        'reconfirm_body' => 'Deine Bestätigung ist abgelaufen, bevor wir die Verbindung abschließen konnten. Bestätige erneut, um die Aktivierung von Open Banking abzuschließen.',
        'reconfirm_button' => 'Erneut bestätigen und abschließen',
    ],

    'status_row' => [
        'heading' => 'Open Banking',
        'manage' => 'Open Banking verwalten',
        'not_connected' => 'Keine Bank verbunden. Verbinde eine, um Transaktionen automatisch zu importieren.',
        'expired' => 'Einwilligung abgelaufen — erneutes Verbinden nötig.',
        'revoked' => 'Deine Bank hat die Verbindung beendet — verbinde dich erneut.',
        'connected' => 'Mit :bank über Enable Banking verbunden. Zuletzt synchronisiert :when.',
        'never' => 'nie',
    ],

    'transparency' => [
        'aggregator_label' => 'Aggregator',
        'bank_label' => 'Bank',
        'consent_status_label' => 'Status der Einwilligung',
        'pill_expired' => 'Abgelaufen — erneut verbinden',
        'pill_expiring' => 'Läuft bald ab',
        'pill_connected' => 'Verbunden',
        'pill_revoked' => 'Von deiner Bank beendet — neu verbinden',
        'whats_fetched_label' => 'Was abgerufen wird',
        'whats_fetched' => 'Gebuchte Transaktionen + Salden, letzte 90 Tage',
        'last_successful_sync_label' => 'Letzte erfolgreiche Synchronisierung',
        'never' => 'Nie',
        'last_attempt_label' => 'Letzter Versuch',
        'last_attempt_failed' => ':when — fehlgeschlagen (:reason)',
        'reason_consent_expired' => 'Einwilligung abgelaufen',
        'reason_error' => 'Fehler',
        'reason_truncated' => 'vorzeitig gestoppt',
        'reason_nothing_imported' => 'nichts konnte verbucht werden',
        'reason_consent_revoked' => 'von deiner Bank beendet',
        'disconnect_button' => 'Trennen',
    ],

    'consent_banner' => [
        'heading' => 'Einwilligung abgelaufen — erneut verbinden',
        'heading_revoked' => 'Deine Bank hat die Verbindung beendet',
        'body' => 'Deine letzte erfolgreiche Synchronisierung war :when. Verbinde erneut, um die automatische Synchronisierung fortzusetzen.',
        'body_revoked' => 'Deine Bank oder Enable Banking hat den Zugriff entzogen, deshalb wurde die Synchronisierung gestoppt. Deine letzte erfolgreiche Synchronisierung war :when. Verbinde erneut, um sie fortzusetzen.',
        'never' => 'nie',
        'reconnect' => 'Erneut verbinden',
    ],

    'sync' => [
        'review_import' => 'Import prüfen',
        'reconnect_first' => 'Zuerst erneut verbinden',
        'auto_caption' => 'Wird automatisch einmal am Tag synchronisiert.',
        'sync_now' => 'Jetzt synchronisieren',

        'consent_expired' => 'Einwilligung abgelaufen — erneut verbinden.',
        'unavailable' => 'Enable Banking ist vorübergehend nicht erreichbar. Versuche es gleich noch einmal.',
        'new_found' => ':count neue Transaktion gefunden.|:count neue Transaktionen gefunden.',
        'none' => 'Keine neuen Transaktionen.',
        'none_importable' => 'Deine Bank hat Transaktionen geschickt, aber keine davon konnte verbucht werden. Öffne die Importprüfung, um zu sehen warum.',
        'in_progress' => 'Es läuft bereits eine Synchronisierung. Versuchen Sie es gleich noch einmal.',
        'truncated' => 'Deine Bank hatte mehr Transaktionen, als eine Synchronisierung abrufen kann, deshalb wurde dieser Lauf vorzeitig gestoppt. Es wurde nichts als synchronisiert vermerkt — die nächste Synchronisierung beginnt an derselben Stelle.',
    ],

    'disconnect' => [
        'heading' => 'Open Banking trennen?',
        'body' => 'Damit werden deine gespeicherten Enable-Banking-Zugangsdaten und die Einwilligung entfernt. Die automatische Synchronisierung stoppt sofort. Bereits in Beatrax importierte Transaktionen bleiben unberührt.',
        'confirm' => 'Trennen',
        'cancel' => 'Verbunden bleiben',
    ],

    'ics' => [
        'section_label' => 'Dateiimport — keine Zugangsdaten gespeichert',
        'heading' => 'ICS-Kreditkartenabrechnung',
        'step_login' => 'Anmelden',
        'step_download' => 'Kontoauszug herunterladen',
        'pdf_statement' => 'PDF-Kontoauszug',
        'step_drop' => 'Lege ihn unten ab',
        'drop_zone_label' => 'Lege deine Kontoauszugsdatei hier ab',
        'drop_zone_hint' => 'oder wähle eine Datei aus',
        'browse_aria' => 'Nach einer ICS-Kontoauszugsdatei suchen',
        'import_button' => 'Kontoauszug importieren',
        'validation' => [
            'required' => 'Lege den ICS-Kontoauszug ab, den du aus Mijn ICS heruntergeladen hast.',
            'max' => 'Diese Datei ist zu groß. ICS-PDF-Kontoauszüge sind normalerweise kleiner als 1 MB.',
            'extensions' => 'Das ist keine PDF. Mijn ICS exportiert nur PDF-Kontoauszüge.',
        ],
        'could_not_read' => ':filename konnte nicht gelesen werden. Der vollständige Fehler steht in /dev/logs.',
    ],

    'warning' => [
        'heading' => 'Bevor du einen Dritten verbindest',
        'body' => 'Wenn du Open Banking aktivierst, werden deine Einwilligung zum Bank-Login und danach deine Transaktions- und Saldodaten direkt von diesem Gerät an Enable Banking und deine Bank gesendet. Beatrax betreibt keinen Server, der diese Daten sieht — Enable Banking und deine Bank aber schon. Das unterscheidet sich von jeder anderen Importmethode in Beatrax, die niemals Daten irgendwohin sendet.',
        'acknowledge' => 'Mir ist klar, dass meine Transaktionsdaten mit Enable Banking und meiner Bank geteilt werden.',
        'confirm' => 'Open Banking aktivieren',
        'cancel' => 'Abbrechen',
    ],

    'wizard' => [
        'heading' => 'Verbinde deine Bank',
        'intro' => 'Beatrax nutzt deine eigene Enable-Banking-Anwendung, damit deine Zugangsdaten nie auf einem gemeinsamen Server landen. Das ist eine einmalige Einrichtung pro Bank.',

        'step1_title' => 'Erzeuge dein lokales Schlüsselpaar',
        'step1_body' => 'Beatrax erzeugt auf diesem Gerät ein RSA-Schlüsselpaar. Der private Schlüssel verlässt es nie.',
        'generate_keypair' => 'Schlüsselpaar erzeugen',
        'public_key_label' => 'Öffentlicher Schlüssel',
        'copy_public_key' => 'Öffentlichen Schlüssel kopieren',
        'copied' => 'Kopiert',
        'redirect_uri_label' => 'Redirect-URI',
        'copy_redirect_uri' => 'Redirect-URI kopieren',

        'step2_title' => 'Registriere die Anwendung in Enable Banking',
        'step2_body' => 'Öffne das Enable-Banking-Entwicklerportal, lege eine Anwendung an und füge den öffentlichen Schlüssel und die Redirect-URI aus Schritt 1 ein.',
        'open_portal' => 'Enable-Banking-Portal öffnen ↗',

        'step3_title' => 'Füge deine Anwendungs-ID ein',
        'application_id_label' => 'Anwendungs-ID',
        'step3_help' => 'Sie wird in einer lokalen Datei außerhalb der Datenbank mit restriktiven Rechten gespeichert und verlässt dieses Gerät nie.',

        'step4_title' => 'Wähle deine Bank',
        'via_enable_banking' => 'über Enable Banking',
        'other_institution' => 'Anderes Institut',
        'institution_id_placeholder' => 'Instituts-ID',

        'step5_title' => 'Schließe die Einwilligung im Browser ab',
        'step5_body' => 'Klicke unten, um den Login- und Einwilligungsbildschirm deiner Bank zu öffnen. Schließe den Login und einen eventuellen 2-Faktor-Schritt ab, dann wirst du automatisch hierher zurückgebracht, um die Aktivierung von Open Banking abzuschließen.',

        'cancel' => 'Abbrechen',
        'continue' => 'Weiter →',
        'continue_to_bank' => 'Weiter zu :bank →',
        'your_bank' => 'deiner Bank',

        'errors' => [
            'save_keypair_failed' => 'Dein Schlüsselpaar konnte nicht auf die Festplatte gespeichert werden — prüfe die Rechte deines Secrets-Verzeichnisses und versuche es erneut.',
            'generate_failed' => 'Auf diesem Gerät konnte kein Schlüsselpaar erzeugt werden — prüfe deine OpenSSL-Konfiguration.',
            'export_failed' => 'Das erzeugte Schlüsselpaar konnte nicht exportiert werden.',
            'read_public_failed' => 'Der erzeugte öffentliche Schlüssel konnte nicht gelesen werden.',
            'generate_first' => 'Erzeuge ein Schlüsselpaar, bevor du fortfährst.',
            'paste_application_id' => 'Füge die Anwendungs-ID aus dem Enable-Banking-Portal ein, bevor du fortfährst.',
            'save_application_id_failed' => 'Deine Anwendungs-ID konnte nicht auf die Festplatte gespeichert werden — prüfe die Rechte deines Secrets-Verzeichnisses und versuche es erneut.',
            'choose_bank' => 'Wähle eine Bank, bevor du fortfährst.',
        ],
    ],

    'errors' => [
        'wizard_incomplete' => 'Schließe zuerst den Open-Banking-Einrichtungsassistenten ab.',
        'no_bank_chosen' => 'Wähle eine Bank, bevor du verbindest.',
        'no_consent_url' => 'Enable Banking hat keine Einwilligungs-URL zurückgegeben.',
        'unparseable_consent_url' => 'Enable Banking hat eine nicht auswertbare Einwilligungs-URL zurückgegeben.',
        'non_public_consent_host' => 'Enable Banking hat einen nicht öffentlichen Einwilligungs-Host zurückgegeben.',
        'unsafe_consent_url' => 'Enable Banking hat eine unsichere Einwilligungs-URL zurückgegeben.',
        'no_authorization_code' => 'Der Enable-Banking-Callback hat keinen Autorisierungscode zurückgegeben.',
        'no_session_id' => 'Enable Banking hat keine Sitzungs-ID zurückgegeben.',
        'oauth_state_mismatch' => 'Dieser Verbindungslink ist abgelaufen oder wurde bereits verwendet. Starten Sie die Verbindung zu Ihrer Bank erneut.',
    ],
];
