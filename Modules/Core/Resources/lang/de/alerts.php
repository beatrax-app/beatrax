<?php

declare(strict_types=1);

return [
    'banner_aria' => 'Systemmeldungen',

    'actions' => [
        'download_and_install' => 'Herunterladen und installieren',
        'download_and_install_aria' => 'Herunterladen und installieren — markiert Systemmeldung #:id als erledigt',
        'skip_version' => 'Diese Version überspringen',
        'release_notes' => 'Versionshinweise →',
        'update_now' => 'Jetzt aktualisieren',
        'update_now_aria' => 'Jetzt aktualisieren — markiert Systemmeldung #:id als erledigt',
        'remind_later' => 'Später erinnern',
        'mark_resolved' => 'Als erledigt markieren',
        'mark_resolved_aria' => 'Als erledigt markieren — Systemmeldung #:id',
        'assign_in_budgets' => 'In Budgets zuweisen',
        'dismiss' => 'Ausblenden',
        'dismiss_aria' => 'Ausblenden — Systemmeldung #:id',
    ],

    'deferred_pass' => [
        'budget-nudges' => 'die Budget-Hinweise',
        'daily-triggers' => 'die täglichen Erinnerungen und die Übersicht',
    ],

    'messages' => [
        'update_available' => 'Update verfügbar — Beatrax :version. Es wird nichts heruntergeladen, bis du dich für die Installation entscheidest; Beatrax schließt sich dann und öffnet sich in der neuen Version wieder.',
        'update_refused' => 'Beatrax hat Version :version heruntergeladen und die Installation verweigert — die Datei stimmte nicht mit der Signatur des Herausgebers überein, es wurde also nichts auf diesem Gerät verändert. Ein beschädigter Download kann das auslösen. Wenn es weiterhin passiert, installiere Beatrax nicht aus dieser Quelle.',
        'update_stale' => 'Du nutzt Version :current — Version :latest ist seit 30 Tagen verfügbar. Aktualisiere jetzt.',
        'update_critical' => 'Kritisches Update verfügbar — Version :version behebt :summary. Installiere es so bald wie möglich.',
        'backup_corrupt_with_path' => 'Das um :timestamp geschriebene Backup hat die Integritätsprüfung nicht bestanden. Prüfe :path. Behebe das, bevor du dich auf Backups verlässt.',
        'backup_corrupt_no_path' => 'Das um :timestamp versuchte Backup wurde abgebrochen, bevor überhaupt eine Datei entstand — die Quell-DB hat die Integritätsprüfung nicht bestanden. Behebe das, bevor du dich auf Backups verlässt.',
        'backup_write_failed' => 'Das um :timestamp begonnene Backup wurde nicht abgeschlossen — die Datenbank hat ihre Prüfungen bestanden, die Backup-Dateien konnten nicht geschrieben werden. Prüfe freien Speicher und Rechte am Backup-Ordner.',
        'backup_restore_failed' => 'Die um :timestamp begonnene Wiederherstellung wurde nicht abgeschlossen. Deine bisherigen Daten wurden zuvor in :snapshot gesichert.',

        'backup_overdue' => 'Das letzte geprüfte Backup ist :hoursh alt. Beatrax erstellt dieses Backup selbst, einmal am Tag, solange die App geöffnet ist — von Hand ist nichts auszuführen. Bleibt es so alt, war die App nicht geöffnet, als ein täglicher Lauf anstand.',
        'backup_none_found' => 'Im Backup-Ordner wurde kein geprüftes Backup gefunden. Beatrax erstellt dieses Backup selbst, einmal am Tag, solange die App geöffnet ist — von Hand ist nichts auszuführen.',
        'wal_mode_missing' => 'Die Datenbank läuft nicht im WAL-Modus (aktuell :mode), daher kann das Speichern stocken, während eine Hintergrundaufgabe läuft. Beatrax setzt WAL bei jedem Start, ein Neustart behebt das also meistens.',
        'synchronous_misconfigured' => 'Die Haltbarkeitsstufe der Datenbank ist :level statt des erwarteten NORMAL. Beatrax setzt das bei jedem Start, ein Neustart behebt es also meistens.',
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
        'auth_lock_key_material_stranded' => 'Die Verschlüsselung im Ruhezustand ist für dieses Konto aktiv, aber keine App-Sperr-Hülle hält mehr den Datenschlüssel, sodass jede verschlüsselte Notiz, Beschreibung und Gegenpartei-Angabe als leer gelesen wird. Stellen Sie ein verschlüsseltes Backup wieder her, das erstellt wurde, solange der Schlüssel noch funktionierte, oder richten Sie dieses Konto erneut auf einem Gerät ein, das ihn noch hat.',
        'auth_lock_recovery_wrap_stale' => 'Das Kontopasswort wurde geändert, ohne dass die Wiederherstellungs-Hülle der App-Sperre neu verpackt wurde, sodass dieses Passwort die App-Sperre nicht mehr öffnet. Die PIN tut es weiterhin. Verknüpfen Sie das Kontopasswort in den App-Sperr-Einstellungen erneut, solange die PIN noch bekannt ist — sonst bleibt nach einer vergessenen PIN nichts übrig.',
        'reconnect_link' => 'Erneut verbinden →',
        'pots_category_link_retired' => 'Die Umschlag-Budgetierung hat kategoriegebundene Rücklagen abgelöst. Der Betrag von :amount aus :count archivierter Rücklage ist wieder nicht zugeteilt und wartet darauf, dass Sie ihn zuweisen.|Die Umschlag-Budgetierung hat kategoriegebundene Rücklagen abgelöst. Der Betrag von :amount aus :count archivierten Rücklagen ist wieder nicht zugeteilt und wartet darauf, dass Sie ihn zuweisen.',
        'notifications_deferred_pass_failed' => 'Beatrax konnte :pass auf diesem Gerät nicht ermitteln, daher fehlen möglicherweise einige. Beim nächsten Öffnen der App wird es erneut versucht.',
    ],
];
