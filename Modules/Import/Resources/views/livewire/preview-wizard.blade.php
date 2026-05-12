<div class="space-y-6">
    <header class="space-y-1">
        <h1 class="text-2xl font-semibold text-slate-900 tracking-tight">Preview import</h1>
        <p class="text-sm text-slate-500">Review the parsed rows. Nothing is saved to your ledger until you confirm.</p>
    </header>

    @if ($preview === null || $previewExpired)
        <div class="rounded-md border border-amber-200 bg-amber-50 p-4 text-sm text-amber-700">
            <p>The preview has expired. <a href="/imports/new" class="underline">Re-upload the file</a> to try again.</p>
        </div>
    @else
        @if (count($preview->accountsToName) > 0)
            <section class="space-y-4 rounded-md border border-slate-200 bg-slate-50 p-6">
                @foreach ($preview->accountsToName as $unknown)
                    <div class="space-y-3">
                        <p class="text-sm text-slate-900">
                            We found an unfamiliar IBAN: <strong class="font-medium">{{ $unknown->iban }}</strong>. Name this account.
                        </p>
                        <div class="flex items-end gap-2">
                            <div class="flex-1 space-y-1">
                                <label class="block text-xs text-slate-500" for="accountName">Account name</label>
                                <input
                                    type="text"
                                    id="accountName"
                                    wire:model="accountName"
                                    placeholder="e.g. ASN Spaarrekening"
                                    class="block w-full rounded-md border border-slate-200 bg-white px-3 py-2 text-sm text-slate-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 focus-visible:ring-offset-2"
                                />
                            </div>
                            <button
                                type="button"
                                wire:click="nameAccount('{{ $unknown->iban }}', $wire.accountName)"
                                class="bg-emerald-600 hover:bg-emerald-700 text-white font-medium rounded-md px-3 py-2 text-sm focus:outline-none focus-visible:ring-2 focus-visible:ring-emerald-600 focus-visible:ring-offset-2"
                            >
                                Save name
                            </button>
                        </div>
                    </div>
                @endforeach
            </section>
        @else
            <section class="overflow-hidden rounded-md border border-slate-200">
                <table class="min-w-full divide-y divide-slate-200" style="font-feature-settings: 'tnum';">
                    <thead class="bg-slate-50">
                        <tr>
                            <th scope="col" class="px-4 py-2 text-left text-xs font-medium text-slate-500">Date</th>
                            <th scope="col" class="px-4 py-2 text-left text-xs font-medium text-slate-500">Counterparty</th>
                            <th scope="col" class="px-4 py-2 text-right text-xs font-medium text-slate-500">Amount</th>
                            <th scope="col" class="px-4 py-2 text-left text-xs font-medium text-slate-500">Status</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-200 bg-white">
                        @foreach ($preview->rows as $row)
                            <tr>
                                <td class="px-4 py-2 text-sm text-slate-900">{{ $row->bookedAt ?? '—' }}</td>
                                <td class="px-4 py-2 text-sm text-slate-900">{{ $row->counterpartyName ?? '—' }}</td>
                                <td class="px-4 py-2 text-right text-sm text-slate-900">
                                    @if ($row->amountMinor !== null)
                                        € {{ number_format($row->amountMinor / 100, 2, '.', ',') }}
                                    @else
                                        —
                                    @endif
                                </td>
                                <td class="px-4 py-2 text-sm">
                                    @if ($row->status === 'new')
                                        <span class="inline-flex items-center rounded-md bg-emerald-50 px-2 py-0.5 text-xs font-medium text-emerald-700 ring-1 ring-inset ring-emerald-600/20" title="Will be added to your ledger.">New</span>
                                    @elseif ($row->status === 'duplicate')
                                        <span class="inline-flex items-center rounded-md bg-amber-50 px-2 py-0.5 text-xs font-medium text-amber-700 ring-1 ring-inset ring-amber-600/20" title="Already imported — will be skipped.">Duplicate</span>
                                    @else
                                        <span class="inline-flex items-center rounded-md bg-rose-50 px-2 py-0.5 text-xs font-medium text-rose-700 ring-1 ring-inset ring-rose-600/20" title="{{ $row->error }}">Error</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </section>

            <div class="flex justify-end gap-3">
                <button
                    type="button"
                    wire:click="discard"
                    class="rounded-md border border-slate-200 bg-white px-4 py-2 text-sm font-medium text-slate-900 hover:bg-slate-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 focus-visible:ring-offset-2"
                >
                    Discard import
                </button>
                <button
                    type="button"
                    wire:click="confirm"
                    class="rounded-md bg-emerald-600 px-4 py-2 text-sm font-medium text-white hover:bg-emerald-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-emerald-600 focus-visible:ring-offset-2"
                >
                    Confirm import
                </button>
            </div>
        @endif
    @endif
</div>
