@use('Modules\Core\Public\Support\Lang')
@use('Modules\Core\Public\Support\Fmt')
@php
    /**
     * @var array{description: string, count: int, lastSeen: ?string, paymentType: ?\Modules\Import\Public\Enums\PaymentType} $row
     */
    $description = (string) ($row['description'] ?? '');
    $count = (int) ($row['count'] ?? 0);
    $lastSeen = $row['lastSeen'] ?? null;
    $paymentType = $row['paymentType'] ?? null;
@endphp

<article class="mystery-card grid grid-cols-1 gap-3 rounded-lg border border-slate-200 bg-white p-4 dark:border-slate-700 dark:bg-slate-950 md:grid-cols-[1.4fr_1fr_1fr_auto]">
    <div>
        <code class="code inline-block rounded-md bg-slate-100 px-2 py-1 text-xs font-mono text-slate-900 dark:bg-slate-900 dark:text-slate-100">
            {{ $description }}
        </code>
        <p class="mt-2 text-xs text-slate-500 dark:text-slate-400">{{ Lang::get('community::mystery.card.likely') }}</p>
    </div>
    <div class="text-sm text-slate-700 dark:text-slate-300">
        <p style="font-variant-numeric: tabular-nums;">{{ Lang::choice('community::mystery.card.seen_times', $count) }}</p>
        @if ($lastSeen !== null)
            <p class="text-xs text-slate-500 dark:text-slate-400">{{ Lang::get('community::mystery.card.last_seen', ['date' => Fmt::shortDate($lastSeen)]) }}</p>
        @endif
    </div>
    <div class="text-sm text-slate-700 dark:text-slate-300">
        @if ($paymentType !== null)
            <span class="ptype-chip inline-flex items-center rounded-full bg-slate-100 px-2 py-0.5 text-xs font-medium text-slate-700 dark:bg-slate-900 dark:text-slate-200">
                {{ $paymentType->chipLabel() }}
            </span>
        @endif
    </div>
    <div class="flex items-center justify-end gap-2">
        <button
            type="button"
            wire:click="$dispatch('suggest-mapping:open', { rawDescription: @js($description) })"
            class="inline-flex items-center rounded-md bg-emerald-600 px-3 py-2 text-xs font-medium text-white hover:bg-emerald-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-emerald-600 focus-visible:ring-offset-2 dark:bg-emerald-500 dark:hover:bg-emerald-400"
        >{{ Lang::get('community::mystery.card.suggest') }}</button>
    </div>
</article>
