{{--
    Renders the headline message for a single system_alerts row.

    Variables in scope:
      - $alert : Modules\Core\Models\SystemAlert

    Templates are LOCKED per UI-SPEC §Severity x Kind Copywriting
    Contract. Operator-controlled fields (suspect_path basename,
    current_mode, current_level, hours_old) flow through Blade
    default-escaped interpolation — unescaped Blade output is forbidden.

    Unknown kinds fall through to the row's own `message` column so
    future modules can write rows of new kinds without an immediate
    Blade change.
--}}
@switch ($alert->kind)
    @case ('backup_corrupt')
        @php
            $metadata = is_array($alert->metadata) ? $alert->metadata : [];
            $timestamp = isset($metadata['timestamp']) && is_string($metadata['timestamp'])
                ? $metadata['timestamp']
                : $alert->created_at->format('d M Y · H:i');
            $suspectPath = isset($metadata['suspect_path']) && is_string($metadata['suspect_path']) && $metadata['suspect_path'] !== ''
                ? basename($metadata['suspect_path'])
                : null;
        @endphp
        <span aria-hidden="true">⚠</span>
        @if ($suspectPath !== null)
            The backup written at {{ $timestamp }} failed integrity check. Inspect {{ $suspectPath }}. Resolve before relying on backups.
        @else
            The backup attempted at {{ $timestamp }} aborted before any file was produced — source DB failed integrity check. Resolve before relying on backups.
        @endif
        @break
    @case ('backup_overdue')
        @php
            $metadata = is_array($alert->metadata) ? $alert->metadata : [];
            $hoursOld = isset($metadata['hours_old']) && is_numeric($metadata['hours_old'])
                ? (int) $metadata['hours_old']
                : 0;
        @endphp
        The most recent verified backup is {{ $hoursOld }}h old. Run <code class="rounded bg-amber-100 px-1 text-amber-900 dark:bg-amber-900 dark:text-amber-200">php artisan db:backup</code> or wait for the 03:00 scheduled run.
        @break
    @case ('wal_mode_missing')
        @php
            $metadata = is_array($alert->metadata) ? $alert->metadata : [];
            $currentMode = isset($metadata['current_mode']) && is_string($metadata['current_mode'])
                ? $metadata['current_mode']
                : 'unknown';
        @endphp
        SQLite is not in WAL mode (currently {{ $currentMode }}). Concurrent writes may stall. Run <code class="rounded bg-amber-100 px-1 text-amber-900 dark:bg-amber-900 dark:text-amber-200">php artisan beatrax:doctor</code> for guidance.
        @break
    @case ('synchronous_misconfigured')
        @php
            $metadata = is_array($alert->metadata) ? $alert->metadata : [];
            $currentLevel = isset($metadata['current_level']) && is_numeric($metadata['current_level'])
                ? (int) $metadata['current_level']
                : -1;
        @endphp
        SQLite synchronous level is {{ $currentLevel }} (expected NORMAL/1). Durability semantics may differ from config. Run <code class="rounded bg-amber-100 px-1 text-amber-900 dark:bg-amber-900 dark:text-amber-200">php artisan beatrax:doctor</code> for guidance.
        @break
    @case ('oauth_reconsent_required')
        {{--
            Re-consent prompt surfaced when the background inbox scanner
            catches an invalid_grant / consent_required failure on token
            refresh. The metadata blob carries `inbox_id` (int) +
            `provider` ('gmail' | 'microsoft'); the `message` column is
            the locked literal "Reconnect your Gmail" / "Reconnect your
            Outlook" written by RaiseReconsentAlertOnTokenFailure. The
            Reconnect link routes to /inboxes?reconnect={inbox_id} where
            InboxesPage auto-opens the OAuthClientWizardModal against
            the existing inbox row, preserving inbox_messages + .eml
            blobs + the cursor.
        --}}
        @php
            $metadata = is_array($alert->metadata) ? $alert->metadata : [];
            $inboxId = isset($metadata['inbox_id']) && is_numeric($metadata['inbox_id'])
                ? (int) $metadata['inbox_id']
                : null;
        @endphp
        <span>{{ $alert->message }}</span>
        @if ($inboxId !== null)
            <a href="/inboxes?reconnect={{ $inboxId }}" class="ml-2 inline-flex items-center font-medium text-amber-900 underline underline-offset-2 hover:text-amber-700 dark:text-amber-200 dark:hover:text-amber-100">Reconnect →</a>
        @endif
        @break
    @default
        {{-- $alert->message is operator-authored text from
             BackupDatabaseCommand::recordCorruptAlert,
             HealthCheckServiceProvider::recordDriftAlert, and
             BackupFreshnessProbe::recordOverdueAlert. Blade's `{{ }}`
             expression auto-escapes HTML, so a future module writing
             an unsanitised string into the column cannot turn into a
             stored-XSS surface. Never swap this to `{!! !!}`. --}}
        {{ $alert->message }}
@endswitch
