{{--
    /budgets page — set a monthly ceiling per expense category and track the
    current period's spend against it.

    Each row shows spent / budget (tabular numerics), a status-coloured
    progress bar (emerald under 80%, amber 80–100%, rose over budget), the
    amount remaining, an inline budget editor, and a remove control. Blade
    default `{{ }}` escaping throughout; no raw HTML output.
--}}

@php
    use Modules\Ledger\Public\ValueObjects\Money;

    $fmt = static fn (int $minor, string $currency): string => Money::ofMinor($minor, $currency)
        ->format($currency === 'EUR' ? 'nl_NL' : 'en_US');

    $barColour = [
        'under' => 'bg-emerald-500 dark:bg-emerald-400',
        'near' => 'bg-amber-500 dark:bg-amber-400',
        'over' => 'bg-rose-500 dark:bg-rose-400',
    ];

    $remainingMinor = $totalBudgetMinor - $totalSpentMinor;
@endphp

<div class="mx-auto max-w-3xl px-4 py-12">
    <header class="mb-8">
        <h1 class="text-2xl font-semibold tracking-tight text-slate-900 dark:text-slate-100">Budgets</h1>
        <p class="mt-2 text-sm text-slate-500 dark:text-slate-400">
            Monthly spending ceilings per category — {{ $period->label }}.
        </p>

        @if (count($rows) > 0)
            <div class="mt-6 flex flex-wrap items-baseline gap-4 rounded-lg border border-slate-200 bg-slate-50 p-4 text-sm text-slate-700 dark:bg-slate-900 dark:border-slate-700 dark:text-slate-300">
                <span style="font-variant-numeric: tabular-nums;">{{ $fmt($totalSpentMinor, 'EUR') }}</span>
                <span class="text-slate-400 dark:text-slate-500" aria-hidden="true">spent of</span>
                <span style="font-variant-numeric: tabular-nums;">{{ $fmt($totalBudgetMinor, 'EUR') }}</span>
                <span class="text-slate-400 dark:text-slate-500" aria-hidden="true">budgeted</span>
                <span class="text-slate-300 dark:text-slate-600" aria-hidden="true">·</span>
                <span class="font-medium {{ $remainingMinor < 0 ? 'text-rose-600 dark:text-rose-400' : 'text-slate-900 dark:text-slate-100' }}" style="font-variant-numeric: tabular-nums;">{{ $fmt(abs($remainingMinor), 'EUR') }}</span>
                <span class="text-slate-400 dark:text-slate-500" aria-hidden="true">{{ $remainingMinor < 0 ? 'over' : 'left' }}</span>
            </div>
        @endif
    </header>

    {{-- Add a budget --}}
    @if (count($available) > 0)
        <section class="mb-8 rounded-lg border border-slate-200 bg-white p-4 dark:bg-slate-950 dark:border-slate-700">
            <h2 class="mb-3 text-sm font-medium text-slate-900 dark:text-slate-100">Add a budget</h2>
            <div class="flex flex-wrap items-end gap-3">
                <label class="flex-1 min-w-[12rem]">
                    <span class="mb-1 block text-xs text-slate-500 dark:text-slate-400">Category</span>
                    <select
                        wire:model="newCategoryId"
                        class="w-full rounded-md border border-slate-200 bg-white px-3 py-2 text-sm text-slate-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 dark:bg-slate-950 dark:border-slate-700 dark:text-slate-100"
                    >
                        <option value="">Choose a category…</option>
                        @foreach ($available as $id => $name)
                            <option value="{{ $id }}">{{ $name }}</option>
                        @endforeach
                    </select>
                </label>
                <label class="w-32">
                    <span class="mb-1 block text-xs text-slate-500 dark:text-slate-400">Monthly (€)</span>
                    <input
                        type="text"
                        inputmode="decimal"
                        wire:model="newAmount"
                        wire:keydown.enter="setBudget"
                        placeholder="0.00"
                        class="w-full rounded-md border border-slate-200 bg-white px-3 py-2 text-sm text-slate-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 dark:bg-slate-950 dark:border-slate-700 dark:text-slate-100"
                        style="font-variant-numeric: tabular-nums;"
                    >
                </label>
                <button
                    type="button"
                    wire:click="setBudget"
                    class="inline-flex items-center rounded-md bg-slate-900 px-4 py-2 text-sm font-medium text-white hover:bg-slate-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 focus-visible:ring-offset-2 dark:bg-slate-100 dark:text-slate-900 dark:hover:bg-white"
                >Add</button>
            </div>
        </section>
    @endif

    {{-- Budget rows --}}
    @if (count($rows) === 0)
        <div class="rounded-lg border border-slate-200 bg-white p-6 dark:bg-slate-950 dark:border-slate-700">
            <h2 class="text-base font-semibold text-slate-900 dark:text-slate-100">No budgets yet</h2>
            <p class="mt-2 text-sm text-slate-500 dark:text-slate-400">
                Pick an expense category above and set a monthly amount to start tracking it here.
            </p>
        </div>
    @else
        <ul class="space-y-3">
            @foreach ($rows as $row)
                @php
                    $barWidth = $row->fractionUsed <= 0 ? 0 : max(2, min(100, (int) round($row->fractionUsed * 100)));
                    $pct = (int) round($row->fractionUsed * 100);
                @endphp
                <li class="rounded-lg border border-slate-200 bg-white p-4 dark:bg-slate-950 dark:border-slate-700">
                    <div class="flex items-baseline justify-between gap-4">
                        <p class="truncate text-sm font-medium text-slate-900 dark:text-slate-100">{{ $row->name }}</p>
                        <p class="shrink-0 text-sm text-slate-500 dark:text-slate-400" style="font-variant-numeric: tabular-nums;">
                            <span class="{{ $row->status === 'over' ? 'text-rose-600 dark:text-rose-400 font-medium' : 'text-slate-900 dark:text-slate-100' }}">{{ $fmt($row->spentMinor, $row->currency) }}</span>
                            <span class="text-slate-400 dark:text-slate-500" aria-hidden="true">/</span>
                            {{ $fmt($row->budgetMinor, $row->currency) }}
                        </p>
                    </div>

                    <div class="mt-3 h-2 w-full overflow-hidden rounded-full bg-slate-200 dark:bg-slate-700">
                        <div class="h-2 rounded-full {{ $barColour[$row->status] }}" style="width: {{ $barWidth }}%;"></div>
                    </div>

                    <div class="mt-3 flex items-center justify-between gap-4">
                        <p class="text-xs text-slate-500 dark:text-slate-400" style="font-variant-numeric: tabular-nums;">
                            {{ $pct }}% used
                            @if ($row->remainingMinor() >= 0)
                                · {{ $fmt($row->remainingMinor(), $row->currency) }} left
                            @else
                                · <span class="text-rose-600 dark:text-rose-400">{{ $fmt(abs($row->remainingMinor()), $row->currency) }} over</span>
                            @endif
                        </p>
                        <div class="flex items-center gap-2">
                            <input
                                type="text"
                                inputmode="decimal"
                                wire:model="amounts.{{ $row->categoryId }}"
                                wire:keydown.enter="updateBudget({{ $row->categoryId }})"
                                wire:blur="updateBudget({{ $row->categoryId }})"
                                aria-label="Monthly budget for {{ $row->name }}"
                                class="w-24 rounded-md border border-slate-200 bg-white px-2 py-1 text-right text-xs text-slate-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 dark:bg-slate-950 dark:border-slate-700 dark:text-slate-100"
                                style="font-variant-numeric: tabular-nums;"
                            >
                            <button
                                type="button"
                                wire:click="removeBudget({{ $row->categoryId }})"
                                class="rounded-md px-2 py-1 text-xs text-slate-400 hover:bg-slate-100 hover:text-rose-600 focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 dark:hover:bg-slate-900 dark:hover:text-rose-400"
                                aria-label="Remove budget for {{ $row->name }}"
                            >Remove</button>
                        </div>
                    </div>
                </li>
            @endforeach
        </ul>
    @endif
</div>
