@php
    $fmt = static fn (int $minor): string => '€' . "\u{00A0}" . number_format($minor / 100, 2, '.', ',');
@endphp

<div class="space-y-6">
    <header class="space-y-1">
        <h1 class="text-2xl font-semibold tracking-tight text-slate-900">Uncategorized</h1>
        <p class="text-sm text-slate-500">
            @if (count($batch->rows) === 0)
                Inbox zero.
            @else
                {{ count($batch->rows) }} pending.
            @endif
        </p>
    </header>

    @if (count($batch->rows) === 0)
        <p class="rounded-lg border border-slate-200 bg-white px-6 py-12 text-center text-sm text-slate-500">
            Every transaction has a category. Re-open this page after your next import.
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
                @foreach ($topNine as $i => $cat)
                    if ($event.key === '{{ $i + 1 }}') {
                        $event.preventDefault();
                        const row = $refs['row-' + cursor];
                        if (row) { $wire.selectForRow(parseInt(row.dataset.txid, 10), {{ $cat->id }}); }
                    }
                @endforeach
            "
        >
            <div class="overflow-hidden rounded-lg border border-slate-200">
                <table class="min-w-full divide-y divide-slate-200 text-sm">
                    <thead class="bg-slate-50">
                        <tr>
                            <th scope="col" class="px-4 py-2 text-left text-xs font-medium uppercase tracking-wide text-slate-500">Date</th>
                            <th scope="col" class="px-4 py-2 text-left text-xs font-medium uppercase tracking-wide text-slate-500">Counterparty</th>
                            <th scope="col" class="px-4 py-2 text-right text-xs font-medium uppercase tracking-wide text-slate-500">Amount</th>
                            <th scope="col" class="px-4 py-2 text-left text-xs font-medium uppercase tracking-wide text-slate-500">Category</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-200 bg-white">
                        @foreach ($batch->rows as $i => $row)
                            <tr
                                x-ref="row-{{ $i }}"
                                data-txid="{{ $row->transactionId }}"
                                x-bind:class="cursor === {{ $i }} ? 'bg-slate-100' : ''"
                            >
                                <td class="px-4 py-2 text-slate-900" style="font-variant-numeric: tabular-nums;">{{ $row->bookedAt }}</td>
                                <td class="px-4 py-2 text-slate-900">{{ $row->counterpartyName ?? '—' }}</td>
                                <td class="px-4 py-2 text-right text-slate-900" style="font-variant-numeric: tabular-nums;">{{ $fmt($row->amountMinor) }}</td>
                                <td class="px-4 py-2">
                                    <select
                                        wire:change="selectForRow({{ $row->transactionId }}, $event.target.value ? parseInt($event.target.value, 10) : null)"
                                        class="block w-full rounded-md border border-slate-200 bg-white px-2 py-1.5 text-sm text-slate-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 focus-visible:ring-offset-2"
                                    >
                                        <option value="">Select category</option>
                                        @foreach ($categories as $cat)
                                            <option value="{{ $cat->id }}" @selected(($pending[$row->transactionId] ?? null) === $cat->id)>{{ $cat->path }}</option>
                                        @endforeach
                                    </select>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <footer class="mt-4 flex items-center justify-between gap-4">
                <p class="text-xs text-slate-500">
                    1–9 assign top categories · ↑/↓ move · Enter save · / search · Esc clear
                </p>
                <button
                    type="button"
                    wire:click="save"
                    @disabled(count($pending) === 0)
                    class="inline-flex items-center rounded-md bg-emerald-600 px-4 py-2 text-sm font-medium text-white hover:bg-emerald-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-emerald-600 focus-visible:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-50"
                >Save categories</button>
            </footer>
        </div>
    @endif
</div>
