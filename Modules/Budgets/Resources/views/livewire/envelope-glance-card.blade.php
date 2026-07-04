{{--
    Dashboard "Budgets" glance card (Req 12, D-21) — mirrors goals-summary-card
    / tax-summary-card chrome exactly: rounded-lg border card, "Budgets"
    eyebrow + "See all →" link, and a single large "Ready to assign" figure
    (emerald ≥ 0 / rose < 0, tabular-nums) sourced from CarryoverQuery.

    Renders nothing at all when the user has zero expense categories
    ($collapse === true) — envelopes are implicit per D-12a, so there is no
    intermediate "no envelopes yet" chrome state, only "no categories yet."
--}}

@php
    use Modules\Ledger\Public\ValueObjects\Money;

    $fmt = static fn (int $minor): string => Money::ofMinor($minor, 'EUR')->format('nl_NL');
    $figureColour = $toBudgetMinor !== null && $toBudgetMinor < 0
        ? 'text-rose-600 dark:text-rose-400'
        : 'text-emerald-600 dark:text-emerald-400';
@endphp

{{-- Outer root div is required unconditionally (Livewire needs one root
     element per render); it stays empty and invisible when $collapse is
     true — the collapse state renders NOTHING user-visible. --}}
<div>
    @if (! $collapse)
        <div class="rounded-lg border border-slate-200 bg-white p-6 dark:border-slate-700 dark:bg-slate-950">
            {{-- Card header --}}
            <div class="flex items-center justify-between">
                <p class="text-xs font-medium uppercase tracking-wide text-slate-500 dark:text-slate-400">Budgets</p>
                <a
                    href="{{ route('budgets.index') }}"
                    class="text-xs text-slate-400 hover:text-slate-600 focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 dark:text-slate-500 dark:hover:text-slate-300"
                >See all →</a>
            </div>

            <div class="mt-4 flex items-center gap-3">
                <div>
                    <p class="text-xs font-medium uppercase tracking-wide text-slate-500 dark:text-slate-400">Ready to assign</p>
                    <p
                        class="mt-1 text-3xl font-semibold {{ $toBudgetMinor === null ? 'text-slate-400 dark:text-slate-500' : $figureColour }}"
                        style="font-family: var(--font-mono, ui-monospace, monospace); font-variant-numeric: tabular-nums;"
                    >
                        {{ $toBudgetMinor === null ? '—' : $fmt($toBudgetMinor) }}
                    </p>
                </div>

                @if ($overspentCount >= 1)
                    <span class="inline-flex items-center rounded-full bg-amber-100 px-2 py-0.5 text-xs font-medium text-amber-800 dark:bg-amber-900/40 dark:text-amber-300">
                        {{ $overspentCount }} over budget
                    </span>
                @endif
            </div>
        </div>
    @endif
</div>
