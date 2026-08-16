<?php

declare(strict_types=1);

return [
    'download' => [
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

        'intro_html' => 'Ersetze deine aktuelle Datenbank durch ein verschlüsseltes Backup. Die Datei wird entschlüsselt und geprüft, bevor sich etwas ändert, und zuerst wird eine Momentaufnahme deiner aktuellen Daten gesichert — trotzdem <strong class="text-slate-700 dark:text-slate-200">überschreibt das alles</strong>, deshalb ist es abgesichert.',
        'restored' => 'Wiederhergestellt. Lade die App neu, um deine wiederhergestellten Daten zu sehen.',
        'snapshot_saved_prefix' => 'Eine Momentaufnahme deiner bisherigen Daten wurde gespeichert unter',
        'file_label' => 'Verschlüsseltes Backup (.enc)',
        'uploading' => 'Wird hochgeladen…',
        'passphrase' => 'Passphrase',
        'confirm_prefix' => 'Tippe',
        'confirm_suffix' => 'zur Bestätigung',
        'submit' => 'Wiederherstellen (überschreibt aktuelle Daten)',
        'restoring' => 'Wird wiederhergestellt…',
    ],

    'errors' => [
        'passphrase_min' => 'Verwende eine Passphrase mit mindestens :min Zeichen.',
        'passphrase_mismatch' => 'Die beiden Passphrasen stimmen nicht überein.',
        'download_sqlite_only' => 'Der verschlüsselte Download ist nur in der SQLite-Version verfügbar.',
        'create_failed' => 'Backup konnte nicht erstellt werden: :message',
        'confirm_phrase' => 'Tippe :phrase zur Bestätigung — das ersetzt deine aktuellen Daten.',
        'choose_file' => 'Wähle eine verschlüsselte Backup-Datei (.enc) zum Wiederherstellen.',
        'enter_passphrase' => 'Gib die Passphrase ein, mit der das Backup verschlüsselt wurde.',
        'unreadable' => 'Die hochgeladene Datei konnte nicht gelesen werden. Versuche es erneut.',
    ],
];
