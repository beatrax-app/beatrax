@use('Modules\Core\Public\Support\Lang')
@php
    use Modules\Ledger\Public\ValueObjects\Money;

    $fmt = static fn (int $minor, string $currency): string => Money::ofMinor($minor, $currency)->format('nl_NL');
@endphp

<div class="space-y-6">
    <header>
        {{-- flex-wrap + shrink-0: at phone width the heading and the action
             were both compressed rather than reflowed, breaking the button
             label over two lines beside the H1. The action wraps to its own
             row intact instead. Same shape as the /drift header. --}}
        <div class="flex flex-wrap items-baseline justify-between gap-4">
            <h1 class="min-w-0 text-2xl font-semibold tracking-tight text-slate-900 dark:text-slate-100">{{ Lang::get('categorization::triage.heading') }}</h1>
            <button
                type="button"
                wire:click="save"
                @disabled(count($pending) === 0)
                class="inline-flex shrink-0 items-center whitespace-nowrap rounded-md bg-emerald-600 px-4 py-2 text-sm font-medium text-white hover:bg-emerald-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-emerald-600 focus-visible:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-50 dark:bg-emerald-500 dark:hover:bg-emerald-400"
            >{{ Lang::get('categorization::triage.save_categories') }}</button>
        </div>
        <p class="mt-2 text-sm text-slate-500 dark:text-slate-400">
            @if ($totalPending === 0)
                {{ Lang::get('categorization::triage.inbox_zero') }}
            @elseif ($batch->hasMore || $totalPending > count($batch->rows))
                {{ Lang::get('categorization::triage.showing', ['shown' => count($batch->rows), 'total' => $totalPending]) }}
            @else
                {{ Lang::get('categorization::triage.pending', ['total' => $totalPending]) }}
            @endif
        </p>
    </header>

    @if (count($batch->rows) === 0 && $totalPending === 0)
        <p class="rounded-lg border border-slate-200 bg-white px-6 py-12 text-center text-sm text-slate-500 dark:bg-slate-950 dark:border-slate-700 dark:text-slate-400">
            {{ Lang::get('categorization::triage.empty') }}
        </p>
    @else
        <div
            x-data="{ cursor: 0, rowCount: {{ count($batch->rows) }} }"
            x-on:keydown.window="
                if (['INPUT','TEXTAREA','SELECT'].includes(document.activeElement?.tagName)) return;
                if ($event.key === 'ArrowUp')   { $event.preventDefault(); cursor = Math.max(0, cursor - 1); }
                if ($event.key === 'ArrowDown') { $event.preventDefault(); cursor = Math.min(rowCount - 1, cursor + 1); }
                if ($event.key === 'Enter')     { $event.preventDefault(); $wire.save(); }
                if ($event.key === 'Escape')    { $event.preventDefault(); $wire.clearPending(); }
                @foreach ($topCategories as $i => $cat)
                    if ($event.key === '{{ $i + 1 }}') {
                        $event.preventDefault();
                        const row = $refs['row-' + cursor];
                        if (row) { $wire.selectForRow(parseInt(row.dataset.txid, 10), {{ $cat->id }}); }
                    }
                @endforeach
            "
        >
            {{-- overflow-x-auto, not overflow-hidden: this table is the only
                 rendering of these rows at every width, and hidden CLIPPED the
                 right-hand columns on a phone rather than letting them scroll —
                 so the category picker and row actions were unreachable.

                 Scrolling was still not good enough. With real data the table
                 is 484px against 346px of room, so it opens on DATUM /
                 WINKELIER / BEDRAG and the category picker — the only control
                 on a screen whose entire purpose is choosing a category — is
                 off the right edge, reachable only by swiping a table nothing
                 marks as swipeable. `triage-inbox-table` restacks the row below
                 768px so the picker and the row actions are simply there. --}}
            <div class="overflow-x-auto rounded-lg border border-slate-200 dark:border-slate-700">
                <table class="triage-inbox-table min-w-full divide-y divide-slate-200 text-sm dark:divide-slate-700">
                    <thead class="bg-slate-50 dark:bg-slate-900">
                        <tr>
                            <th scope="col" class="px-4 py-2 text-left text-xs font-medium uppercase tracking-wide text-slate-500 dark:text-slate-400">{{ Lang::get('categorization::triage.col_date') }}</th>
                            <th scope="col" class="px-4 py-2 text-left text-xs font-medium uppercase tracking-wide text-slate-500 dark:text-slate-400">{{ Lang::get('categorization::triage.col_counterparty') }}</th>
                            <th scope="col" class="px-4 py-2 text-right text-xs font-medium uppercase tracking-wide text-slate-500 dark:text-slate-400">{{ Lang::get('categorization::triage.col_amount') }}</th>
                            <th scope="col" class="px-4 py-2 text-left text-xs font-medium uppercase tracking-wide text-slate-500 dark:text-slate-400">{{ Lang::get('categorization::triage.col_category') }}</th>
                            <th scope="col" class="px-4 py-2 text-right text-xs font-medium uppercase tracking-wide text-slate-500 dark:text-slate-400">
                                <span class="sr-only">{{ Lang::get('categorization::triage.col_row_actions') }}</span>
                            </th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-200 bg-white dark:bg-slate-950 dark:divide-slate-700">
                        @foreach ($batch->rows as $i => $row)
                            <tr
                                x-ref="row-{{ $i }}"
                                data-txid="{{ $row->transactionId }}"
                                x-bind:class="cursor === {{ $i }} ? 'bg-slate-100 dark:bg-slate-800' : ''"
                                class="triage-row group"
                            >
                                <td class="px-4 py-2 text-slate-900 dark:text-slate-100" style="font-variant-numeric: tabular-nums;">{{ $row->bookedAt }}</td>
                                {{-- Counterparty cell renders the resolved counterparty's
                                     display name. When the row's counterparty_id has been
                                     resolved (counterpartySlug is non-null), wrap the name
                                     in a link routing to counterparties.profile. Rows
                                     without a resolved counterparty render the name as
                                     plain text. --}}
                                <td class="px-4 py-2 text-slate-900 dark:text-slate-100">
                                    @if ($row->counterpartySlug !== null)
                                        <a
                                            href="{{ route('counterparties.profile', ['slug' => $row->counterpartySlug]) }}"
                                            class="underline-offset-2 hover:underline focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 focus-visible:ring-offset-2 dark:focus-visible:ring-slate-100"
                                            data-testid="triage-row-counterparty-link-{{ $row->transactionId }}"
                                        >{{ $row->counterpartyName ?? '—' }}</a>
                                    @else
                                        <span data-testid="triage-row-counterparty-text-{{ $row->transactionId }}">{{ $row->counterpartyName ?? '—' }}</span>
                                    @endif
                                </td>
                                <td class="px-4 py-2 text-right text-slate-900 dark:text-slate-100" style="font-variant-numeric: tabular-nums;">{{ $fmt($row->amountMinor, $row->currency) }}</td>
                                <td class="px-4 py-2">
                                    <label for="triage-category-{{ $row->transactionId }}" class="sr-only">{{ Lang::get('categorization::triage.category_for', ['name' => $row->counterpartyName ?? Lang::get('categorization::triage.this_transaction')]) }}</label>
                                    <select
                                        id="triage-category-{{ $row->transactionId }}"
                                        x-on:change="$wire.selectForRow({{ $row->transactionId }}, $event.target.value ? parseInt($event.target.value, 10) : null)"
                                        class="block w-full rounded-md border border-slate-200 bg-white px-2 py-1.5 text-sm text-slate-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 focus-visible:ring-offset-2 dark:bg-slate-950 dark:border-slate-700 dark:text-slate-100"
                                    >
                                        <option value="">{{ Lang::get('categorization::triage.select_category') }}</option>
                                        @foreach ($categories as $cat)
                                            <option value="{{ $cat->id }}" @selected(($pending[$row->transactionId] ?? null) === $cat->id)>{{ $cat->path }}</option>
                                        @endforeach
                                    </select>
                                </td>
                                <td class="px-4 py-2">
                                    <div class="row-cta flex items-center justify-end gap-2 opacity-0 transition-opacity duration-150 group-hover:opacity-100">
                                        @if ($offerToContribute)
                                            <button
                                                type="button"
                                                wire:click="$dispatch('suggest-mapping:open', { rawDescription: @js($row->description ?? ($row->counterpartyName ?? '')) })"
                                                class="help-others-link inline-flex items-center rounded-md border border-dashed border-slate-300 px-2 py-1 text-xs text-slate-500 hover:border-slate-400 hover:text-slate-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 focus-visible:ring-offset-2 dark:border-slate-600 dark:text-slate-400 dark:hover:border-slate-500 dark:hover:text-slate-200"
                                                data-testid="help-others-cta"
                                            >{{ Lang::get('categorization::triage.help_others') }}</button>
                                        @endif
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <p class="mt-4 text-xs text-slate-500 dark:text-slate-400">
                {{ Lang::get('categorization::triage.shortcuts') }}
            </p>

            @if ($batch->hasMore && $batch->nextCursorId !== null)
                <div class="mt-4 flex justify-center">
                    <button
                        type="button"
                        wire:click="loadMore({{ $batch->nextCursorId }}, @js($batch->nextCursorPostedAt))"
                        class="inline-flex items-center rounded-md border border-slate-200 px-4 py-2 text-sm text-slate-900 hover:bg-slate-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 focus-visible:ring-offset-2 dark:border-slate-700 dark:text-slate-100 dark:hover:bg-slate-900"
                    >{{ Lang::get('categorization::triage.load_more') }}</button>
                </div>
            @endif
        </div>
    @endif
</div>
