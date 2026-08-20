@use('Modules\Core\Public\Support\Lang')
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

    // Derived once for both withdraw surfaces. It used to be derived inside
    // the modal, which put it out of scope for the sheet that renders first.
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

{{--
    Phone responsive pass (D-06, D-10, D-12, UI-SPEC §8).

    At <768px:
    - Pots render as .card-list-item rows (pot name .primary, balance .amount, account .secondary)
    - Fund/Move/Create/Edit modals become bottom sheets (x-core::bottom-sheet)
    - Row actions (Fund, Move, Edit) are always visible on phone (D-12)
    At >=768px: existing card grid + Flux modals unchanged.
--}}
{{-- Inside the single root, not beside it. Livewire binds wire:id to the
     FIRST top-level element, so a <style> tag out here became the whole
     component: every wire:model and wire:click in the markup below was
     orphaned, no /livewire/update request was ever sent, and each write
     silently did nothing while the sheets still opened — those ride
     $dispatch, which is a plain window event and needs no binding. --}}
<div class="mx-auto max-w-3xl px-4 py-12">
    <style>
        @media (min-width: 768px) {
            .pots-phone-list { display: none !important; }
        }
        @media (max-width: 767px) {
            .pots-desktop-list { display: none !important; }
        }
    </style>

    {{-- Page header --}}
    <header class="mb-8 flex items-start justify-between gap-4">
        <div>
            <h1 class="text-2xl font-semibold tracking-tight text-slate-900 dark:text-slate-100">{{ Lang::get('pots::messages.heading') }}</h1>
            <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">{{ Lang::get('pots::messages.subtitle') }}</p>
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
            >{{ Lang::get('pots::messages.add_pot') }}</button>
        @endif
    </header>

    {{-- No accounts / no pots: empty state --}}
    @if (count($accounts) === 0 || count($groups) === 0)
        <x-core::empty-state
            :heading="Lang::get('pots::messages.empty.heading')"
            :body="Lang::get('pots::messages.empty.body')"
        >
            @if (count($accounts) > 0)
                <button
                    type="button"
                    x-on:click="$flux.modal('pot-form').show()"
                    wire:click="$set('editPotId', 0)"
                    class="inline-flex items-center rounded-md bg-slate-900 px-4 py-2 text-sm font-medium text-white hover:bg-slate-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 focus-visible:ring-offset-2 dark:bg-slate-100 dark:text-slate-900 dark:hover:bg-white"
                >{{ Lang::get('pots::messages.empty.cta') }}</button>
            @endif
        </x-core::empty-state>
    @else
        {{-- Phone: flat .card-list-item list across all accounts (D-06) --}}
        <div class="pots-phone-list rounded-lg border border-slate-200 bg-white dark:bg-slate-950 dark:border-slate-700 overflow-hidden">
            @foreach ($groups as $accountId => $pots)
                @foreach ($pots as $pot)
                    <div class="card-list-item flex-wrap">
                        <div class="flex-1 min-w-0">
                            <p class="primary truncate">{{ $pot->name }}</p>
                            <p class="secondary">{{ $pot->accountName }}</p>
                        </div>
                        <span class="amount">{{ $fmt($pot->balanceMinor, $pot->currency) }}</span>
                        {{-- Actions on their own line. Five 44px targets and
                             the amount left the name 6px wide on a 375pt
                             screen, so the row said which pot it was not. --}}
                        <div class="flex w-full items-center justify-end gap-1">
                        {{-- Row actions always visible on phone (D-12) --}}
                        <x-core::emoji-action
                            :label="Lang::get('pots::messages.actions.fund')"
                            x-on:click=" $wire.set('operationPotId', {{ $pot->id }}); $wire.set('operationKind', 'fund'); $dispatch('open-sheet', { name: 'pot-fund' }); "
                        >💰</x-core::emoji-action>
                        <x-core::emoji-action
                            :label="Lang::get('pots::messages.actions.move')"
                            x-on:click=" $wire.set('operationPotId', {{ $pot->id }}); $wire.set('operationKind', 'transfer'); $dispatch('open-sheet', { name: 'pot-move' }); "
                        >🔄</x-core::emoji-action>
                        {{-- Withdraw, edit and archive lived only in the desktop
                             kebab, which the phone list hides — so on a phone
                             money could go into a pot and never come out. --}}
                        <x-core::emoji-action
                            :label="Lang::get('pots::messages.actions.withdraw')"
                            x-on:click=" $wire.set('operationPotId', {{ $pot->id }}); $wire.set('operationKind', 'withdraw'); $dispatch('open-sheet', { name: 'pot-withdraw' }); "
                        >🏧</x-core::emoji-action>
                        <x-core::emoji-action
                            :label="Lang::get('pots::messages.actions.edit')"
                            x-on:click=" $wire.openEdit({{ $pot->id }}); $dispatch('open-sheet', { name: 'pot-form' }); "
                        >✏️</x-core::emoji-action>
                        <x-core::emoji-action
                            :label="Lang::get('pots::messages.actions.archive')"
                            wire:click="confirmArchive({{ $pot->id }})"
                        >🗄️</x-core::emoji-action>
                        </div>
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
                        <x-core::section-heading :title="$firstPot->accountName" />
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
                        >{{ Lang::get('pots::messages.add_pot') }}</button>
                    </div>

                    {{-- Negative-unallocated amber warning banner (D-02) --}}
                    @if ($rec !== null && $rec->isOverAllocated)
                        <x-core::alert tone="warning" class="mb-2" role="alert">
                            <span class="mr-1" aria-hidden="true">
                                <flux:icon.exclamation-triangle class="inline-block h-4 w-4 align-text-bottom" />
                            </span>
                            {{ Lang::get('pots::messages.recon.over_allocated', ['amount' => $fmt(abs($rec->unallocatedMinor), $rec->currency)]) }}
                        </x-core::alert>
                    @endif

                    {{-- Reconciliation line (D-15) --}}
                    @if ($rec !== null)
                        <p class="mb-4 text-xs text-slate-500 dark:text-slate-400" style="font-family: var(--font-mono, ui-monospace, monospace); font-variant-numeric: tabular-nums;">
                            {{ Lang::get('pots::messages.recon.real_balance') }} {{ $fmt($rec->realBalanceMinor, $rec->currency) }}
                            <span aria-hidden="true"> · </span>
                            {{ Lang::get('pots::messages.recon.allocated') }} {{ $fmt($rec->allocatedMinor, $rec->currency) }}
                            <span aria-hidden="true"> · </span>
                            {{ Lang::get('pots::messages.recon.unallocated') }} <span class="{{ $rec->isOverAllocated ? 'font-medium text-amber-600 dark:text-amber-400' : '' }}">{{ $fmt($rec->unallocatedMinor, $rec->currency) }}</span>
                        </p>
                    @endif

                    {{-- Pot cards --}}
                    <ul class="space-y-4">
                        @foreach ($pots as $pot)
                            <x-core::card
                                tag="li"
                                padding="tight"
                                wire:key="pot-{{ $pot->id }}"
                            >
                                {{-- Top row: pot name + link chip --}}
                                <div class="flex items-center justify-between gap-3">
                                    <p class="min-w-0 truncate text-sm font-semibold text-slate-900 dark:text-slate-100">{{ $pot->name }}</p>
                                    <div class="flex shrink-0 items-center gap-2">
                                        @if ($pot->goalId !== null)
                                            <x-core::status-pill tone="positive">
                                                {{ Lang::get('pots::messages.chip.goal') }} {{ $pot->goalName ?? Lang::get('pots::messages.chip.goal_name_fallback') }}
                                            </x-core::status-pill>
                                        @elseif ($pot->categoryId !== null)
                                            <x-core::status-pill>
                                                {{ $pot->categoryName ?? Lang::get('pots::messages.chip.category_fallback') }}
                                            </x-core::status-pill>
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
                                        {{ $pot->categoryName }}: {{ $fmt($pot->categorySpentMinor, $pot->currency) }} {{ Lang::get('pots::messages.coverage.spent') }} · {{ $fmt($pot->balanceMinor, $pot->currency) }} {{ Lang::get('pots::messages.coverage.in_pot') }}
                                    </p>
                                @endif

                                {{-- Archive micro-confirm or footer action row --}}
                                @if ($archivingPotId === $pot->id)
                                    <div class="mt-3 flex items-center gap-3 rounded-md bg-slate-50 px-3 py-2 dark:bg-slate-900">
                                        <p class="flex-1 text-sm text-slate-700 dark:text-slate-300">
                                            {{ Lang::get('pots::messages.archive_confirm', ['amount' => $fmt($pot->balanceMinor, $pot->currency)]) }}
                                        </p>
                                        <button
                                            type="button"
                                            wire:click="cancelArchive"
                                            class="text-sm text-slate-500 hover:text-slate-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 dark:hover:text-slate-100 dark:text-slate-400"
                                        >{{ Lang::get('pots::messages.common.cancel') }}</button>
                                        <button
                                            type="button"
                                            wire:click="archivePot({{ $pot->id }})"
                                            aria-label="{{ Lang::get('pots::messages.confirm_archive_aria', ['name' => $pot->name]) }}"
                                            class="text-sm font-medium text-rose-600 hover:text-rose-800 focus:outline-none focus-visible:ring-2 focus-visible:ring-rose-600 dark:text-rose-400 dark:hover:text-rose-200"
                                        >{{ Lang::get('pots::messages.actions.archive') }}</button>
                                    </div>
                                @else
                                    {{-- Both actions carried the phone idiom the /budgets
                                         row uses — a sm:hidden glyph beside a sr-only
                                         sm:not-sr-only word — and neither half ever did
                                         anything: this list is display:none under 768px,
                                         so sm: (640px) always applies. The glyph was
                                         never painted, the label never hidden. --}}
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
                                            class="text-sm text-slate-400 hover:text-slate-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 min-w-[44px] min-h-[44px] flex items-center justify-center dark:hover:text-slate-100"
                                            title="{{ Lang::get('pots::messages.actions.fund') }}"
                                            aria-label="{{ Lang::get('pots::messages.actions.fund') }}"
                                        >{{ Lang::get('pots::messages.actions.fund') }}</button>

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
                                            class="text-sm text-slate-400 hover:text-slate-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 min-w-[44px] min-h-[44px] flex items-center justify-center dark:hover:text-slate-100"
                                            title="{{ Lang::get('pots::messages.actions.move') }}"
                                            aria-label="{{ Lang::get('pots::messages.actions.move') }}"
                                        >{{ Lang::get('pots::messages.actions.move') }}</button>

                                        {{-- Kebab dropdown --}}
                                        <flux:dropdown>
                                            <flux:button
                                                variant="ghost"
                                                size="sm"
                                                class="emoji-action"
                                                icon="ellipsis-horizontal"
                                                aria-label="{{ Lang::get('pots::messages.more_actions_aria', ['name' => $pot->name]) }}"
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
                                                >{{ Lang::get('pots::messages.actions.edit') }}</flux:menu.item>
                                                <flux:menu.item
                                                    wire:click="$set('operationPotId', {{ $pot->id }}); $set('operationKind', 'withdraw')"
                                                    x-on:click="
                                                        if (window.innerWidth < 768) {
                                                            $dispatch('open-sheet', { name: 'pot-withdraw' });
                                                        } else {
                                                            $flux.modal('pot-withdraw').show();
                                                        }
                                                    "
                                                >{{ Lang::get('pots::messages.actions.withdraw') }}</flux:menu.item>
                                                <flux:menu.item wire:click="confirmArchive({{ $pot->id }})">{{ Lang::get('pots::messages.actions.archive') }}</flux:menu.item>
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
                                            <span x-show="!open">{{ Lang::get('pots::messages.history.show') }}</span>
                                            <span x-show="open" x-cloak>{{ Lang::get('pots::messages.history.hide') }}</span>
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
                                                            'fund' => Lang::get('pots::messages.movement.fund'),
                                                            'withdraw' => Lang::get('pots::messages.movement.withdraw'),
                                                            'transfer_in' => Lang::get('pots::messages.movement.moved_from', ['name' => $movement->counterpartPotName ?? Lang::get('pots::messages.pot_fallback')]),
                                                            'transfer_out' => Lang::get('pots::messages.movement.moved_to', ['name' => $movement->counterpartPotName ?? Lang::get('pots::messages.pot_fallback')]),
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
                            </x-core::card>
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
                <span>{{ Lang::get('pots::messages.archived.toggle', ['count' => count($archived)]) }}</span>
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
                                <x-core::status-pill>{{ Lang::get('pots::messages.archived.badge') }}</x-core::status-pill>
                            </div>
                            <p class="mt-1 text-sm text-slate-500 dark:text-slate-400" style="font-family: var(--font-mono, ui-monospace, monospace); font-variant-numeric: tabular-nums;">
                                {{ $fmt($pot->balanceMinor, $pot->currency) }}
                            </p>
                            <div class="mt-3">
                                <flux:dropdown>
                                    <flux:button
                                        variant="ghost"
                                        size="sm"
                                        class="emoji-action"
                                        icon="ellipsis-horizontal"
                                        aria-label="{{ Lang::get('pots::messages.more_actions_aria', ['name' => $pot->name]) }}"
                                    />
                                    <flux:menu>
                                        <flux:menu.item wire:click="restorePot({{ $pot->id }})">{{ Lang::get('pots::messages.actions.restore') }}</flux:menu.item>
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
    <x-core::bottom-sheet name="pot-form" title="{{ $editPotId ? Lang::get('pots::messages.form.edit_title') : Lang::get('pots::messages.form.create_title') }}">
        <form
            wire:submit="{{ $editPotId ? 'updatePot' : 'createPot' }}"
            class="space-y-4"
        >
            {{-- The inline 16px stays on every sheet control. size="base" is
                 text-base, and this theme redefines --text-base to 0.9375rem
                 (15px) — under Safari's 16px threshold, so the class alone
                 would let the viewport zoom on focus. --}}
            <div>
                <x-core::form-field
                    name="name"
                    field-id="pot-name-sheet"
                    :label="Lang::get('pots::messages.form.name')"
                    size="base"
                    wire:model="name"
                    :placeholder="Lang::get('pots::messages.form.name_placeholder')"
                    style="font-size: 16px;"
                />
                @if ($errorName !== '')
                    <p class="mt-1 text-sm text-rose-600 dark:text-rose-400">{{ $errorName }}</p>
                @endif
            </div>
            {{-- Bound, not wrapped in @if: a directive inside a component
                 tag's attribute list defeats Blade's tag regex, and the tag
                 then reaches the page as an unknown element that renders
                 nothing. A false attribute is dropped by the bag. --}}
            <x-core::form-field
                name="accountId"
                field-id="pot-account-sheet"
                type="select"
                :label="Lang::get('pots::messages.form.account')"
                size="base"
                wire:model="accountId"
                :disabled="$editPotId !== 0"
                :class="$editPotId ? 'opacity-50 cursor-not-allowed' : ''"
                style="font-size: 16px;"
            >
                <option value="">{{ Lang::get('pots::messages.form.select_account') }}</option>
                @foreach ($accounts as $account)
                    <option value="{{ $account->id }}">{{ $account->name }}</option>
                @endforeach
            </x-core::form-field>
            <div class="flex gap-3 pt-2">
                <button
                    type="submit"
                    class="flex-1 rounded-md bg-slate-900 px-4 py-3 text-sm font-medium text-white hover:bg-slate-700 focus:outline-none dark:bg-slate-100 dark:text-slate-900 dark:hover:bg-white"
                >{{ $editPotId ? Lang::get('pots::messages.form.save_changes') : Lang::get('pots::messages.form.save_pot') }}</button>
                {{-- Closes on the client too: the sheet's open flag is
                     Alpine's, so wire:click alone cleared the form and left
                     the panel on screen. Same defect as the goals sheet. --}}
                <button
                    type="button"
                    x-on:click="open = false"
                    wire:click="cancel"
                    class="rounded-md border border-slate-200 px-4 py-3 text-sm font-medium text-slate-500 hover:text-slate-900 focus:outline-none dark:border-slate-700 dark:hover:text-slate-100"
                >{{ Lang::get('pots::messages.common.cancel') }}</button>
            </div>
        </form>
    </x-core::bottom-sheet>

    {{-- ------------------------------------------------------------------- --}}
    {{-- Phone bottom sheet: Fund pot (D-10)                                  --}}
    {{-- ------------------------------------------------------------------- --}}
    <x-core::bottom-sheet name="pot-fund" title="{{ Lang::get('pots::messages.fund.title') }}">
        <form wire:submit="fundPot" class="space-y-4">
            <div>
                <x-core::form-field
                    name="operationAmount"
                    field-id="fund-amount-sheet"
                    :label="Lang::get('pots::messages.common.amount')"
                    size="base"
                    inputmode="decimal"
                    wire:model="operationAmount"
                    :placeholder="Lang::get('core::components.amount_placeholder')"
                    style="font-size: 16px; font-variant-numeric: tabular-nums;"
                />
                @if ($errorAmount !== '')
                    <p class="mt-1 text-sm text-rose-600 dark:text-rose-400">{{ $errorAmount }}</p>
                @endif
            </div>
            <x-core::form-field
                name="operationMemo"
                field-id="fund-memo-sheet"
                :label="Lang::get('pots::messages.common.note_optional')"
                size="base"
                wire:model="operationMemo"
                :placeholder="Lang::get('pots::messages.fund.note_placeholder')"
                style="font-size: 16px;"
            />
            <div class="flex gap-3 pt-2">
                <button type="submit" class="flex-1 rounded-md bg-slate-900 px-4 py-3 text-sm font-medium text-white hover:bg-slate-700 focus:outline-none dark:bg-slate-100 dark:text-slate-900 dark:hover:bg-white">{{ Lang::get('pots::messages.fund.submit') }}</button>
                <button type="button" wire:click="$dispatch('modal-close', { name: 'pot-fund' })" class="rounded-md border border-slate-200 px-4 py-3 text-sm font-medium text-slate-500 focus:outline-none dark:border-slate-700">{{ Lang::get('pots::messages.common.cancel') }}</button>
            </div>
        </form>
    </x-core::bottom-sheet>

    {{-- ------------------------------------------------------------------- --}}
    {{-- Phone bottom sheet: Move pot (D-10)                                  --}}
    {{-- ------------------------------------------------------------------- --}}
    {{-- Withdraw sheet. The phone row's ↑ dispatches open-sheet for this
         name; without a sheet listening, the only thing that answered was a
         flux:modal the phone never opens, so Withdraw did nothing at all. --}}
    <x-core::bottom-sheet name="pot-withdraw" title="{{ Lang::get('pots::messages.withdraw.heading', ['name' => $withdrawPot?->name ?? Lang::get('pots::messages.pot_fallback')]) }}">
        <form wire:submit="withdrawPot" class="space-y-4">
            <div>
                <x-core::form-field
                    name="operationAmount"
                    field-id="withdraw-amount-sheet"
                    :label="Lang::get('pots::messages.common.amount')"
                    size="base"
                    inputmode="decimal"
                    wire:model="operationAmount"
                    :placeholder="Lang::get('core::components.amount_placeholder')"
                    style="font-size: 16px; font-variant-numeric: tabular-nums;"
                />
                @if ($errorAmount !== '')
                    <p class="mt-1 text-sm text-rose-600 dark:text-rose-400">{{ $errorAmount }}</p>
                @endif
                @if ($withdrawPot !== null)
                    <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">
                        {{ Lang::get('pots::messages.available_in', ['name' => $withdrawPot->name, 'amount' => $fmt($withdrawPot->balanceMinor, $withdrawPot->currency)]) }}
                    </p>
                @endif
            </div>
            <x-core::form-field
                name="operationMemo"
                field-id="withdraw-memo-sheet"
                :label="Lang::get('pots::messages.common.note_optional')"
                size="base"
                wire:model="operationMemo"
                :placeholder="Lang::get('pots::messages.withdraw.note_placeholder')"
                style="font-size: 16px;"
            />
            <div class="flex gap-3 pt-2">
                <button type="submit" class="flex-1 rounded-md bg-slate-900 px-4 py-3 text-sm font-medium text-white hover:bg-slate-700 focus:outline-none dark:bg-slate-100 dark:text-slate-900 dark:hover:bg-white">{{ Lang::get('pots::messages.actions.withdraw') }}</button>
                <button type="button" wire:click="$dispatch('modal-close', { name: 'pot-withdraw' })" class="rounded-md border border-slate-200 px-4 py-3 text-sm font-medium text-slate-500 focus:outline-none dark:border-slate-700">{{ Lang::get('pots::messages.common.cancel') }}</button>
            </div>
        </form>
    </x-core::bottom-sheet>

    <x-core::bottom-sheet name="pot-move" title="{{ Lang::get('pots::messages.move.title') }}">
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
            <x-core::form-field
                name="transferTargetPotId"
                field-id="move-to-sheet"
                type="select"
                :label="Lang::get('pots::messages.move.to')"
                size="base"
                wire:model="transferTargetPotId"
                style="font-size: 16px;"
            >
                <option value="">{{ count($moveDestPotsSheet) === 0 ? Lang::get('pots::messages.move.no_others_short') : Lang::get('pots::messages.move.select_pot') }}</option>
                @foreach ($moveDestPotsSheet as $destPot)
                    <option value="{{ $destPot->id }}">{{ $destPot->name }}</option>
                @endforeach
            </x-core::form-field>
            <div>
                <x-core::form-field
                    name="operationAmount"
                    field-id="move-amount-sheet"
                    :label="Lang::get('pots::messages.common.amount')"
                    size="base"
                    inputmode="decimal"
                    wire:model="operationAmount"
                    :placeholder="Lang::get('core::components.amount_placeholder')"
                    style="font-size: 16px; font-variant-numeric: tabular-nums;"
                />
                @if ($errorAmount !== '')
                    <p class="mt-1 text-sm text-rose-600 dark:text-rose-400">{{ $errorAmount }}</p>
                @endif
            </div>
            <div class="flex gap-3 pt-2">
                <button type="submit" @disabled(count($moveDestPotsSheet) === 0) class="flex-1 rounded-md bg-slate-900 px-4 py-3 text-sm font-medium text-white hover:bg-slate-700 focus:outline-none disabled:opacity-50 dark:bg-slate-100 dark:text-slate-900 dark:hover:bg-white">{{ Lang::get('pots::messages.move.submit') }}</button>
                <button type="button" wire:click="$dispatch('modal-close', { name: 'pot-move' })" class="rounded-md border border-slate-200 px-4 py-3 text-sm font-medium text-slate-500 focus:outline-none dark:border-slate-700">{{ Lang::get('pots::messages.common.cancel') }}</button>
            </div>
        </form>
    </x-core::bottom-sheet>

    {{-- ------------------------------------------------------------------- --}}
    {{-- Create / Edit modal (pot-form)                                       --}}
    {{-- ------------------------------------------------------------------- --}}
    <flux:modal name="pot-form" dismissible>
        <div class="pt-[44px]" style="max-width: 520px;">
            <x-core::section-heading :title="$editPotId ? Lang::get('pots::messages.form.edit_title') : Lang::get('pots::messages.form.create_title')" />
            <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">
                {{ $editPotId ? Lang::get('pots::messages.form.edit_subtitle') : Lang::get('pots::messages.form.create_subtitle') }}
            </p>

            <form
                wire:submit="{{ $editPotId ? 'updatePot' : 'createPot' }}"
                class="mt-6 space-y-4"
            >
                {{-- Name --}}
                <div>
                    <x-core::form-field
                        name="name"
                        field-id="pot-name"
                        :label="Lang::get('pots::messages.form.name')"
                        wire:model="name"
                        :placeholder="Lang::get('pots::messages.form.name_placeholder')"
                        :aria-invalid="$errorName !== '' ? 'true' : null"
                        :aria-describedby="$errorName !== '' ? 'pot-name-error' : null"
                    />
                    @if ($errorName !== '')
                        <p id="pot-name-error" class="mt-1 text-sm text-rose-600 dark:text-rose-400">{{ $errorName }}</p>
                    @endif
                </div>

                {{-- Account (disabled on edit) --}}
                <x-core::form-field
                    name="accountId"
                    field-id="pot-account"
                    type="select"
                    :label="Lang::get('pots::messages.form.account')"
                    wire:model="accountId"
                    :disabled="$editPotId !== 0"
                    :class="$editPotId ? 'opacity-50 cursor-not-allowed' : ''"
                >
                    <option value="">{{ Lang::get('pots::messages.form.select_account') }}</option>
                    @foreach ($accounts as $account)
                        <option value="{{ $account->id }}">{{ $account->name }}</option>
                    @endforeach
                </x-core::form-field>

                {{-- Initial amount (create only, D-08) --}}
                @if (! $editPotId)
                    <div>
                        <x-core::form-field
                            name="amount"
                            field-id="pot-amount"
                            :label="Lang::get('pots::messages.form.initial_amount')"
                            :hint="Lang::get('pots::messages.form.initial_amount_help')"
                            inputmode="decimal"
                            wire:model="amount"
                            :placeholder="Lang::get('core::components.amount_placeholder')"
                            :aria-invalid="$errorAmount !== '' ? 'true' : null"
                            :aria-describedby="$errorAmount !== '' ? 'pot-amount-error' : null"
                            style="font-variant-numeric: tabular-nums;"
                        />
                        @if ($errorAmount !== '')
                            <p id="pot-amount-error" class="mt-1 text-sm text-rose-600 dark:text-rose-400">{{ $errorAmount }}</p>
                        @endif
                    </div>
                @endif

                {{-- Link to (D-15): Goal | None — category-linking is retired --}}
                {{-- "Link to" was a <label> with no `for` and nothing inside it,
                     which labels exactly nothing. wire:model emits no name=, so
                     the two radios were not one group to the browser either. --}}
                <fieldset>
                    <legend class="mb-1 block text-xs font-medium uppercase tracking-wide text-slate-500 dark:text-slate-400">{{ Lang::get('pots::messages.form.link_to') }}</legend>
                    <div class="flex gap-2">
                        <label class="flex cursor-pointer items-center gap-1.5 rounded-md border px-3 py-1.5 text-sm {{ $linkType === 'goal' ? 'border-slate-900 bg-slate-900 text-white dark:border-slate-100 dark:bg-slate-100 dark:text-slate-900' : 'border-slate-200 bg-white text-slate-600 hover:bg-slate-50 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-400 dark:hover:bg-slate-900' }}">
                            <input type="radio" name="linkType" wire:model.live="linkType" value="goal" class="sr-only" />
                            {{ Lang::get('pots::messages.form.link_goal') }}
                        </label>
                        <label class="flex cursor-pointer items-center gap-1.5 rounded-md border px-3 py-1.5 text-sm {{ $linkType === 'none' ? 'border-slate-900 bg-slate-900 text-white dark:border-slate-100 dark:bg-slate-100 dark:text-slate-900' : 'border-slate-200 bg-white text-slate-600 hover:bg-slate-50 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-400 dark:hover:bg-slate-900' }}">
                            <input type="radio" name="linkType" wire:model.live="linkType" value="none" class="sr-only" />
                            {{ Lang::get('pots::messages.form.link_none') }}
                        </label>
                    </div>
                    {{-- Left hand-rolled on purpose: this select has no label of
                         its own — the "Link to" legend names the whole group it
                         sits in — and x-core::form-field requires one. Giving it a label
                         means a new user-facing string in all 26 locales, which
                         is a copy decision, not a refactor. --}}
                    @if ($linkType === 'goal')
                        <select
                            wire:model="goalId"
                            class="mt-2 block w-full rounded-md border border-slate-200 bg-white px-3 py-2 text-sm text-slate-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 dark:bg-slate-950 dark:border-slate-700 dark:text-slate-100"
                        >
                            <option value="">{{ Lang::get('pots::messages.form.select_goal') }}</option>
                            @foreach ($goalsForPicker as $goal)
                                <option value="{{ $goal->id }}">{{ $goal->name }}</option>
                            @endforeach
                        </select>
                    @endif
                </fieldset>

                {{-- Modal footer --}}
                <div class="flex justify-end gap-2 pt-2">
                    <button
                        type="button"
                        wire:click="cancel"
                        class="rounded-md px-4 py-2 text-sm font-medium text-slate-500 hover:text-slate-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 dark:hover:text-slate-100 dark:text-slate-400"
                    >{{ Lang::get('pots::messages.common.cancel') }}</button>
                    <button
                        type="submit"
                        class="inline-flex items-center rounded-md bg-slate-900 px-4 py-2 text-sm font-medium text-white hover:bg-slate-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 focus-visible:ring-offset-2 dark:bg-slate-100 dark:text-slate-900 dark:hover:bg-white"
                    >{{ $editPotId ? Lang::get('pots::messages.form.save_changes') : Lang::get('pots::messages.form.save_pot') }}</button>
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
            <x-core::section-heading :title="Lang::get('pots::messages.fund.heading', ['name' => $fundPot?->name ?? Lang::get('pots::messages.pot_fallback')])" />
            <form wire:submit="fundPot" class="mt-6 space-y-4">
                <div>
                    <x-core::form-field
                        name="operationAmount"
                        field-id="fund-amount"
                        :label="Lang::get('pots::messages.common.amount')"
                        inputmode="decimal"
                        wire:model="operationAmount"
                        :placeholder="Lang::get('core::components.amount_placeholder')"
                        :aria-invalid="$errorAmount !== '' ? 'true' : null"
                        :aria-describedby="$errorAmount !== '' ? 'fund-amount-error' : null"
                        style="font-variant-numeric: tabular-nums;"
                    />
                    @if ($fundRec !== null)
                        <p class="mt-1 text-xs text-slate-400 dark:text-slate-500" style="font-variant-numeric: tabular-nums;">
                            {{ Lang::get('pots::messages.fund.available', ['amount' => $fmt(max(0, $fundRec->unallocatedMinor), $fundRec->currency)]) }}
                        </p>
                    @endif
                    @if ($errorAmount !== '')
                        <p id="fund-amount-error" class="mt-1 text-sm text-rose-600 dark:text-rose-400">{{ $errorAmount }}</p>
                    @endif
                </div>
                <x-core::form-field
                    name="operationMemo"
                    field-id="fund-memo"
                    :label="Lang::get('pots::messages.common.note_optional')"
                    wire:model="operationMemo"
                    :placeholder="Lang::get('pots::messages.fund.note_placeholder')"
                />
                <div class="flex justify-end gap-2 pt-2">
                    <button type="button" wire:click="$dispatch('modal-close', { name: 'pot-fund' })" class="rounded-md px-4 py-2 text-sm font-medium text-slate-500 hover:text-slate-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 dark:hover:text-slate-100 dark:text-slate-400">{{ Lang::get('pots::messages.common.cancel') }}</button>
                    <button type="submit" class="inline-flex items-center rounded-md bg-slate-900 px-4 py-2 text-sm font-medium text-white hover:bg-slate-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 focus-visible:ring-offset-2 dark:bg-slate-100 dark:text-slate-900 dark:hover:bg-white">{{ Lang::get('pots::messages.fund.submit') }}</button>
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
            <x-core::section-heading :title="Lang::get('pots::messages.move.heading', ['name' => $moveSrcPot?->name ?? Lang::get('pots::messages.pot_fallback')])" />
            <form wire:submit="movePot" class="mt-6 space-y-4">
                <x-core::form-field
                    name="transferTargetPotId"
                    field-id="move-to"
                    type="select"
                    :label="Lang::get('pots::messages.move.to')"
                    wire:model="transferTargetPotId"
                >
                    <option value="">{{ count($moveDestPots) === 0 ? Lang::get('pots::messages.move.no_others') : Lang::get('pots::messages.move.select_pot') }}</option>
                    @foreach ($moveDestPots as $destPot)
                        <option value="{{ $destPot->id }}">{{ $destPot->name }}</option>
                    @endforeach
                </x-core::form-field>
                <div>
                    <x-core::form-field
                        name="operationAmount"
                        field-id="move-amount"
                        :label="Lang::get('pots::messages.common.amount')"
                        inputmode="decimal"
                        wire:model="operationAmount"
                        :placeholder="Lang::get('core::components.amount_placeholder')"
                        :aria-invalid="$errorAmount !== '' ? 'true' : null"
                        :aria-describedby="$errorAmount !== '' ? 'move-amount-error' : null"
                        style="font-variant-numeric: tabular-nums;"
                    />
                    @if ($moveSrcPot !== null)
                        <p class="mt-1 text-xs text-slate-400 dark:text-slate-500" style="font-variant-numeric: tabular-nums;">
                            {{ Lang::get('pots::messages.available_in', ['name' => $moveSrcPot->name, 'amount' => $fmt($moveSrcPot->balanceMinor, $moveSrcPot->currency)]) }}
                        </p>
                    @endif
                    @if ($errorAmount !== '')
                        <p id="move-amount-error" class="mt-1 text-sm text-rose-600 dark:text-rose-400">{{ $errorAmount }}</p>
                    @endif
                </div>
                <x-core::form-field
                    name="operationMemo"
                    field-id="move-memo"
                    :label="Lang::get('pots::messages.common.note_optional')"
                    wire:model="operationMemo"
                    :placeholder="Lang::get('pots::messages.move.note_placeholder')"
                />
                <div class="flex justify-end gap-2 pt-2">
                    <button type="button" wire:click="$dispatch('modal-close', { name: 'pot-move' })" class="rounded-md px-4 py-2 text-sm font-medium text-slate-500 hover:text-slate-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 dark:hover:text-slate-100 dark:text-slate-400">{{ Lang::get('pots::messages.common.cancel') }}</button>
                    <button type="submit" @disabled(count($moveDestPots) === 0) class="inline-flex items-center rounded-md bg-slate-900 px-4 py-2 text-sm font-medium text-white hover:bg-slate-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 focus-visible:ring-offset-2 dark:bg-slate-100 dark:text-slate-900 dark:hover:bg-white disabled:opacity-50 disabled:cursor-not-allowed">{{ Lang::get('pots::messages.move.submit') }}</button>
                </div>
            </form>
        </div>
    </flux:modal>

    {{-- ------------------------------------------------------------------- --}}
    {{-- Withdraw modal (pot-withdraw)                                        --}}
    {{-- ------------------------------------------------------------------- --}}
    <flux:modal name="pot-withdraw" dismissible>
        <div class="pt-[44px]" style="max-width: 480px;">
            <x-core::section-heading :title="Lang::get('pots::messages.withdraw.heading', ['name' => $withdrawPot?->name ?? Lang::get('pots::messages.pot_fallback')])" />
            <form wire:submit="withdrawPot" class="mt-6 space-y-4">
                <div>
                    <x-core::form-field
                        name="operationAmount"
                        field-id="withdraw-amount"
                        :label="Lang::get('pots::messages.common.amount')"
                        inputmode="decimal"
                        wire:model="operationAmount"
                        :placeholder="Lang::get('core::components.amount_placeholder')"
                        :aria-invalid="$errorAmount !== '' ? 'true' : null"
                        :aria-describedby="$errorAmount !== '' ? 'withdraw-amount-error' : null"
                        style="font-variant-numeric: tabular-nums;"
                    />
                    @if ($withdrawPot !== null)
                        <p class="mt-1 text-xs text-slate-400 dark:text-slate-500" style="font-variant-numeric: tabular-nums;">
                            {{ Lang::get('pots::messages.available_in', ['name' => $withdrawPot->name, 'amount' => $fmt($withdrawPot->balanceMinor, $withdrawPot->currency)]) }}
                        </p>
                    @endif
                    @if ($errorAmount !== '')
                        <p id="withdraw-amount-error" class="mt-1 text-sm text-rose-600 dark:text-rose-400">{{ $errorAmount }}</p>
                    @endif
                </div>
                <x-core::form-field
                    name="operationMemo"
                    field-id="withdraw-memo"
                    :label="Lang::get('pots::messages.common.note_optional')"
                    wire:model="operationMemo"
                    :placeholder="Lang::get('pots::messages.withdraw.note_placeholder')"
                />
                <div class="flex justify-end gap-2 pt-2">
                    <button type="button" wire:click="$dispatch('modal-close', { name: 'pot-withdraw' })" class="rounded-md px-4 py-2 text-sm font-medium text-slate-500 hover:text-slate-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 dark:hover:text-slate-100 dark:text-slate-400">{{ Lang::get('pots::messages.common.cancel') }}</button>
                    <button type="submit" class="inline-flex items-center rounded-md bg-slate-900 px-4 py-2 text-sm font-medium text-white hover:bg-slate-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 focus-visible:ring-offset-2 dark:bg-slate-100 dark:text-slate-900 dark:hover:bg-white">{{ Lang::get('pots::messages.actions.withdraw') }}</button>
                </div>
            </form>
        </div>
    </flux:modal>
</div>
