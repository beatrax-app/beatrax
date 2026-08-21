<?php

declare(strict_types=1);

return [
    'heading' => 'Geräte & Synchronisierung',

    'enable_sync' => 'Synchronisierung aktivieren',
    'enable_sync_help' => 'Teile deine Daten sicher zwischen vertrauenswürdigen Geräten. Erfordert eine App-Sperre.',

    'app_lock_notice' => 'Richte zuerst eine App-Sperre ein, um die Synchronisierung zu aktivieren.',
    'go_to_app_lock' => 'Zur App-Sperre',

    'encrypted_at_rest' => 'Daten im Ruhezustand verschlüsselt',
    'encrypted_at_rest_scope' => 'Notizen, Buchungstexte und die Namen und IBANs deiner Zahlungsempfänger sind im Buchungsbestand mit der Passphrase deiner App-Sperre verschlüsselt. Beträge, Daten sowie dein eigener Kontoname und deine eigene IBAN sind es nicht. Der Suchindex behält eine eigene lesbare Kopie davon, wen du bezahlst, deiner Buchungstexte und deiner Steuernotizen, und einige Händlernamen stehen im Klartext an anderer Stelle in der Datenbankdatei.',
    'on' => 'An',
    'securing' => 'Deine Daten werden geschützt…',
    'do_not_close' => 'Schließe dieses Fenster nicht.',
    'encryption_progress_aria' => 'Fortschritt der Verschlüsselung',
    'not_encrypted_offer' => 'Ihre Daten sind nicht verschlüsselt gespeichert. Verschlüsselung verbirgt, wen Sie bezahlen, falls dieses Gerät verloren geht oder gestohlen wird — Beträge, Daten und der Suchindex bleiben lesbar.',
    'enable_encryption' => 'Verschlüsselung aktivieren',

    'your_devices' => 'Deine Geräte',

    'moved_help' => 'Kopplung, Gerätenamen und Verschlüsselung findest du jetzt bei deinem Sync-Status.',
    'moved_cta' => 'Sync & Gerät öffnen',
    'device_name' => 'Gerätename',
    'save' => 'Speichern',
    'peer_default_name' => 'Gekoppeltes Gerät',
    'rename_device' => 'Gerät umbenennen',
    'this_device' => 'Dieses Gerät',
    'removed' => 'Entfernt',
    'confirmed' => 'Bestätigt',
    'awaiting_confirmation' => 'Warten auf Bestätigung',
    'safety_number_words' => 'Sicherheitsnummer-Wörter:',
    'paired' => 'Gekoppelt',
    'remove_aria' => ':name entfernen',
    'remove' => 'Entfernen',
    'pair_new_device' => 'Neues Gerät koppeln',

    'relay_endpoint' => 'Relay-Endpunkt',
    'relay_endpoint_help' => 'Optional. Wenn gesetzt, synchronisieren Offline-Geräte über dieses Relay. Leer lassen, wenn nur LAN&#8209;direkt genutzt werden soll.',
    'relay_endpoint_aria' => 'Relay-Endpunkt-URL',
    'relay_insecure_warning' => 'Dieser Relay-Endpunkt nutzt einfaches HTTP. Das Relay entschlüsselt deine Daten zwar nie, aber eine unsichere Verbindung gibt verschlüsselte Größen und Zeitpunkte gegenüber Beobachtern im Netzwerk preis. Nutze für beste Privatsphäre einen <strong>https://</strong>-Endpunkt.',

    'enable_at_rest' => 'Verschlüsselung im Ruhezustand aktivieren',
    'enable_at_rest_body' => 'Deine Daten werden mit der Passphrase deiner App-Sperre verschlüsselt. Vor der Migration wird automatisch ein Backup erstellt.',
    'no_recovery_warning' => 'Wenn du die Passphrase deiner App-Sperre verlierst und kein Backup und kein anderes vertrauenswürdiges Gerät hast, lassen sich deine Daten nicht wiederherstellen.',
    'recover_help' => 'Um den Zugriff wiederzuerlangen, koppele dieses Gerät von einem anderen vertrauenswürdigen Gerät neu oder nutze dein eigenes verschlüsseltes Backup.',
    'amounts_plaintext' => 'Beträge werden im Ruhezustand nicht verschlüsselt — Salden und Summen bleiben lesbar, damit deine Monatssummen weiter korrekt zusammenzählen.',
    'search_plaintext' => 'Der Suchindex behält eine Klartextkopie von Händler- und Beschreibungstext, damit die Volltextsuche weiter funktioniert.',
    'keep_unencrypted' => 'Daten unverschlüsselt lassen',
    'encryption_enabled' => 'Verschlüsselung aktiviert',
    'encryption_enabled_scope' => 'Notizen, Buchungstexte und deine Zahlungsempfänger sind jetzt mit der Passphrase deiner App-Sperre verschlüsselt. Beträge, Daten und der Suchindex bleiben lesbar.',
    'done_encryption_enabled' => 'Fertig — Verschlüsselung aktiviert',
    'encryption_failed' => 'Einrichtung der Verschlüsselung fehlgeschlagen',
    'encryption_failed_body' => 'Deine Daten wurden nicht geändert. Dein Backup wurde bewahrt.',
    'close_no_changes' => 'Schließen — keine Änderungen vorgenommen',

    'remove_this_device' => 'Dieses Gerät entfernen',
    'removing' => 'Wird entfernt:',
    'remove_rotates_key' => 'Beim Entfernen dieses Geräts wird der Verschlüsselungsschlüssel rotiert, sodass es keine weiteren Updates mehr erhält.',
    'remove_cannot_erase' => 'Daten, die schon auf dem Gerät liegen, lassen sich damit nicht löschen. Wenn dieses Gerät verloren ging oder gestohlen wurde, betrachte alle Daten darauf als offengelegt.',
    'remove_device' => 'Gerät entfernen',
    'keep_device' => 'Gerät behalten',
    'rotating_key' => 'Verschlüsselungsschlüssel wird rotiert…',

    'flash' => [
        'app_lock_first' => 'Richte zuerst eine App-Sperre ein, um die Synchronisierung zu aktivieren.',
        'enable_failed' => 'Synchronisierung konnte nicht aktiviert werden. Prüfe, ob deine App-Sperre aktiv ist, und versuche es erneut.',
        'cannot_remove_self' => 'Du kannst dieses Gerät nicht entfernen — es ist das Gerät, das du gerade nutzt.',
        'remove_failed' => 'Gerät konnte nicht entfernt werden. Versuche es erneut.',
        'app_lock_first_settings' => 'Richte zuerst eine App-Sperre ein, um die Synchronisierungseinstellungen zu ändern.',
        'relay_cleared' => 'Relay-Endpunkt gelöscht.',
        'relay_saved' => 'Relay-Endpunkt gespeichert.',
        'relay_save_failed' => 'Relay-Endpunkt konnte nicht gespeichert werden: :message',
    ],
];
