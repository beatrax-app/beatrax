{{-- Dashboard "Email scan health" tile partial.

     Props:
       $tile — Modules\EmailScan\Public\Dto\EmailScanHealthTile DTO.

     The host dashboard view (`shell::livewire.dashboard`) wraps this
     partial in an anchor to the Email destination so the whole
     card is clickable; the wrapper anchor owns the hover affordance. The
     partial body lists up to three per-inbox lines + an optional
     "+N more" footer when more inboxes are connected.

     Per-line dot colour:
       healthy     → emerald-600
       unscheduled → slate-400
       stale       → amber-700
       reauth      → rose-600
     `unscheduled` is the state of an inbox on a device that schedules no
     scan, so it is drawn as the neutral it is rather than as a warning
     nothing on that device can ever clear.
     The tile heading itself stays `text-slate-900` regardless of
     overall status — the calm aesthetic forbids painting the tile
     title red (UI-SPEC § Typography / Email-scan-health tile). --}}

@use('Modules\Core\Public\Services\UserDataPathService')
@use('Modules\Core\Public\Support\Lang')
@props(['tile'])
@php
    $onPhone = UserDataPathService::platform() !== null;
@endphp

<div class="space-y-3 rounded-lg border border-slate-200 bg-white p-6 dark:bg-slate-950 dark:border-slate-700">
    <x-core::section-heading :title="Lang::get('email-scan::health.heading')" :level="3" />

    @foreach ($tile->lines as $line)
        @php
            // Status-dot palette + dark companions. Reds and emeralds step
            // down one shade on dark to keep contrast against slate-950
            // (UI-SPEC § Color); amber dot ticks to amber-500.
            $dotColor = match ($line->status) {
                'healthy' => 'bg-emerald-700 dark:bg-emerald-700',
                'unscheduled' => 'bg-slate-400 dark:bg-slate-500',
                'stale' => 'bg-amber-700 dark:bg-amber-500',
                'reauth' => 'bg-rose-600 dark:bg-rose-500',
                default => 'bg-slate-400 dark:bg-slate-500',
            };
            $providerLabel = $line->provider === \Modules\EmailScan\Public\Enums\MailProvider::Gmail->value ? 'Gmail' : 'Microsoft 365';
            if ($line->status === 'reauth') {
                $lineCopy = $providerLabel . ': ' . Lang::get('email-scan::health.needs_reconnect');
            } elseif ($line->lastScanAt === null) {
                $lineCopy = $providerLabel . ': ' . Lang::get($onPhone ? 'email-scan::health.not_scanned_yet_phone' : 'email-scan::health.not_scanned_yet');
            } else {
                $lineCopy = $providerLabel . ': ' . Lang::get('email-scan::health.last_scanned') . ' '
                    . \Carbon\CarbonImmutable::instance($line->lastScanAt)->diffForHumans();
            }
        @endphp
        <div class="flex items-center gap-2 text-xs text-slate-700 dark:text-slate-300">
            <span class="h-2 w-2 rounded-full {{ $dotColor }}" aria-hidden="true"></span>
            <span class="truncate">{{ $lineCopy }}</span>
        </div>
    @endforeach

    @if ($tile->overflowCount > 0)
        <div class="text-xs text-slate-500 dark:text-slate-400">{{ Lang::get('email-scan::health.more', ['count' => $tile->overflowCount]) }}</div>
    @endif
</div>
