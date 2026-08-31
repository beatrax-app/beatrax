<?php

declare(strict_types=1);

return [
    'banner_aria' => 'Systemmeldungen',

    'actions' => [
        'install_next_launch' => 'Beim nächsten Start installieren',
        'install_next_launch_aria' => 'Beim nächsten Start installieren — markiert Systemmeldung #:id als erledigt',
        'skip_version' => 'Diese Version überspringen',
        'release_notes' => 'Versionshinweise →',
        'update_now' => 'Jetzt aktualisieren',
        'update_now_aria' => 'Jetzt aktualisieren — markiert Systemmeldung #:id als erledigt',
        'remind_later' => 'Später erinnern',
        'mark_resolved' => 'Als erledigt markieren',
        'mark_resolved_aria' => 'Als erledigt markieren — Systemmeldung #:id',
    ],

    'messages' => [
        'update_available' => 'Update verfügbar — Beatrax :version steht bereit. Es wird beim nächsten Start installiert.',
        'update_stale' => 'Du nutzt Version :current — Version :latest ist seit 30 Tagen verfügbar. Aktualisiere jetzt.',
        'update_critical' => 'Kritisches Update verfügbar — Version :version behebt :summary. Installiere es so bald wie möglich.',
        'backup_corrupt_with_path' => 'Das um :timestamp geschriebene Backup hat die Integritätsprüfung nicht bestanden. Prüfe :path. Behebe das, bevor du dich auf Backups verlässt.',
        'backup_corrupt_no_path' => 'Das um :timestamp versuchte Backup wurde abgebrochen, bevor überhaupt eine Datei entstand — die Quell-DB hat die Integritätsprüfung nicht bestanden. Behebe das, bevor du dich auf Backups verlässt.',
        'backup_write_failed' => 'Das um :timestamp begonnene Backup wurde nicht abgeschlossen — die Datenbank hat ihre Prüfungen bestanden, die Backup-Dateien konnten nicht geschrieben werden. Prüfe freien Speicher und Rechte am Backup-Ordner.',
        'backup_restore_failed' => 'Die um :timestamp begonnene Wiederherstellung wurde nicht abgeschlossen. Deine bisherigen Daten wurden zuvor in :snapshot gesichert.',

        'backup_overdue' => 'Das letzte geprüfte Backup ist :hoursh alt. Beatrax erstellt dieses Backup selbst, einmal am Tag, solange die App geöffnet ist — von Hand ist nichts auszuführen. Bleibt es so alt, war die App nicht geöffnet, als ein täglicher Lauf anstand.',
        'backup_none_found' => 'Im Backup-Ordner wurde kein geprüftes Backup gefunden. Beatrax erstellt dieses Backup selbst, einmal am Tag, solange die App geöffnet ist — von Hand ist nichts auszuführen.',
        'wal_mode_missing' => 'SQLite läuft nicht im WAL-Modus (aktuell :mode). Gleichzeitige Schreibvorgänge können hängen bleiben. Führe <code class="rounded bg-amber-100 px-1 text-amber-900 dark:bg-amber-900 dark:text-amber-200">php artisan beatrax:doctor</code> für Hinweise aus.',
        'synchronous_misconfigured' => 'Der SQLite-synchronous-Level ist :level (erwartet NORMAL/1). Die Dauerhaftigkeitssemantik kann von der Konfiguration abweichen. Führe <code class="rounded bg-amber-100 px-1 text-amber-900 dark:bg-amber-900 dark:text-amber-200">php artisan beatrax:doctor</code> für Hinweise aus.',
        'oauth_scrub_set_failed' => 'Die Schwärzung von OAuth-Geheimnissen ist außer Betrieb. Protokolle und Audit-Auszüge können bis zum nächsten erfolgreichen Laden ungeschwärzte Tokens enthalten.',
        'oauth_reauth_required' => 'OAuth-Geheimnisse wurden in benutzerspezifischen Speicher verschoben. Autorisieren Sie Gmail und Microsoft erneut, um das E-Mail-Scannen fortzusetzen. Die alte Geheimnisdatei wurde für ein Rollback in :file umbenannt.',
        'oauth_reconsent' => 'Verbinden Sie Ihr :provider-Konto erneut',
        'auth_recovery_code_consumed' => 'Wiederherstellungscode von :username verwendet.',
        'auth_recovery_code_failed' => 'Fehlgeschlagener Wiederherstellungscode-Versuch für :username.',
        'auth_lock_hard_cap_reached' => 'Nach zu vielen fehlgeschlagenen PIN-Versuchen abgemeldet.',
        'open_banking_reconsent' => 'Verbinden Sie Ihre Bank erneut',
        'open_banking_nothing_imported' => 'Ihre Bank hat Transaktionen geschickt, aber Beatrax konnte keine davon verbuchen, sodass nichts in Ihren Aufzeichnungen angekommen ist. Öffnen Sie die Einstellungen unter Open Banking, um zu sehen warum.',
        'auth_lock_corrupted_key' => 'Ihre PIN kann die App-Sperre auf diesem Gerät nicht öffnen: der gespeicherte Schlüssel ist unlesbar. Melden Sie sich mit Ihrem Kontopasswort an, um eine neue PIN festzulegen.',
        'sync_gdk_rewrap_failed' => 'Das erneute Verpacken des GDK-Schlüsselbunds nach einer Änderung der App-Sperr-Passphrase ist fehlgeschlagen — verschlüsselte Daten sind möglicherweise nicht wiederherstellbar, bis der Schlüsselbund neu verpackt wurde.',
        'worker_crashed' => 'Die Hintergrundverarbeitung von Beatrax wurde unerwartet beendet. Importe und E-Mail-Scans sind pausiert. Öffnen Sie die App erneut, um sie neu zu starten.',
        'auth_lock_key_material_stranded' => 'Die Verschlüsselung im Ruhezustand ist für dieses Konto aktiv, aber keine App-Sperr-Hülle hält mehr den Datenschlüssel, sodass jede verschlüsselte Notiz, Beschreibung und Gegenpartei-Angabe als leer gelesen wird. Die Kopplung mit einem Gerät, das den Schlüssel noch hat, ist der einzige Weg zurück.',
        'auth_lock_recovery_wrap_stale' => 'Das Kontopasswort wurde geändert, ohne dass die Wiederherstellungs-Hülle der App-Sperre neu verpackt wurde, sodass dieses Passwort die App-Sperre nicht mehr öffnet. Die PIN tut es weiterhin. Verknüpfen Sie das Kontopasswort in den App-Sperr-Einstellungen erneut, solange die PIN noch bekannt ist — sonst bleibt nach einer vergessenen PIN nichts übrig.',
        'reconnect_link' => 'Erneut verbinden →',
    ],
];
