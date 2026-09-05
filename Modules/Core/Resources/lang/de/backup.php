<?php

declare(strict_types=1);

return [
    'download' => [
        'no_download_route' => 'Diese App kann deinem Gerät keine Datei übergeben, deshalb entsteht das verschlüsselte Backup stattdessen in der Desktop-App. Koppele dieses Gerät, um beide synchron zu halten.',
        'unavailable' => 'Verschlüsselte Backups gibt es in der Desktop-Version (SQLite). Nutze bei einer Server-Datenbank die eigenen Backup-Werkzeuge deiner Datenbank.',
        'intro' => 'Lade eine mit einer Passphrase verschlüsselte Kopie deiner gesamten Datenbank herunter — du kannst sie bedenkenlos auf einer externen Festplatte oder in der Cloud aufbewahren, denn ohne die Passphrase ist sie unlesbar (quantensicheres XChaCha20-Poly1305 + Argon2id).',
        'passphrase' => 'Passphrase',
        'confirm_passphrase' => 'Passphrase bestätigen',
        'keep_safe' => 'Bewahre die Passphrase sicher auf — ohne sie lässt sich das Backup nicht wiederherstellen.',
        'submit' => 'Verschlüsseltes Backup herunterladen',
        'preparing' => 'Wird vorbereitet…',
    ],

    'restore' => [
        'heading' => 'Aus einem Backup wiederherstellen',

        'intro_html' => 'Ersetze deine aktuelle Datenbank durch ein verschlüsseltes Backup. Die Datei wird entschlüsselt und geprüft, bevor sich etwas ändert, und zuerst wird eine Momentaufnahme deiner aktuellen Daten gesichert — trotzdem <strong class="text-slate-700 dark:text-slate-200">überschreibt das alles</strong>, deshalb ist es abgesichert. Du wirst abgemeldet, denn deine Anmeldung liegt ebenfalls in der Datenbank.',
        'restored' => 'Ihre Sicherung wurde wiederhergestellt. Melden Sie sich mit dem Benutzernamen und Passwort an, die bei ihrer Erstellung galten.',
        'snapshot_saved_prefix' => 'Eine Momentaufnahme deiner bisherigen Daten wurde gespeichert unter',
        'file_label' => 'Backup-Datei (.enc) oder Export-Archiv (.zip)',
        'uploading' => 'Wird hochgeladen…',
        'passphrase' => 'Passphrase',
        'confirm_prefix' => 'Tippe',
        'confirm_suffix' => 'zur Bestätigung',
        'submit' => 'Wiederherstellen (überschreibt aktuelle Daten)',
        'restoring' => 'Wird wiederhergestellt…',
    ],

    'errors' => [
        'passphrase_min' => 'Verwende eine Passphrase mit mindestens :min Zeichen.|Verwende eine Passphrase mit mindestens :min Zeichen.',
        'passphrase_mismatch' => 'Die beiden Passphrasen stimmen nicht überein.',
        'download_sqlite_only' => 'Der verschlüsselte Download ist nur in der SQLite-Version verfügbar.',
        'create_failed' => 'Backup konnte nicht erstellt werden: :message',
        'confirm_phrase' => 'Tippe :phrase zur Bestätigung — das ersetzt deine aktuellen Daten.',
        'choose_file' => 'Wähle, woraus wiederhergestellt werden soll: die .enc-Backup-Datei oder die .zip, die der Export mit einem Klick geschrieben hat.',
        'upload_failed' => 'Die Datei wurde nicht vollständig hochgeladen. Sie ist möglicherweise zu groß für dieses Gerät — die Wiederherstellung in der Desktop-App akzeptiert eine größere Sicherung.',
        'enter_passphrase' => 'Gib die Passphrase ein, mit der das Backup verschlüsselt wurde.',
        'unreadable' => 'Die hochgeladene Datei konnte nicht gelesen werden. Versuche es erneut.',
        'restore_wrong_passphrase' => 'Diese Passphrase hat das Backup nicht geöffnet, und nichts wurde geändert. Tippe sie neu ein und versuche es erneut. Ist sie sicher richtig, wurde die Datei seit ihrer Erstellung verändert — stelle dann aus einer anderen Kopie wieder her.',
        'restore_not_a_backup' => 'Diese Datei enthält kein Beatrax-Backup, also gibt es darin nichts wiederherzustellen, und nichts wurde geändert. Wähle die .enc-Datei, die die App beim Erstellen des Backups geschrieben hat, oder die .zip aus dem Export mit einem Klick.',
        'restore_contents_unreadable' => 'Das Backup ließ sich öffnen, aber die Datenbank darin ist beschädigt, wurde daher nicht wiederhergestellt, und nichts wurde geändert. Stelle aus einem älteren Backup wieder her.',
        'restore_could_not_read' => 'Die Backup-Datei konnte nicht gelesen werden, die Wiederherstellung lief daher nicht, und nichts wurde geändert. Prüfe, ob dieses Gerät freien Speicher hat, und versuche es erneut.',
        'restore_not_supported' => 'Wiederherstellen funktioniert auf der Variante, die ihre Daten in einer einzigen Datei hält — diese ist es nicht, und nichts wurde geändert. Nutze bei einer Serverdatenbank deren eigene Wiederherstellungswerkzeuge.',
        'restore_failed' => 'Die Wiederherstellung lief nicht, und nichts wurde geändert. Versuche es erneut — schlägt es weiter fehl, hält das App-Protokoll fest, was sie gestoppt hat.',
    ],
];
