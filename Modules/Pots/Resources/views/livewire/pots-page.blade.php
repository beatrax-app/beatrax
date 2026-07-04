{{--
    /pots page — savings pots grouped by account with per-account reconciliation
    headers (real · allocated · unallocated, D-15), negative-unallocated amber
    warning (D-02), Flux modals for create/edit/fund/move/withdraw (D-17),
    inline movement history expansion per card (D-17), archive/restore micro-
    confirm, and an "Archived pots" disclosure.

    Calm-slate direction: emerald goal links, amber negative-unallocated warning,
    rose archive-action confirm. Tabular mono numerics throughout.
    Matches 03-UI-SPEC.md exactly (colors, spacing, copy).
--}}

@php
    use Modules\Ledger\Public\ValueObjects\Money;

    $fmt = static fn (int $minor, string $currency): string => Money::ofMinor($minor, $currency)
        ->format($currency === 'EUR' ? 'nl_NL' : 'en_US');
@endphp

{{--
    Phone responsive pass (D-06, D-10, D-12, UI-SPEC §8).

    At <768px:
    - Pots render as .card-list-item rows (pot name .primary, balance .amount, account .secondary)
    - Fund/Move/Create/Edit modals become bottom sheets (x-core::bottom-sheet)
    - Row actions (Fund, Move, Edit) are always visible on phone (D-12)
    At >=768px: existing card grid + Flux modals unchanged.
--}}
<style>
    @media (min-width: 768px) {
        .pots-phone-list { display: none !important; }
    }
    @media (max-width: 767px) {
        .pots-desktop-list { display: none !important; }
    }
</style>

<div class="mx-auto max-w-3xl px-4 py-12">
    {{-- Page header --}}
    <header class="mb-8 flex items-start justify-between gap-4">
        <div>
            <h1 class="text-2xl font-semibold tracking-tight text-slate-900 dark:text-slate-100">Pots</h1>
            <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">Virtual sub-balances that always add up to your real account balance.</p>
        </div>
        @if (count($accounts) > 0)
            <button
                type="button"
                x-on:click="
                    $wire.set('editPotId', 0);
                    if (window.innerWidth < 768) {
                        $dispatch('open-sheet', { name: 'pot-form' });
                    } else {
                        $flux.modal('pot-form').show();
                    }
                "
                class="inline-flex shrink-0 items-center rounded-md bg-slate-900 px-4 py-2 text-sm font-medium text-white hover:bg-slate-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 focus-visible:ring-offset-2 dark:bg-slate-100 dark:text-slate-900 dark:hover:bg-white"
            >Add pot</button>
        @endif
    </header>

    {{-- No accounts / no pots: empty state --}}
    @if (count($accounts) === 0 || count($groups) === 0)
        <div class="rounded-lg border border-slate-200 bg-white p-6 dark:bg-slate-950 dark:border-slate-700">
            <h2 class="text-base font-semibold text-slate-900 dark:text-slate-100">No pots yet</h2>
            <p class="mt-2 text-sm text-slate-500 dark:text-slate-400">
                Create virtual sub-balances within any account to organise your money without a real bank transfer.
            </p>
            @if (count($accounts) > 0)
                <button
                    type="button"
                    x-on:click="$flux.modal('pot-form').show()"
                    wire:click="$set('editPotId', 0)"
                    class="mt-4 inline-flex items-center rounded-md bg-slate-900 px-4 py-2 text-sm font-medium text-white hover:bg-slate-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 focus-visible:ring-offset-2 dark:bg-slate-100 dark:text-slate-900 dark:hover:bg-white"
                >Add your first pot</button>
            @endif
        </div>
    @else
        {{-- Phone: flat .card-list-item list across all accounts (D-06) --}}
        <div class="pots-phone-list rounded-lg border border-slate-200 bg-white dark:bg-slate-950 dark:border-slate-700 overflow-hidden">
            @foreach ($groups as $accountId => $pots)
                @foreach ($pots as $pot)
                    <div class="card-list-item">
                        <div class="flex-1 min-w-0">
                            <p class="primary truncate">{{ $pot->name }}</p>
                            <p class="secondary">{{ $pot->accountName }}</p>
                        </div>
                        <span class="amount">{{ $fmt($pot->balanceMinor, $pot->currency) }}</span>
                        {{-- Row actions always visible on phone (D-12) --}}
                        <button
                            type="button"
                            x-on:click="
                                $wire.set('operationPotId', {{ $pot->id }});
                                $wire.set('operationKind', 'fund');
                                $dispatch('open-sheet', { name: 'pot-fund' });
                            "
                            class="text-xs text-slate-400 hover:text-slate-900 focus:outline-none min-w-[44px] min-h-[44px] flex items-center justify-center dark:hover:text-slate-100"
                        >Fund</button>
                        <button
                            type="button"
                            x-on:click="
                                $wire.set('operationPotId', {{ $pot->id }});
                                $wire.set('operationKind', 'transfer');
                                $dispatch('open-sheet', { name: 'pot-move' });
                            "
                            class="text-xs text-slate-400 hover:text-slate-900 focus:outline-none min-w-[44px] min-h-[44px] flex items-center justify-center dark:hover:text-slate-100"
                        >Move</button>
                    </div>
                @endforeach
            @endforeach
        </div>

        {{-- Desktop: Account groups (D-14): grouped by account, ordered by account name --}}
        <div class="pots-desktop-list space-y-8">
            @foreach ($groups as $accountId => $pots)
                @php
                    /** @var \Modules\Pots\Public\Dto\PotRow[] $pots */
                    $rec = $reconciliations[$accountId] ?? null;
                    $firstPot = $pots[0];
                @endphp

                <div>
                    {{-- Account group header row --}}
                    <div class="flex items-center justify-between gap-4 mb-2">
                        <h2 class="text-base font-semibold text-slate-900 dark:text-slate-100">
                            {{ $firstPot->accountName }}
                        </h2>
                        <button
                            type="button"
                            x-on:click="
                                $wire.set('accountId', '{{ $accountId }}');
                                $wire.set('editPotId', 0);
                                if (window.innerWidth < 768) {
                                    $dispatch('open-sheet', { name: 'pot-form' });
                                } else {
                                    $flux.modal('pot-form').show();
                                }
                            "
                            class="rounded-md border border-slate-200 bg-transparent px-3 py-1.5 text-sm font-medium text-slate-600 hover:bg-slate-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 dark:border-slate-700 dark:text-slate-400 dark:hover:bg-slate-900"
                        >Add pot</button>
                    </div>

                    {{-- Negative-unallocated amber warning banner (D-02) --}}
                    @if ($rec !== null && $rec->isOverAllocated)
                        <div
                            class="mb-2 rounded-md border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-700 dark:border-amber-800 dark:bg-amber-900/20 dark:text-amber-400"
                            role="alert"
                        >
                            <span class="mr-1" aria-hidden="true">
                                <flux:icon.exclamation-triangle class="inline-block h-4 w-4 align-text-bottom" />
                            </span>
                            Pots exceed real balance by {{ $fmt(abs($rec->unallocatedMinor), $rec->currency) }} — rebalance to fix
                        </div>
                    @endif

                    {{-- Reconciliation line (D-15) --}}
                    @if ($rec !== null)
                        <p class="mb-4 text-xs text-slate-500 dark:text-slate-400" style="font-family: var(--font-mono, ui-monospace, monospace); font-variant-numeric: tabular-nums;">
                            Real balance: {{ $fmt($rec->realBalanceMinor, $rec->currency) }}
                            <span aria-hidden="true"> · </span>
                            Allocated: {{ $fmt($rec->allocatedMinor, $rec->currency) }}
                            <span aria-hidden="true"> · </span>
                            Unallocated: <span class="{{ $rec->isOverAllocated ? 'font-medium text-amber-600 dark:text-amber-400' : '' }}">{{ $fmt($rec->unallocatedMinor, $rec->currency) }}</span>
                        </p>
                    @endif

                    {{-- Pot cards --}}
                    <ul class="space-y-4">
                        @foreach ($pots as $pot)
                            <li
                                class="rounded-lg border border-slate-200 bg-white p-4 dark:bg-slate-950 dark:border-slate-700"
                                wire:key="pot-{{ $pot->id }}"
                            >
                                {{-- Top row: pot name + link chip --}}
                                <div class="flex items-center justify-between gap-3">
                                    <p class="min-w-0 truncate text-sm font-semibold text-slate-900 dark:text-slate-100">{{ $pot->name }}</p>
                                    <div class="flex shrink-0 items-center gap-2">
                                        @if ($pot->goalId !== null)
                                            <span class="inline-flex items-center rounded-full bg-emerald-100 px-2.5 py-[3px] text-xs font-medium text-emerald-800 dark:bg-emerald-900/40 dark:text-emerald-300">
                                                Goal: {{ $pot->goalName ?? 'Goal' }}
                                            </span>
                                        @elseif ($pot->categoryId !== null)
                                            <span class="inline-flex items-center rounded-full bg-slate-100 px-2.5 py-[3px] text-xs font-medium text-slate-600 dark:bg-slate-800 dark:text-slate-400">
                                                {{ $pot->categoryName ?? 'Category' }}
                                            </span>
                                        @endif
                                    </div>
                                </div>

                                {{-- Pot balance --}}
                                <p class="mt-1 font-semibold text-slate-900 dark:text-slate-100" style="font-family: var(--font-mono, ui-monospace, monospace); font-variant-numeric: tabular-nums; font-size: var(--text-md, 1rem);">
                                    {{ $fmt($pot->balanceMinor, $pot->currency) }}
                                </p>

                                {{-- Coverage insight (D-12): category-linked only --}}
                                @if ($pot->categoryId !== null && $pot->categorySpentMinor !== null)
                                    <p class="mt-0.5 text-xs text-slate-500 dark:text-slate-400" style="font-family: var(--font-mono, ui-monospace, monospace); font-variant-numeric: tabular-nums;">
                                        {{ $pot->categoryName }}: {{ $fmt($pot->categorySpentMinor, $pot->currency) }} spent · {{ $fmt($pot->balanceMinor, $pot->currency) }} in pot
                                    </p>
                                @endif

                                {{-- Archive micro-confirm or footer action row --}}
                                @if ($archivingPotId === $pot->id)
                                    <div class="mt-3 flex items-center gap-3 rounded-md bg-slate-50 px-3 py-2 dark:bg-slate-900">
                                        <p class="flex-1 text-sm text-slate-700 dark:text-slate-300">
                                            Archive this pot? Balance of {{ $fmt($pot->balanceMinor, $pot->currency) }} will return to unallocated.
                                        </p>
                                        <button
                                            type="button"
                                            wire:click="cancelArchive"
                                            class="text-sm text-slate-500 hover:text-slate-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 dark:hover:text-slate-100 dark:text-slate-400"
                                        >Cancel</button>
                                        <button
                                            type="button"
                                            wire:click="archivePot({{ $pot->id }})"
                                            aria-label="Confirm archive of {{ $pot->name }}"
                                            class="text-sm font-medium text-rose-600 hover:text-rose-800 focus:outline-none focus-visible:ring-2 focus-visible:ring-rose-600 dark:text-rose-400 dark:hover:text-rose-200"
                                        >Archive</button>
                                    </div>
                                @else
                                    <div class="mt-3 flex items-center gap-2">
                                        {{-- Fund button --}}
                                        <button
                                            type="button"
                                            wire:click="$set('operationPotId', {{ $pot->id }}); $set('operationKind', 'fund')"
                                            x-on:click="
                                                if (window.innerWidth < 768) {
                                                    $dispatch('open-sheet', { name: 'pot-fund' });
                                                } else {
                                                    $flux.modal('pot-fund').show();
                                                }
                                            "
                                            class="text-sm text-slate-400 hover:text-slate-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 dark:hover:text-slate-100"
                                        >Fund</button>

                                        {{-- Move button --}}
                                        <button
                                            type="button"
                                            wire:click="$set('operationPotId', {{ $pot->id }}); $set('operationKind', 'transfer')"
                                            x-on:click="
                                                if (window.innerWidth < 768) {
                                                    $dispatch('open-sheet', { name: 'pot-move' });
                                                } else {
                                                    $flux.modal('pot-move').show();
                                                }
                                            "
                                            class="text-sm text-slate-400 hover:text-slate-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 dark:hover:text-slate-100"
                                        >Move</button>

                                        {{-- Kebab dropdown --}}
                                        <flux:dropdown>
                                            <flux:button
                                                variant="ghost"
                                                size="sm"
                                                icon="ellipsis-horizontal"
                                                aria-label="More actions for {{ $pot->name }}"
                                            />
                                            <flux:menu>
                                                <flux:menu.item
                                                    wire:click="openEdit({{ $pot->id }})"
                                                    x-on:click="
                                                        if (window.innerWidth < 768) {
                                                            $dispatch('open-sheet', { name: 'pot-form' });
                                                        } else {
                                                            $flux.modal('pot-form').show();
                                                        }
                                                    "
                                                >Edit</flux:menu.item>
                                                <flux:menu.item
                                                    wire:click="$set('operationPotId', {{ $pot->id }}); $set('operationKind', 'withdraw')"
                                                    x-on:click="
                                                        if (window.innerWidth < 768) {
                                                            $dispatch('open-sheet', { name: 'pot-withdraw' });
                                                        } else {
                                                            $flux.modal('pot-withdraw').show();
                                                        }
                                                    "
                                                >Withdraw</flux:menu.item>
                                                <flux:menu.item wire:click="confirmArchive({{ $pot->id }})">Archive</flux:menu.item>
                                            </flux:menu>
                                        </flux:dropdown>
                                    </div>
                                @endif

                                {{-- Inline movement history (D-17) — Alpine x-show / x-collapse --}}
                                @if (count($pot->recentMovements) > 0)
                                    <div
                                        x-data="{ open: false }"
                                        class="mt-3"
                                    >
                                        <button
                                            type="button"
                                            x-on:click="open = !open"
                                            :aria-expanded="open.toString()"
                                            aria-controls="pot-history-{{ $pot->id }}"
                                            class="text-xs text-slate-400 hover:text-slate-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 dark:hover:text-slate-300"
                                        >
                                            <span x-show="!open">Show history ↓</span>
                                            <span x-show="open" x-cloak>Hide history ↑</span>
                                        </button>

                                        <div
                                            id="pot-history-{{ $pot->id }}"
                                            x-show="open"
                                            x-collapse
                                            class="mt-2 border-t border-slate-100 dark:border-slate-800"
                                        >
                                            <ul>
                                                @foreach ($pot->recentMovements as $movement)
                                                    @php
                                                        $isIncoming = in_array($movement->kind, ['fund', 'transfer_in'], true);
                                                        $label = match ($movement->kind) {
                                                            'fund' => 'Fund',
                                                            'withdraw' => 'Withdraw',
                                                            'transfer_in' => 'Moved from ' . ($movement->counterpartPotName ?? 'pot'),
                                                            'transfer_out' => 'Moved to ' . ($movement->counterpartPotName ?? 'pot'),
                                                            default => $movement->kind,
                                                        };
                                                    @endphp
                                                    <li class="flex items-center justify-between gap-4 py-2 px-0 text-sm">
                                                        <div class="min-w-0">
                                                            <span class="text-xs text-slate-400 dark:text-slate-500 tabular-nums">{{ substr($movement->createdAt, 0, 10) }}</span>
                                                            <span class="ml-2 text-sm text-slate-500 dark:text-slate-400">{{ $label }}</span>
                                                            @if ($movement->memo !== null)
                                                                <span class="ml-1 text-xs italic text-slate-400 dark:text-slate-500">{{ $movement->memo }}</span>
                                                            @endif
                                                        </div>
                                                        <span
                                                            class="shrink-0 text-sm tabular-nums {{ $isIncoming ? 'text-emerald-600 dark:text-emerald-400' : 'text-slate-500 dark:text-slate-400' }}"
                                                            style="font-family: var(--font-mono, ui-monospace, monospace); font-variant-numeric: tabular-nums;"
                                                        >
                                                            {{ $isIncoming ? '+' : '' }}{{ $fmt($movement->amountMinor, $movement->currency) }}
                                                        </span>
                                                    </li>
                                                @endforeach
                                            </ul>
                                        </div>
                                    </div>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endforeach
        </div>{{-- end .pots-desktop-list --}}
    @endif

    {{-- Archived pots disclosure --}}
    @if (count($archived) > 0)
        <div class="mt-8">
            <button
                type="button"
                wire:click="$toggle('showArchived')"
                class="flex items-center gap-2 text-sm text-slate-500 hover:text-slate-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 dark:hover:text-slate-100 dark:text-slate-400"
            >
                <span>Archived pots ({{ count($archived) }})</span>
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="h-4 w-4 transition-transform {{ $showArchived ? 'rotate-180' : '' }}" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="m19.5 8.25-7.5 7.5-7.5-7.5" />
                </svg>
            </button>

            @if ($showArchived)
                <ul class="mt-4 space-y-4">
                    @foreach ($archived as $pot)
                        <li class="rounded-lg border border-slate-200 bg-white p-4 opacity-60 dark:bg-slate-950 dark:border-slate-700" wire:key="archived-pot-{{ $pot->id }}">
                            <div class="flex items-center justify-between gap-3">
                                <p class="min-w-0 truncate text-sm font-semibold text-slate-500 dark:text-slate-400">{{ $pot->name }}</p>
                                <span class="inline-flex items-center rounded-full bg-slate-100 px-2.5 py-[3px] text-xs font-medium text-slate-500 dark:bg-slate-800 dark:text-slate-400">Archived</span>
                            </div>
                            <p class="mt-1 text-sm text-slate-500 dark:text-slate-400" style="font-family: var(--font-mono, ui-monospace, monospace); font-variant-numeric: tabular-nums;">
                                {{ $fmt($pot->balanceMinor, $pot->currency) }}
                            </p>
                            <div class="mt-3">
                                <flux:dropdown>
                                    <flux:button
                                        variant="ghost"
                                        size="sm"
                                        icon="ellipsis-horizontal"
                                        aria-label="More actions for {{ $pot->name }}"
                                    />
                                    <flux:menu>
                                        <flux:menu.item wire:click="restorePot({{ $pot->id }})">Restore</flux:menu.item>
                                    </flux:menu>
                                </flux:dropdown>
                            </div>
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>
    @endif

    {{-- ------------------------------------------------------------------- --}}
    {{-- Phone bottom sheet: Create / Edit pot (D-10, Pitfall 6)             --}}
    {{-- At <768px: slides up as a sheet. At >=768px: flux modal handles it. --}}
    {{-- ------------------------------------------------------------------- --}}
    <x-core::bottom-sheet name="pot-form" title="{{ $editPotId ? 'Edit pot' : 'Create a pot' }}">
        <form
            wire:submit="{{ $editPotId ? 'updatePot' : 'createPot' }}"
            class="space-y-4"
        >
            <div>
                <label for="pot-name-sheet" class="mb-1 block text-xs font-medium uppercase tracking-wide text-slate-500 dark:text-slate-400">Name</label>
                <input
                    type="text"
                    id="pot-name-sheet"
                    wire:model="name"
                    placeholder="e.g. Holiday fund"
                    style="font-size: 16px;"
                    class="block w-full rounded-md border border-slate-200 bg-white px-3 py-2 text-slate-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 dark:bg-slate-950 dark:border-slate-700 dark:text-slate-100"
                />
                @if ($errorName !== '')
                    <p class="mt-1 text-sm text-rose-600 dark:text-rose-400">{{ $errorName }}</p>
                @endif
            </div>
            <div>
                <label for="pot-account-sheet" class="mb-1 block text-xs font-medium uppercase tracking-wide text-slate-500 dark:text-slate-400">Account</label>
                <select
                    id="pot-account-sheet"
                    wire:model="accountId"
                    @if ($editPotId) disabled @endif
                    style="font-size: 16px;"
                    class="block w-full rounded-md border border-slate-200 bg-white px-3 py-2 text-slate-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 dark:bg-slate-950 dark:border-slate-700 dark:text-slate-100 {{ $editPotId ? 'opacity-50 cursor-not-allowed' : '' }}"
                >
                    <option value="">Select an account</option>
                    @foreach ($accounts as $account)
                        <option value="{{ $account->id }}">{{ $account->name }}</option>
                    @endforeach
                </select>
            </div>
            <div class="flex gap-3 pt-2">
                <button
                    type="submit"
                    class="flex-1 rounded-md bg-slate-900 px-4 py-3 text-sm font-medium text-white hover:bg-slate-700 focus:outline-none dark:bg-slate-100 dark:text-slate-900 dark:hover:bg-white"
                >{{ $editPotId ? 'Save changes' : 'Save pot' }}</button>
                <button
                    type="button"
                    wire:click="cancel"
                    class="rounded-md border border-slate-200 px-4 py-3 text-sm font-medium text-slate-500 hover:text-slate-900 focus:outline-none dark:border-slate-700 dark:hover:text-slate-100"
                >Cancel</button>
            </div>
        </form>
    </x-core::bottom-sheet>

    {{-- ------------------------------------------------------------------- --}}
    {{-- Phone bottom sheet: Fund pot (D-10)                                  --}}
    {{-- ------------------------------------------------------------------- --}}
    <x-core::bottom-sheet name="pot-fund" title="Fund pot">
        <form wire:submit="fundPot" class="space-y-4">
            <div>
                <label for="fund-amount-sheet" class="mb-1 block text-xs font-medium uppercase tracking-wide text-slate-500 dark:text-slate-400">Amount</label>
                <input
                    type="text"
                    id="fund-amount-sheet"
                    wire:model="operationAmount"
                    inputmode="decimal"
                    placeholder="0.00"
                    style="font-size: 16px; font-variant-numeric: tabular-nums;"
                    class="block w-full rounded-md border border-slate-200 bg-white px-3 py-2 text-slate-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 dark:bg-slate-950 dark:border-slate-700 dark:text-slate-100"
                />
                @if ($errorAmount !== '')
                    <p class="mt-1 text-sm text-rose-600 dark:text-rose-400">{{ $errorAmount }}</p>
                @endif
            </div>
            <div>
                <label for="fund-memo-sheet" class="mb-1 block text-xs font-medium uppercase tracking-wide text-slate-500 dark:text-slate-400">Note (optional)</label>
                <input
                    type="text"
                    id="fund-memo-sheet"
                    wire:model="operationMemo"
                    placeholder="e.g. Monthly savings"
                    style="font-size: 16px;"
                    class="block w-full rounded-md border border-slate-200 bg-white px-3 py-2 text-slate-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 dark:bg-slate-950 dark:border-slate-700 dark:text-slate-100"
                />
            </div>
            <div class="flex gap-3 pt-2">
                <button type="submit" class="flex-1 rounded-md bg-slate-900 px-4 py-3 text-sm font-medium text-white hover:bg-slate-700 focus:outline-none dark:bg-slate-100 dark:text-slate-900 dark:hover:bg-white">Fund pot</button>
                <button type="button" wire:click="$dispatch('modal-close', { name: 'pot-fund' })" class="rounded-md border border-slate-200 px-4 py-3 text-sm font-medium text-slate-500 focus:outline-none dark:border-slate-700">Cancel</button>
            </div>
        </form>
    </x-core::bottom-sheet>

    {{-- ------------------------------------------------------------------- --}}
    {{-- Phone bottom sheet: Move pot (D-10)                                  --}}
    {{-- ------------------------------------------------------------------- --}}
    <x-core::bottom-sheet name="pot-move" title="Move funds">
        @php
            $moveSrcPotSheet = null;
            foreach ($groups as $accountPots) {
                foreach ($accountPots as $p) {
                    if ($p->id === $operationPotId) { $moveSrcPotSheet = $p; break 2; }
                }
            }
            $moveDestPotsSheet = $moveSrcPotSheet !== null
                ? array_filter($potsForMove[$moveSrcPotSheet->accountId] ?? [], static fn($p) => $p->id !== $operationPotId)
                : [];
        @endphp
        <form wire:submit="movePot" class="space-y-4">
            <div>
                <label for="move-to-sheet" class="mb-1 block text-xs font-medium uppercase tracking-wide text-slate-500 dark:text-slate-400">Move to</label>
                <select
                    id="move-to-sheet"
                    wire:model="transferTargetPotId"
                    style="font-size: 16px;"
                    class="block w-full rounded-md border border-slate-200 bg-white px-3 py-2 text-slate-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 dark:bg-slate-950 dark:border-slate-700 dark:text-slate-100"
                >
                    <option value="">{{ count($moveDestPotsSheet) === 0 ? 'No other pots' : 'Select a pot' }}</option>
                    @foreach ($moveDestPotsSheet as $destPot)
                        <option value="{{ $destPot->id }}">{{ $destPot->name }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label for="move-amount-sheet" class="mb-1 block text-xs font-medium uppercase tracking-wide text-slate-500 dark:text-slate-400">Amount</label>
                <input
                    type="text"
                    id="move-amount-sheet"
                    wire:model="operationAmount"
                    inputmode="decimal"
                    placeholder="0.00"
                    style="font-size: 16px; font-variant-numeric: tabular-nums;"
                    class="block w-full rounded-md border border-slate-200 bg-white px-3 py-2 text-slate-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 dark:bg-slate-950 dark:border-slate-700 dark:text-slate-100"
                />
                @if ($errorAmount !== '')
                    <p class="mt-1 text-sm text-rose-600 dark:text-rose-400">{{ $errorAmount }}</p>
                @endif
            </div>
            <div class="flex gap-3 pt-2">
                <button type="submit" @disabled(count($moveDestPotsSheet) === 0) class="flex-1 rounded-md bg-slate-900 px-4 py-3 text-sm font-medium text-white hover:bg-slate-700 focus:outline-none disabled:opacity-50 dark:bg-slate-100 dark:text-slate-900 dark:hover:bg-white">Move funds</button>
                <button type="button" wire:click="$dispatch('modal-close', { name: 'pot-move' })" class="rounded-md border border-slate-200 px-4 py-3 text-sm font-medium text-slate-500 focus:outline-none dark:border-slate-700">Cancel</button>
            </div>
        </form>
    </x-core::bottom-sheet>

    {{-- ------------------------------------------------------------------- --}}
    {{-- Create / Edit modal (pot-form)                                       --}}
    {{-- ------------------------------------------------------------------- --}}
    <flux:modal name="pot-form" dismissible>
        <div class="pt-[44px]" style="max-width: 520px;">
            <h2 class="text-base font-semibold text-slate-900 dark:text-slate-100">
                {{ $editPotId ? 'Edit pot' : 'Create a pot' }}
            </h2>
            <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">
                {{ $editPotId ? 'Update the name or link for this pot.' : 'Name a virtual sub-balance within an account.' }}
            </p>

            <form
                wire:submit="{{ $editPotId ? 'updatePot' : 'createPot' }}"
                class="mt-6 space-y-4"
            >
                {{-- Name --}}
                <div>
                    <label for="pot-name" class="mb-1 block text-xs font-medium uppercase tracking-wide text-slate-500 dark:text-slate-400">Name</label>
                    <input
                        type="text"
                        id="pot-name"
                        wire:model="name"
                        placeholder="e.g. Holiday fund"
                        @if ($errorName !== '') aria-invalid="true" aria-describedby="pot-name-error" @endif
                        class="block w-full rounded-md border border-slate-200 bg-white px-3 py-2 text-sm text-slate-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 dark:bg-slate-950 dark:border-slate-700 dark:text-slate-100"
                    />
                    @if ($errorName !== '')
                        <p id="pot-name-error" class="mt-1 text-sm text-rose-600 dark:text-rose-400">{{ $errorName }}</p>
                    @endif
                </div>

                {{-- Account (disabled on edit) --}}
                <div>
                    <label for="pot-account" class="mb-1 block text-xs font-medium uppercase tracking-wide text-slate-500 dark:text-slate-400">Account</label>
                    <select
                        id="pot-account"
                        wire:model="accountId"
                        @if ($editPotId) disabled @endif
                        class="block w-full rounded-md border border-slate-200 bg-white px-3 py-2 text-sm text-slate-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 dark:bg-slate-950 dark:border-slate-700 dark:text-slate-100 {{ $editPotId ? 'opacity-50 cursor-not-allowed' : '' }}"
                    >
                        <option value="">Select an account</option>
                        @foreach ($accounts as $account)
                            <option value="{{ $account->id }}">{{ $account->name }}</option>
                        @endforeach
                    </select>
                </div>

                {{-- Initial amount (create only, D-08) --}}
                @if (! $editPotId)
                    <div>
                        <label for="pot-amount" class="mb-1 block text-xs font-medium uppercase tracking-wide text-slate-500 dark:text-slate-400">Initial amount (optional)</label>
                        <input
                            type="text"
                            id="pot-amount"
                            wire:model="amount"
                            inputmode="decimal"
                            placeholder="0.00"
                            @if ($errorAmount !== '') aria-invalid="true" aria-describedby="pot-amount-error" @endif
                            class="block w-full rounded-md border border-slate-200 bg-white px-3 py-2 text-sm text-slate-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 dark:bg-slate-950 dark:border-slate-700 dark:text-slate-100"
                            style="font-variant-numeric: tabular-nums;"
                        />
                        <p class="mt-1 text-xs text-slate-400 dark:text-slate-500">Amount is deducted from unallocated. Leave blank to create empty.</p>
                        @if ($errorAmount !== '')
                            <p id="pot-amount-error" class="mt-1 text-sm text-rose-600 dark:text-rose-400">{{ $errorAmount }}</p>
                        @endif
                    </div>
                @endif

                {{-- Link to (D-15): Goal | None — category-linking is retired --}}
                <div>
                    <label class="mb-1 block text-xs font-medium uppercase tracking-wide text-slate-500 dark:text-slate-400">Link to (optional)</label>
                    <div class="flex gap-2">
                        <label class="flex cursor-pointer items-center gap-1.5 rounded-md border px-3 py-1.5 text-sm {{ $linkType === 'goal' ? 'border-slate-900 bg-slate-900 text-white dark:border-slate-100 dark:bg-slate-100 dark:text-slate-900' : 'border-slate-200 bg-white text-slate-600 hover:bg-slate-50 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-400 dark:hover:bg-slate-900' }}">
                            <input type="radio" wire:model.live="linkType" value="goal" class="sr-only" />
                            Goal
                        </label>
                        <label class="flex cursor-pointer items-center gap-1.5 rounded-md border px-3 py-1.5 text-sm {{ $linkType === 'none' ? 'border-slate-900 bg-slate-900 text-white dark:border-slate-100 dark:bg-slate-100 dark:text-slate-900' : 'border-slate-200 bg-white text-slate-600 hover:bg-slate-50 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-400 dark:hover:bg-slate-900' }}">
                            <input type="radio" wire:model.live="linkType" value="none" class="sr-only" />
                            None
                        </label>
                    </div>
                    @if ($linkType === 'goal')
                        <select
                            wire:model="goalId"
                            class="mt-2 block w-full rounded-md border border-slate-200 bg-white px-3 py-2 text-sm text-slate-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 dark:bg-slate-950 dark:border-slate-700 dark:text-slate-100"
                        >
                            <option value="">Select a goal</option>
                            @foreach ($goalsForPicker as $goal)
                                <option value="{{ $goal->id }}">{{ $goal->name }}</option>
                            @endforeach
                        </select>
                    @endif
                </div>

                {{-- Modal footer --}}
                <div class="flex justify-end gap-2 pt-2">
                    <button
                        type="button"
                        wire:click="cancel"
                        class="rounded-md px-4 py-2 text-sm font-medium text-slate-500 hover:text-slate-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 dark:hover:text-slate-100 dark:text-slate-400"
                    >Cancel</button>
                    <button
                        type="submit"
                        class="inline-flex items-center rounded-md bg-slate-900 px-4 py-2 text-sm font-medium text-white hover:bg-slate-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 focus-visible:ring-offset-2 dark:bg-slate-100 dark:text-slate-900 dark:hover:bg-white"
                    >{{ $editPotId ? 'Save changes' : 'Save pot' }}</button>
                </div>
            </form>
        </div>
    </flux:modal>

    {{-- ------------------------------------------------------------------- --}}
    {{-- Fund modal (pot-fund)                                                --}}
    {{-- ------------------------------------------------------------------- --}}
    <flux:modal name="pot-fund" dismissible>
        <div class="pt-[44px]" style="max-width: 480px;">
            @php
                $fundPot = null;
                foreach ($groups as $accountPots) {
                    foreach ($accountPots as $p) {
                        if ($p->id === $operationPotId) {
                            $fundPot = $p;
                            break 2;
                        }
                    }
                }
                $fundRec = $fundPot !== null && isset($reconciliations[$fundPot->accountId])
                    ? $reconciliations[$fundPot->accountId]
                    : null;
            @endphp
            <h2 class="text-base font-semibold text-slate-900 dark:text-slate-100">
                Fund {{ $fundPot?->name ?? 'pot' }}
            </h2>
            <form wire:submit="fundPot" class="mt-6 space-y-4">
                <div>
                    <label for="fund-amount" class="mb-1 block text-xs font-medium uppercase tracking-wide text-slate-500 dark:text-slate-400">Amount</label>
                    <input
                        type="text"
                        id="fund-amount"
                        wire:model="operationAmount"
                        inputmode="decimal"
                        placeholder="0.00"
                        @if ($errorAmount !== '') aria-invalid="true" aria-describedby="fund-amount-error" @endif
                        class="block w-full rounded-md border border-slate-200 bg-white px-3 py-2 text-sm text-slate-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 dark:bg-slate-950 dark:border-slate-700 dark:text-slate-100"
                        style="font-variant-numeric: tabular-nums;"
                    />
                    @if ($fundRec !== null)
                        <p class="mt-1 text-xs text-slate-400 dark:text-slate-500" style="font-variant-numeric: tabular-nums;">
                            Available to allocate: {{ $fmt(max(0, $fundRec->unallocatedMinor), $fundRec->currency) }} (unallocated)
                        </p>
                    @endif
                    @if ($errorAmount !== '')
                        <p id="fund-amount-error" class="mt-1 text-sm text-rose-600 dark:text-rose-400">{{ $errorAmount }}</p>
                    @endif
                </div>
                <div>
                    <label for="fund-memo" class="mb-1 block text-xs font-medium uppercase tracking-wide text-slate-500 dark:text-slate-400">Note (optional)</label>
                    <input
                        type="text"
                        id="fund-memo"
                        wire:model="operationMemo"
                        placeholder="e.g. Monthly savings"
                        class="block w-full rounded-md border border-slate-200 bg-white px-3 py-2 text-sm text-slate-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 dark:bg-slate-950 dark:border-slate-700 dark:text-slate-100"
                    />
                </div>
                <div class="flex justify-end gap-2 pt-2">
                    <button type="button" wire:click="$dispatch('modal-close', { name: 'pot-fund' })" class="rounded-md px-4 py-2 text-sm font-medium text-slate-500 hover:text-slate-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 dark:hover:text-slate-100 dark:text-slate-400">Cancel</button>
                    <button type="submit" class="inline-flex items-center rounded-md bg-slate-900 px-4 py-2 text-sm font-medium text-white hover:bg-slate-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 focus-visible:ring-offset-2 dark:bg-slate-100 dark:text-slate-900 dark:hover:bg-white">Fund pot</button>
                </div>
            </form>
        </div>
    </flux:modal>

    {{-- ------------------------------------------------------------------- --}}
    {{-- Move modal (pot-move)                                                --}}
    {{-- ------------------------------------------------------------------- --}}
    <flux:modal name="pot-move" dismissible>
        <div class="pt-[44px]" style="max-width: 480px;">
            @php
                $moveSrcPot = null;
                foreach ($groups as $accountPots) {
                    foreach ($accountPots as $p) {
                        if ($p->id === $operationPotId) {
                            $moveSrcPot = $p;
                            break 2;
                        }
                    }
                }
                $moveDestPots = $moveSrcPot !== null
                    ? array_filter($potsForMove[$moveSrcPot->accountId] ?? [], static fn($p) => $p->id !== $operationPotId)
                    : [];
            @endphp
            <h2 class="text-base font-semibold text-slate-900 dark:text-slate-100">
                Move from {{ $moveSrcPot?->name ?? 'pot' }}
            </h2>
            <form wire:submit="movePot" class="mt-6 space-y-4">
                <div>
                    <label for="move-to" class="mb-1 block text-xs font-medium uppercase tracking-wide text-slate-500 dark:text-slate-400">Move to</label>
                    <select
                        id="move-to"
                        wire:model="transferTargetPotId"
                        class="block w-full rounded-md border border-slate-200 bg-white px-3 py-2 text-sm text-slate-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 dark:bg-slate-950 dark:border-slate-700 dark:text-slate-100"
                    >
                        <option value="">{{ count($moveDestPots) === 0 ? 'No other pots in this account' : 'Select a pot' }}</option>
                        @foreach ($moveDestPots as $destPot)
                            <option value="{{ $destPot->id }}">{{ $destPot->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label for="move-amount" class="mb-1 block text-xs font-medium uppercase tracking-wide text-slate-500 dark:text-slate-400">Amount</label>
                    <input
                        type="text"
                        id="move-amount"
                        wire:model="operationAmount"
                        inputmode="decimal"
                        placeholder="0.00"
                        @if ($errorAmount !== '') aria-invalid="true" aria-describedby="move-amount-error" @endif
                        class="block w-full rounded-md border border-slate-200 bg-white px-3 py-2 text-sm text-slate-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 dark:bg-slate-950 dark:border-slate-700 dark:text-slate-100"
                        style="font-variant-numeric: tabular-nums;"
                    />
                    @if ($moveSrcPot !== null)
                        <p class="mt-1 text-xs text-slate-400 dark:text-slate-500" style="font-variant-numeric: tabular-nums;">
                            Available in {{ $moveSrcPot->name }}: {{ $fmt($moveSrcPot->balanceMinor, $moveSrcPot->currency) }}
                        </p>
                    @endif
                    @if ($errorAmount !== '')
                        <p id="move-amount-error" class="mt-1 text-sm text-rose-600 dark:text-rose-400">{{ $errorAmount }}</p>
                    @endif
                </div>
                <div>
                    <label for="move-memo" class="mb-1 block text-xs font-medium uppercase tracking-wide text-slate-500 dark:text-slate-400">Note (optional)</label>
                    <input
                        type="text"
                        id="move-memo"
                        wire:model="operationMemo"
                        placeholder="e.g. Transfer for holiday"
                        class="block w-full rounded-md border border-slate-200 bg-white px-3 py-2 text-sm text-slate-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 dark:bg-slate-950 dark:border-slate-700 dark:text-slate-100"
                    />
                </div>
                <div class="flex justify-end gap-2 pt-2">
                    <button type="button" wire:click="$dispatch('modal-close', { name: 'pot-move' })" class="rounded-md px-4 py-2 text-sm font-medium text-slate-500 hover:text-slate-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 dark:hover:text-slate-100 dark:text-slate-400">Cancel</button>
                    <button type="submit" @disabled(count($moveDestPots) === 0) class="inline-flex items-center rounded-md bg-slate-900 px-4 py-2 text-sm font-medium text-white hover:bg-slate-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 focus-visible:ring-offset-2 dark:bg-slate-100 dark:text-slate-900 dark:hover:bg-white disabled:opacity-50 disabled:cursor-not-allowed">Move funds</button>
                </div>
            </form>
        </div>
    </flux:modal>

    {{-- ------------------------------------------------------------------- --}}
    {{-- Withdraw modal (pot-withdraw)                                        --}}
    {{-- ------------------------------------------------------------------- --}}
    <flux:modal name="pot-withdraw" dismissible>
        <div class="pt-[44px]" style="max-width: 480px;">
            @php
                $withdrawPot = null;
                foreach ($groups as $accountPots) {
                    foreach ($accountPots as $p) {
                        if ($p->id === $operationPotId) {
                            $withdrawPot = $p;
                            break 2;
                        }
                    }
                }
            @endphp
            <h2 class="text-base font-semibold text-slate-900 dark:text-slate-100">
                Withdraw from {{ $withdrawPot?->name ?? 'pot' }}
            </h2>
            <form wire:submit="withdrawPot" class="mt-6 space-y-4">
                <div>
                    <label for="withdraw-amount" class="mb-1 block text-xs font-medium uppercase tracking-wide text-slate-500 dark:text-slate-400">Amount</label>
                    <input
                        type="text"
                        id="withdraw-amount"
                        wire:model="operationAmount"
                        inputmode="decimal"
                        placeholder="0.00"
                        @if ($errorAmount !== '') aria-invalid="true" aria-describedby="withdraw-amount-error" @endif
                        class="block w-full rounded-md border border-slate-200 bg-white px-3 py-2 text-sm text-slate-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 dark:bg-slate-950 dark:border-slate-700 dark:text-slate-100"
                        style="font-variant-numeric: tabular-nums;"
                    />
                    @if ($withdrawPot !== null)
                        <p class="mt-1 text-xs text-slate-400 dark:text-slate-500" style="font-variant-numeric: tabular-nums;">
                            Available in {{ $withdrawPot->name }}: {{ $fmt($withdrawPot->balanceMinor, $withdrawPot->currency) }}
                        </p>
                    @endif
                    @if ($errorAmount !== '')
                        <p id="withdraw-amount-error" class="mt-1 text-sm text-rose-600 dark:text-rose-400">{{ $errorAmount }}</p>
                    @endif
                </div>
                <div>
                    <label for="withdraw-memo" class="mb-1 block text-xs font-medium uppercase tracking-wide text-slate-500 dark:text-slate-400">Note (optional)</label>
                    <input
                        type="text"
                        id="withdraw-memo"
                        wire:model="operationMemo"
                        placeholder="e.g. Withdrawal"
                        class="block w-full rounded-md border border-slate-200 bg-white px-3 py-2 text-sm text-slate-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 dark:bg-slate-950 dark:border-slate-700 dark:text-slate-100"
                    />
                </div>
                <div class="flex justify-end gap-2 pt-2">
                    <button type="button" wire:click="$dispatch('modal-close', { name: 'pot-withdraw' })" class="rounded-md px-4 py-2 text-sm font-medium text-slate-500 hover:text-slate-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 dark:hover:text-slate-100 dark:text-slate-400">Cancel</button>
                    <button type="submit" class="inline-flex items-center rounded-md bg-slate-900 px-4 py-2 text-sm font-medium text-white hover:bg-slate-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 focus-visible:ring-offset-2 dark:bg-slate-100 dark:text-slate-900 dark:hover:bg-white">Withdraw</button>
                </div>
            </form>
        </div>
    </flux:modal>
</div>
