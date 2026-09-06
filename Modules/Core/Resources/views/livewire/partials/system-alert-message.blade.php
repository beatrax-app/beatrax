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
    Blade change. A writer that knows its line puts the spec in
    `metadata.copy` and the rendered sentence in `message`: the spec
    follows this reader, and the sentence is what a household peer on
    an older build — which cannot read a spec — still shows.
--}}
@use('Modules\Core\Public\Support\Lang')
@use('Modules\Core\Public\Support\StoredCopy')
@use('Modules\Core\Internal\Enums\BackupAlertKind')
@use('Modules\Core\Internal\Enums\BackupFailureCause')
@use('Modules\Core\Public\Enums\OAuthAlertKind')
@use('Modules\Core\Public\Enums\UpdateAlertKind')
@use('Modules\EmailScan\Public\Enums\MailProvider')
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
            {{ StoredCopy::readFromParams($alert->metadata, $alert->message) }}
        @endif
        @break
    @case (UpdateAlertKind::Refused->value)
        @php
            $metadata = is_array($alert->metadata) ? $alert->metadata : [];
            $refusedVersion = isset($metadata['refusedVersion']) && is_string($metadata['refusedVersion'])
                ? $metadata['refusedVersion']
                : null;
        @endphp
        {{-- The stored line is the fallback for a row whose metadata predates
             the version key; the reader's own locale wins where it is there. --}}
        @if ($refusedVersion !== null)
            {{ Lang::get('core::alerts.messages.update_refused', ['version' => $refusedVersion]) }}
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
            {{ StoredCopy::readFromParams($alert->metadata, $alert->message) }}
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
            {{ StoredCopy::readFromParams($alert->metadata, $alert->message) }}
        @endif
        @break
    @case (BackupAlertKind::Corrupt->value)
        {{-- The kind covers every backup AND restore failure, so the sentence
             is chosen by the recorded `cause`. Choosing it from suspect_path
             alone told a reader whose disk was full, and one whose restore had
             failed, that their database had failed its integrity check. --}}
        @php
            $metadata = is_array($alert->metadata) ? $alert->metadata : [];
            $timestamp = isset($metadata['timestamp']) && is_string($metadata['timestamp'])
                ? $metadata['timestamp']
                : $alert->created_at->translatedFormat('d M Y · H:i');
            $suspectPath = isset($metadata['suspect_path']) && is_string($metadata['suspect_path']) && $metadata['suspect_path'] !== ''
                ? basename($metadata['suspect_path'])
                : null;
            $cause = isset($metadata['cause']) && is_string($metadata['cause'])
                ? BackupFailureCause::tryFrom($metadata['cause'])
                : null;
            $snapshot = isset($metadata['pre_restore_snapshot']) && is_string($metadata['pre_restore_snapshot']) && $metadata['pre_restore_snapshot'] !== ''
                ? basename($metadata['pre_restore_snapshot'])
                : null;
        @endphp
        <span aria-hidden="true">⚠</span>
        @if ($cause === BackupFailureCause::RestoreFailed && $snapshot !== null)
            {{ Lang::get('core::alerts.messages.backup_restore_failed', ['timestamp' => $timestamp, 'snapshot' => $snapshot]) }}
        @elseif ($suspectPath !== null)
            {{ Lang::get('core::alerts.messages.backup_corrupt_with_path', ['timestamp' => $timestamp, 'path' => $suspectPath]) }}
        @elseif ($cause === BackupFailureCause::SourceUnreadable || $cause === null)
            {{ Lang::get('core::alerts.messages.backup_corrupt_no_path', ['timestamp' => $timestamp]) }}
        @else
            {{ Lang::get('core::alerts.messages.backup_write_failed', ['timestamp' => $timestamp]) }}
        @endif
        @break
    @case (BackupAlertKind::Overdue->value)
        @php
            $metadata = is_array($alert->metadata) ? $alert->metadata : [];
            $hoursOld = isset($metadata['hours_old']) && is_numeric($metadata['hours_old'])
                ? (int) $metadata['hours_old']
                : null;
        @endphp
        {{-- Escaped like ordinary copy: this line named a terminal command no
             shipped bundle can open, and the <code> span went with it. A row
             with no age is the probe finding no backup at all, which the age
             sentence rendered as a backup made "0h old". --}}
        @if ($hoursOld === null)
            {{ Lang::get('core::alerts.messages.backup_none_found') }}
        @else
            {{ Lang::get('core::alerts.messages.backup_overdue', ['hours' => $hoursOld]) }}
        @endif
        @break
    @case ('wal_mode_missing')
        @php
            $metadata = is_array($alert->metadata) ? $alert->metadata : [];
            $currentMode = isset($metadata['current_mode']) && is_string($metadata['current_mode'])
                ? $metadata['current_mode']
                : 'unknown';
        @endphp
        {{-- Escaped output: the sentence carries no markup, so the pragma value
             reaches the reader through Blade rather than through an e() call
             whose result is then printed unescaped. --}}
        {{ Lang::get('core::alerts.messages.wal_mode_missing', ['mode' => $currentMode]) }}
        @break
    @case ('synchronous_misconfigured')
        @php
            $metadata = is_array($alert->metadata) ? $alert->metadata : [];
            $currentLevel = isset($metadata['current_level']) && is_numeric($metadata['current_level'])
                ? (int) $metadata['current_level']
                : -1;
        @endphp
        {{-- Escaped output, for the same reason as the pragma above. --}}
        {{ Lang::get('core::alerts.messages.synchronous_misconfigured', ['level' => $currentLevel]) }}
        @break
    @case (OAuthAlertKind::ReconsentRequired->value)
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
            $provider = ($metadata['provider'] ?? null) === MailProvider::Microsoft->value ? 'Outlook' : 'Gmail';
        @endphp
        <span>{{ Lang::get('core::alerts.messages.oauth_reconsent', ['provider' => $provider]) }}</span>
        @if ($inboxId !== null)
            <a href="/inboxes?reconnect={{ $inboxId }}" class="ml-2 inline-flex items-center font-medium text-amber-900 underline underline-offset-2 hover:text-amber-700 dark:text-amber-200 dark:hover:text-amber-100">{{ Lang::get('core::alerts.messages.reconnect_link') }}</a>
        @endif
        @break
    @case (OAuthAlertKind::ScrubSetFailed->value)
        {{ Lang::get('core::alerts.messages.oauth_scrub_set_failed') }}
        @break
    @case (OAuthAlertKind::ReauthRequired->value)
        {{-- The filename is a path on disk, so it is substituted rather than
             translated; every locale carries the sentence around it. --}}
        {{ Lang::get('core::alerts.messages.oauth_reauth_required', ['file' => 'email-oauth.json.pre-phase-12.bak']) }}
        @break
    {{-- The name is read from metadata rather than pulled out of the sentence
         it used to be baked into, so the sentence can be a translation. --}}
    @case ('auth.recovery_code_consumed')
        @php
            $metadata = is_array($alert->metadata) ? $alert->metadata : [];
            $username = isset($metadata['username']) && is_string($metadata['username']) ? $metadata['username'] : null;
        @endphp
        @if ($username !== null)
            {{ Lang::get('core::alerts.messages.auth_recovery_code_consumed', ['username' => $username]) }}
        @else
            {{ StoredCopy::readFromParams($alert->metadata, $alert->message) }}
        @endif
        @break
    @case ('auth.recovery_code_failed')
        @php
            $metadata = is_array($alert->metadata) ? $alert->metadata : [];
            $username = isset($metadata['username']) && is_string($metadata['username']) ? $metadata['username'] : null;
        @endphp
        @if ($username !== null)
            {{ Lang::get('core::alerts.messages.auth_recovery_code_failed', ['username' => $username]) }}
        @else
            {{ StoredCopy::readFromParams($alert->metadata, $alert->message) }}
        @endif
        @break
    {{-- Both branches that raise this kind mean the same thing to the reader,
         so they share one sentence; which blob failed is in metadata. --}}
    @case ('auth.lock.corrupted_key')
        {{ Lang::get('core::alerts.messages.auth_lock_corrupted_key') }}
        @break
    {{-- The cap is a constant and is never one, but a count beside a bare
         plural still has to decline in 26 languages to earn its place. It
         tells the reader nothing they can act on, so it stays in metadata. --}}
    @case ('auth.lock.hard_cap_reached')
        {{ Lang::get('core::alerts.messages.auth_lock_hard_cap_reached') }}
        @break
    @case ('auth.lock.key_material_stranded')
        {{ Lang::get('core::alerts.messages.auth_lock_key_material_stranded') }}
        @break
    @case ('auth.lock.recovery_wrap_stale')
        {{ Lang::get('core::alerts.messages.auth_lock_recovery_wrap_stale') }}
        @break
    @case ('sync.gdk.rewrap_failed')
        {{ Lang::get('core::alerts.messages.sync_gdk_rewrap_failed') }}
        @break
    {{-- These two were already translated, but at WRITE time, so the row froze
         in whatever language was active when it was raised. Rendered here they
         follow the reader instead. --}}
    @case ('worker.crashed')
        {{ Lang::get('core::alerts.messages.worker_crashed') }}
        @break
    @case ('open_banking_reconsent_required')
        {{ Lang::get('core::alerts.messages.open_banking_reconsent') }}
        @break
    {{-- The count of rows the bank sent is in metadata rather than the
         sentence: what the reader acts on is that none of them landed, and a
         number beside a plural noun has to decline in 26 languages. --}}
    @case ('open_banking_nothing_imported')
        {{ Lang::get('core::alerts.messages.open_banking_nothing_imported') }}
        @break
    @default
        {{-- The line the writer stored, or the column verbatim when it
             stored none — an older build's row, or an operator's own
             words. Both are plain strings, and Blade's `{{ }}`
             expression auto-escapes them, so a module writing an
             unsanitised string into the column cannot turn into a
             stored-XSS surface. Never swap this to `{!! !!}`. --}}
        {{ StoredCopy::readFromParams($alert->metadata, $alert->message) }}
@endswitch
