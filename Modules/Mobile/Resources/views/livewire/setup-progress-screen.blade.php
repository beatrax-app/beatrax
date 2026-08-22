{{--
    Blocking, resumable, full-screen initial-sync setup gate
    (15-UI-SPEC.md §2). Full-screen and chromeless — no top bar, no drawer —
    so the seam comes from .safe-screen and the app mark from
    x-core::app-mark, the same two the lock screens use.

    The progress bar is x-core::progress-bar, the same component the Sync
    encryption rows use — the ARIA and the track/fill geometry live there.

    aria-live="polite" sits on the HEADING only — never on the ticking
    N-of-M number — to avoid AT spam (UI-SPEC Copywriting).

    This screen is genuinely blocking: it offers exactly one path
    forward — waiting for parity — and no other interactive control.
--}}
@use('Modules\Core\Public\Support\Lang')
@use('Modules\Mobile\Internal\Sync\SyncPhase')
<div
    class="safe-screen min-h-screen flex items-center justify-center bg-white dark:bg-slate-950
            motion-reduce:transition-none"
    wire:poll.2s.keep-alive="poll"
>
    <div class="w-full max-w-sm px-6 space-y-6 text-center">

        {{-- App mark --}}
        <div class="flex justify-center">
            <x-core::app-mark />
        </div>

        {{-- Headline — resume copy, never re-shows the
             fresh-start line once this render already reflects progress. --}}
        <p aria-live="polite" class="text-lg font-semibold text-slate-900 dark:text-slate-100">
            @if ($isResuming)
                {{ Lang::get('mobile::setup.resuming') }}
            @else
                {{ Lang::get('mobile::setup.setting_up') }}
            @endif
        </p>

        {{-- Progress bar. Indeterminate while the running step has nothing
             countable: a fixed full bar through a long rebuild read as a
             finished sync that had stalled.

             The valuetext always names the running step, so an indeterminate
             bar still announces what it is waiting on; the number is added
             only when the step actually has one. The component drops
             aria-valuenow while indeterminate, which is what makes the
             valuetext the whole announcement rather than a "0%" beside it. --}}
        <x-core::progress-bar
            :value="$percent"
            :indeterminate="$percent === 0"
            :label="Lang::get('mobile::setup.progress_aria')"
            aria-valuetext="{{ Lang::get('mobile::setup.step.'.$step->value) }}"
        />

        {{-- What the poll is waiting on. Every one of these reasons has copy
             in twenty-six languages and none of it was ever rendered, so a
             stalled setup showed a turning bar and nothing else — including
             the one state that cannot resolve on its own. --}}
        @if ($blocked !== null)
            <p aria-live="polite" class="text-sm text-slate-500 dark:text-slate-400" data-testid="setup-blocked-reason">
                {{ Lang::get('mobile::setup.blocked.'.$blocked->value) }}
            </p>
        @endif

        {{-- Revoked is terminal: the other device no longer knows this one, so
             polling can never clear it. This is the only way out of a screen
             that otherwise holds the app hostage. --}}
        @if ($blocked === \Modules\Mobile\Internal\Sync\SyncBlockedReason::Revoked)
            <a
                href="{{ route('mobile.pair', ['mode' => 'import']) }}"
                class="inline-block text-sm font-medium text-emerald-700 underline-offset-2 hover:underline dark:text-emerald-400"
                data-testid="setup-repair-link"
            >{{ Lang::get('mobile::setup.step.connect') }}</a>
        @endif

        {{-- The count only. The step list already says WHICH stage is
             running, and the cursor's expected figure only ever equals what
             has been applied — the same reason stepPercent() will not draw a
             determinate bar from it — so a ratio here read as "x of x". --}}
        @if ($recordsApplied > 0)
            <p aria-live="polite" class="text-sm text-slate-500 dark:text-slate-400">
                {{ Lang::choice('mobile::setup.records', $recordsApplied) }}
            </p>
        @endif

        {{-- Every stage, so a slow one reads as "3 of 4" rather than a hang. --}}
        <ol class="space-y-2 text-left">
            @foreach ($this->steps() as $entry)
                @php($done = $entry->isBefore($step) || $phase === SyncPhase::Complete)
                @php($current = ! $done && $entry === $step)
                <li class="flex items-center gap-3 text-sm">
                    <span
                        @class([
                            'flex h-5 w-5 shrink-0 items-center justify-center rounded-full border text-[10px] font-semibold',
                            'border-slate-900 bg-slate-900 text-white dark:border-slate-100 dark:bg-slate-100 dark:text-slate-900' => $done,
                            'border-slate-900 text-slate-900 dark:border-slate-100 dark:text-slate-100' => $current,
                            'border-slate-300 text-slate-400 dark:border-slate-600 dark:text-slate-500' => ! $done && ! $current,
                        ])
                        aria-hidden="true"
                    >{{ $done ? '✓' : $loop->iteration }}</span>
                    <span @class([
                        'text-slate-900 dark:text-slate-100' => $done || $current,
                        'font-medium' => $current,
                        'text-slate-400 dark:text-slate-500' => ! $done && ! $current,
                    ])>
                        {{ Lang::get('mobile::setup.step.'.$entry->value) }}
                        @if ($current)
                            <span class="sr-only">{{ Lang::get('mobile::setup.step_current') }}</span>
                        @endif
                    </span>
                </li>
            @endforeach
        </ol>
    </div>
</div>
