@php
    $fmt = static fn (int $minor): string => '€' . "\u{00A0}" . number_format($minor / 100, 2, '.', ',');
@endphp

<div class="space-y-6">
    <header class="flex items-end justify-between gap-4">
        <div class="space-y-1">
            <h1 class="text-2xl font-semibold tracking-tight text-slate-900">Transactions</h1>
            <p class="text-sm text-slate-500">
                {{ $fullHistory ? 'Full history.' : 'Recent transactions (last 90 days).' }}
            </p>
        </div>
        <button
            type="button"
            wire:click="toggleFullHistory"
            class="inline-flex items-center rounded-md border border-slate-200 px-3 py-2 text-sm text-slate-900 hover:bg-slate-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 focus-visible:ring-offset-2"
        >
            {{ $fullHistory ? 'Show recent only' : 'Show full history' }}
        </button>
    </header>

    @if (count($page->rows) === 0)
        <p class="rounded-lg border border-slate-200 bg-white px-6 py-12 text-center text-sm text-slate-500">
            Nothing here for this period.
        </p>
    @else
        <div class="overflow-hidden rounded-lg border border-slate-200">
            <table class="min-w-full divide-y divide-slate-200 text-sm">
                <thead class="bg-slate-50">
                    <tr>
                        <th scope="col" class="px-4 py-2 text-left text-xs font-medium uppercase tracking-wide text-slate-500">Date</th>
                        <th scope="col" class="px-4 py-2 text-left text-xs font-medium uppercase tracking-wide text-slate-500">Counterparty</th>
                        <th scope="col" class="px-4 py-2 text-left text-xs font-medium uppercase tracking-wide text-slate-500">Category</th>
                        <th scope="col" class="px-4 py-2 text-right text-xs font-medium uppercase tracking-wide text-slate-500">Amount</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-200 bg-white">
                    @foreach ($page->rows as $row)
                        <tr>
                            <td class="px-4 py-2 text-slate-900" style="font-variant-numeric: tabular-nums;">{{ $row->bookedAt }}</td>
                            <td class="px-4 py-2 text-slate-900">{{ $row->counterpartyName ?? '—' }}</td>
                            <td class="px-4 py-2 text-slate-500">{{ $row->categoryName ?? 'Uncategorized' }}</td>
                            <td class="px-4 py-2 text-right text-slate-900" style="font-variant-numeric: tabular-nums;">
                                {{ $fmt($row->amount->toMinor()) }}
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        @if ($page->hasMore && $page->nextCursorId !== null)
            <div class="flex justify-center">
                <button
                    type="button"
                    wire:click="loadMore({{ $page->nextCursorId }})"
                    class="inline-flex items-center rounded-md border border-slate-200 px-4 py-2 text-sm text-slate-900 hover:bg-slate-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 focus-visible:ring-offset-2"
                >Load more</button>
            </div>
        @endif
    @endif
</div>
