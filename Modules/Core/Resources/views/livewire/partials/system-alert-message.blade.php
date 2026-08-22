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
@use('Modules\Core\Public\Support\Lang')
@use('Modules\Core\Public\Enums\UpdateAlertKind')
@switch ($alert->kind)
    @case (UpdateAlertKind::Available->value)
        @php
            $metadata = is_array($alert->metadata) ? $alert->metadata : [];
            $latestVersion = isset($metadata['latestVersion']) && is_string($metadata['latestVersion'])
                ? $metadata['latestVersion']
                : null;
        @endphp
        @if ($latestVersion !== null)
            {{ Lang::get('core::alerts.messages.update_available', ['version' => $latestVersion]) }}
        @else
            {{ $alert->message }}
        @endif
        @break
    @case (UpdateAlertKind::Stale->value)
        @php
            $metadata = is_array($alert->metadata) ? $alert->metadata : [];
            $currentVersion = isset($metadata['currentVersion']) && is_string($metadata['currentVersion'])
                ? $metadata['currentVersion']
                : null;
            $latestVersion = isset($metadata['latestVersion']) && is_string($metadata['latestVersion'])
                ? $metadata['latestVersion']
                : null;
        @endphp
        @if ($currentVersion !== null && $latestVersion !== null)
            {{-- App-static copy carries an apostrophe that must reach the DOM
                 unescaped; the dynamic version values are individually escaped
                 with e() before substitution, so no untrusted markup leaks. --}}
            {!! Lang::get('core::alerts.messages.update_stale', ['current' => e($currentVersion), 'latest' => e($latestVersion)]) !!}
        @else
            {{ $alert->message }}
        @endif
        @break
    @case (UpdateAlertKind::Critical->value)
        @php
            $metadata = is_array($alert->metadata) ? $alert->metadata : [];
            $newVersion = isset($metadata['newVersion']) && is_string($metadata['newVersion'])
                ? $metadata['newVersion']
                : null;
            $summary = isset($metadata['summary']) && is_string($metadata['summary'])
                ? $metadata['summary']
                : null;
        @endphp
        @if ($newVersion !== null && $summary !== null)
            {{ Lang::get('core::alerts.messages.update_critical', ['version' => $newVersion, 'summary' => $summary]) }}
        @else
            {{ $alert->message }}
        @endif
        @break
    @case ('backup_corrupt')
        @php
            $metadata = is_array($alert->metadata) ? $alert->metadata : [];
            $timestamp = isset($metadata['timestamp']) && is_string($metadata['timestamp'])
                ? $metadata['timestamp']
                : $alert->created_at->translatedFormat('d M Y · H:i');
            $suspectPath = isset($metadata['suspect_path']) && is_string($metadata['suspect_path']) && $metadata['suspect_path'] !== ''
                ? basename($metadata['suspect_path'])
                : null;
        @endphp
        <span aria-hidden="true">⚠</span>
        @if ($suspectPath !== null)
            {{ Lang::get('core::alerts.messages.backup_corrupt_with_path', ['timestamp' => $timestamp, 'path' => $suspectPath]) }}
        @else
            {{ Lang::get('core::alerts.messages.backup_corrupt_no_path', ['timestamp' => $timestamp]) }}
        @endif
        @break
    @case ('backup_overdue')
        @php
            $metadata = is_array($alert->metadata) ? $alert->metadata : [];
            $hoursOld = isset($metadata['hours_old']) && is_numeric($metadata['hours_old'])
                ? (int) $metadata['hours_old']
                : 0;
        @endphp
        {{-- App-static copy with an inline <code> span; :hours is an integer. --}}
        {!! Lang::get('core::alerts.messages.backup_overdue', ['hours' => $hoursOld]) !!}
        @break
    @case ('wal_mode_missing')
        @php
            $metadata = is_array($alert->metadata) ? $alert->metadata : [];
            $currentMode = isset($metadata['current_mode']) && is_string($metadata['current_mode'])
                ? $metadata['current_mode']
                : 'unknown';
        @endphp
        {{-- App-static copy with an inline <code> span; the operator-controlled
             :mode value is escaped with e() before substitution. --}}
        {!! Lang::get('core::alerts.messages.wal_mode_missing', ['mode' => e($currentMode)]) !!}
        @break
    @case ('synchronous_misconfigured')
        @php
            $metadata = is_array($alert->metadata) ? $alert->metadata : [];
            $currentLevel = isset($metadata['current_level']) && is_numeric($metadata['current_level'])
                ? (int) $metadata['current_level']
                : -1;
        @endphp
        {{-- App-static copy with an inline <code> span; :level is an integer. --}}
        {!! Lang::get('core::alerts.messages.synchronous_misconfigured', ['level' => $currentLevel]) !!}
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
            <a href="/inboxes?reconnect={{ $inboxId }}" class="ml-2 inline-flex items-center font-medium text-amber-900 underline underline-offset-2 hover:text-amber-700 dark:text-amber-200 dark:hover:text-amber-100">{{ Lang::get('core::alerts.messages.reconnect_link') }}</a>
        @endif
        @break
    @default
        {{-- $alert->message is operator-authored text from
             BackupDatabaseCommand::recordCorruptAlert,
             HealthCheckListener::recordDriftAlert, and
             BackupFreshnessProbe::recordOverdueAlert. Blade's `{{ }}`
             expression auto-escapes HTML, so a future module writing
             an unsanitised string into the column cannot turn into a
             stored-XSS surface. Never swap this to `{!! !!}`. --}}
        {{ $alert->message }}
@endswitch
