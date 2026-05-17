{{-- Dashboard "Email scan health" tile partial.

     Props:
       $tile — Modules\EmailScan\Public\Dto\EmailScanHealthTile DTO.

     The host dashboard view (`core::livewire.dashboard`) wraps this
     partial in an `<a href="{{ route('inboxes.index') }}">` so the whole
     card is clickable; the wrapper anchor owns the hover affordance. The
     partial body lists up to three per-inbox lines + an optional
     "+N more" footer when more inboxes are connected.

     Per-line dot colour:
       healthy → emerald-600
       stale   → amber-700
       reauth  → rose-600
     The tile heading itself stays `text-slate-900` regardless of
     overall status — the calm aesthetic forbids painting the tile
     title red (UI-SPEC § Typography / Email-scan-health tile). --}}

@props(['tile'])

<div class="space-y-3 rounded-lg border border-slate-200 bg-white p-6">
    <h3 class="text-base font-semibold text-slate-900">Email scan health</h3>

    @foreach ($tile->lines as $line)
        @php
            $dotColor = match ($line->status) {
                'healthy' => 'bg-emerald-600',
                'stale' => 'bg-amber-700',
                'reauth' => 'bg-rose-600',
                default => 'bg-slate-400',
            };
            $providerLabel = $line->provider === 'gmail' ? 'Gmail' : 'Microsoft 365';
            if ($line->status === 'reauth') {
                $lineCopy = $providerLabel . ': needs reconnect';
            } elseif ($line->lastScanAt === null) {
                $lineCopy = $providerLabel . ': not scanned yet';
            } else {
                $lineCopy = $providerLabel . ': last scanned '
                    . \Carbon\CarbonImmutable::instance($line->lastScanAt)->diffForHumans();
            }
        @endphp
        <div class="flex items-center gap-2 text-xs text-slate-700">
            <span class="h-2 w-2 rounded-full {{ $dotColor }}" aria-hidden="true"></span>
            <span class="truncate">{{ $lineCopy }}</span>
        </div>
    @endforeach

    @if ($tile->overflowCount > 0)
        <div class="text-xs text-slate-500">+{{ $tile->overflowCount }} more</div>
    @endif
</div>
