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

        'backup_overdue' => 'Das letzte geprüfte Backup ist :hoursh alt. Führe <code class="rounded bg-amber-100 px-1 text-amber-900 dark:bg-amber-900 dark:text-amber-200">php artisan db:backup</code> aus oder warte auf den geplanten Lauf um 03:00.',
        'wal_mode_missing' => 'SQLite läuft nicht im WAL-Modus (aktuell :mode). Gleichzeitige Schreibvorgänge können hängen bleiben. Führe <code class="rounded bg-amber-100 px-1 text-amber-900 dark:bg-amber-900 dark:text-amber-200">php artisan Beatrax:doctor</code> für Hinweise aus.',
        'synchronous_misconfigured' => 'Der SQLite-synchronous-Level ist :level (erwartet NORMAL/1). Die Dauerhaftigkeitssemantik kann von der Konfiguration abweichen. Führe <code class="rounded bg-amber-100 px-1 text-amber-900 dark:bg-amber-900 dark:text-amber-200">php artisan Beatrax:doctor</code> für Hinweise aus.',
        'oauth_scrub_set_failed' => 'Die Schwärzung von OAuth-Geheimnissen ist außer Betrieb. Protokolle und Audit-Auszüge können bis zum nächsten erfolgreichen Laden ungeschwärzte Tokens enthalten.',
        'oauth_reauth_required' => 'OAuth-Geheimnisse wurden in benutzerspezifischen Speicher verschoben. Autorisieren Sie Gmail und Microsoft erneut, um das E-Mail-Scannen fortzusetzen. Die alte Geheimnisdatei wurde für ein Rollback in :file umbenannt.',
        'oauth_reconsent' => 'Verbinden Sie Ihr :provider-Konto erneut',
        'reconnect_link' => 'Erneut verbinden →',
    ],
];
