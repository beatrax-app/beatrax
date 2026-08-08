{{--
    Blocking, resumable, full-screen initial-sync setup gate (D-03/D-04,
    15-UI-SPEC.md §2). Naked full-screen safe-area chrome (no top-bar, no
    drawer) reused verbatim from Modules/Auth/Resources/views/livewire/
    lock-screen.blade.php lines 1-5; the 48x48 /icon.png app-mark block is
    the same reuse.

    The progress-bar ARIA markup below (aria-valuenow/min/max + h-2
    rounded-full bg-slate-200/bg-slate-900 fill) is reused verbatim from
    Modules/Sync/Resources/views/livewire/devices-and-sync-settings-section
    .blade.php's encryption-status-row (lines 106-121, PATTERNS.md Analog B).

    aria-live="polite" sits on the HEADING only — never on the ticking
    N-of-M number — to avoid AT spam (UI-SPEC Copywriting).

    This screen is genuinely blocking (D-03): it offers exactly one path
    forward — waiting for parity — and no other interactive control.
--}}
@use('Modules\Core\Public\Support\Lang')
<div
    class="min-h-screen flex items-center justify-center bg-white dark:bg-slate-950
            px-[env(safe-area-inset-left)] pr-[env(safe-area-inset-right)]
            pt-[env(safe-area-inset-top)] pb-[env(safe-area-inset-bottom)]
            motion-reduce:transition-none"
    wire:poll.2s="poll"
>
    <div class="w-full max-w-sm px-6 space-y-6 text-center">

        {{-- App mark --}}
        <div class="flex justify-center">
            <img
                src="/icon.png"
                width="48"
                height="48"
                alt="Beatrax"
                class="rounded-xl"
                aria-hidden="true"
            />
        </div>

        {{-- Headline (D-03/D-04) — resume copy, never re-shows the
             fresh-start line once this render already reflects progress. --}}
        <p aria-live="polite" class="text-lg font-semibold text-slate-900 dark:text-slate-100">
            @if ($isResuming)
                {{ Lang::get('mobile::setup.resuming') }}
            @else
                {{ Lang::get('mobile::setup.setting_up') }}
            @endif
        </p>

        {{-- Progress bar --}}
        <div
            class="h-2 w-full rounded-full bg-slate-200 dark:bg-slate-700"
            role="progressbar"
            aria-valuenow="{{ $percent }}"
            aria-valuemin="0"
            aria-valuemax="100"
            aria-label="{{ Lang::get('mobile::setup.progress_aria') }}"
        >
            <div class="h-2 rounded-full bg-slate-900 dark:bg-slate-100" style="width: {{ $percent }}%"></div>
        </div>

        <p class="text-sm text-slate-500 dark:text-slate-400">
            {{ Lang::get('mobile::setup.records', ['applied' => $recordsApplied, 'expected' => $recordsExpected ?? $recordsApplied]) }}
        </p>

    </div>
</div>
