@use('Modules\Core\Public\Navigation\Destination')
@use('Modules\Core\Public\Support\Fmt')
@use('Modules\Core\Public\Support\Lang')
@use('Modules\Pots\Internal\Enums\PotLinkType')
@use('Modules\Pots\Public\Enums\PotMovementKind')
{{--
    /pots page — savings pots grouped by account with per-account reconciliation
    headers (real · allocated · unallocated), negative-unallocated amber
    warning, Flux modals for create/edit/fund/move/withdraw,
    inline movement history expansion per card, archive/restore micro-
    confirm, and an "Archived pots" disclosure.

    Calm-slate direction: emerald goal links, amber negative-unallocated warning,
    rose archive-action confirm. Tabular mono numerics throughout.
    Matches 03-UI-SPEC.md exactly (colors, spacing, copy).
--}}

@php
    use Modules\Ledger\Public\ValueObjects\Money;
    use Modules\Ledger\Public\ValueObjects\MoneyInput;

    $fmt = static fn (int $minor, string $currency): string => Money::ofMinor($minor, $currency)
        ->format();

    // The accounts a pot may be created on. A group whose account is not among
    // them is a pot the server now refuses to add to, so the button is not drawn.
    $allocatableAccountIds = array_map(static fn ($account) => (int) $account->id, $accounts);

    // Derived once for both withdraw surfaces. It used to be derived inside
    // the modal, which put it out of scope for the sheet that renders first.
    $operationPot = null;
    foreach ($groups as $accountPots) {
        foreach ($accountPots as $p) {
            if ($p->id === $operationPotId) {
                $operationPot = $p;
                break 2;
            }
        }
    }

    // Fund, withdraw and move all act on this pot and are read at its own
    // denomination, so the box has to state the scale the writer will use.
    $operationCurrency = $operationPot?->currency;

    // A new pot takes the currency of the account it is opened on, which is
    // the select above the amount; before one is picked there is no scale.
    $newPotCurrency = null;
    foreach ($accounts as $account) {
        if ((string) $account->id === (string) $accountId) {
            $newPotCurrency = (string) $account->default_currency;
            break;
        }
    }
@endphp

{{--
    Phone responsive pass (UI-SPEC §8).

    At <768px:
    - Pots render as .card-list-item rows (pot name .primary, balance .amount, account .secondary)
    - Fund/Move/Create/Edit modals become bottom sheets (x-core::bottom-sheet)
    - Row actions (Fund, Move, Edit) are always visible on phone
    At >=768px: existing card grid + Flux modals unchanged.
--}}
{{-- Inside the single root, not beside it. Livewire binds wire:id to the
     FIRST top-level element, so a <style> tag out here became the whole
     component: every wire:model and wire:click in the markup below was
     orphaned, no /livewire/update request was ever sent, and each write
     silently did nothing while the sheets still opened — those ride
     $dispatch, which is a plain window event and needs no binding. --}}
<div class="mx-auto max-w-3xl px-4 py-6">
    <style>
        @media (min-width: 768px) {
            .pots-phone-list { display: none !important; }
        }
        @media (max-width: 767px) {
            .pots-desktop-list { display: none !important; }
        }
    </style>

    {{-- Page header --}}
    <header class="mb-8 flex flex-wrap items-start justify-between gap-4">
        <div>
            <x-core::page-heading>{{ Lang::get('pots::messages.heading') }}</x-core::page-heading>
            <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">{{ Lang::get('pots::messages.subtitle') }}</p>
        </div>
        @if (count($accounts) > 0)
            <x-core::neutral-button
                class="shrink-0"
                x-on:click="$wire.set('editPotId', 0); if (window.innerWidth < 768) { $dispatch('open-sheet', { name: 'pot-form' }); } else { $flux.modal('pot-form').show(); }"
            >{{ Lang::get('pots::messages.add_pot') }}</x-core::neutral-button>
        @endif
    </header>

    {{-- No pots: empty state. Gated on the pots, not on the accounts: a pot
         created on an account that can no longer hold one still has to be
         reachable, and hiding the whole list left it with no way off the page. --}}
    @if (count($groups) === 0)
        <x-core::empty-state
            :heading="Lang::get('pots::messages.empty.heading')"
            :body="Lang::get('pots::messages.empty.body')"
        >
            @if (count($accounts) > 0)
                <x-core::neutral-button
                    x-on:click="$flux.modal('pot-form').show()"
                    wire:click="$set('editPotId', 0)"
                >{{ Lang::get('pots::messages.empty.cta') }}</x-core::neutral-button>
            @else
                {{-- A pot lives on an account, so with none there is nothing
                     "Add pot" could attach to. The way forward is the wizard
                     that creates the first account. --}}
                <x-core::neutral-button :href="Destination::Imports->url()">{{ Lang::get('pots::messages.empty.no_accounts_cta') }}</x-core::neutral-button>
            @endif
        </x-core::empty-state>
    @else
        {{-- Phone: flat .card-list-item list across all accounts --}}
        <div class="pots-phone-list rounded-lg border border-slate-200 bg-white dark:bg-slate-950 dark:border-slate-700 overflow-hidden">
            @foreach ($groups as $accountId => $pots)
                @php
                    /** @var \Modules\Pots\Public\Dto\PotRow[] $pots */
                    $phoneRecs = $reconciliations[$accountId] ?? [];
                @endphp
                {{-- The three figures the pots have to add up to, one line per
                     currency the account still holds pots in. They were in the
                     desktop group header only, so a phone showed envelopes with
                     nothing to weigh them against. --}}
                @foreach ($phoneRecs as $phoneRec)
                    <div class="border-b border-slate-200 px-4 py-3 dark:border-slate-700">
                        <p class="text-sm font-semibold text-slate-900 dark:text-slate-100">{{ $pots[0]->accountName }}</p>
                        <p class="mt-1 text-xs text-slate-500 dark:text-slate-400" style="font-variant-numeric: tabular-nums;">
                            {{ Lang::get('pots::messages.recon.real_balance') }} {{ $fmt($phoneRec->realBalanceMinor, $phoneRec->currency) }}
                            <span aria-hidden="true"> · </span>
                            {{ Lang::get('pots::messages.recon.allocated') }} {{ $fmt($phoneRec->allocatedMinor, $phoneRec->currency) }}
                            <span aria-hidden="true"> · </span>
                            {{ Lang::get('pots::messages.recon.unallocated') }} <span class="{{ $phoneRec->isOverAllocated ? 'font-medium text-amber-600 dark:text-amber-400' : '' }}">{{ $fmt($phoneRec->unallocatedMinor, $phoneRec->currency) }}</span>
                        </p>
                        @if ($phoneRec->isOverAllocated)
                            <p class="mt-2 text-xs font-medium text-amber-600 dark:text-amber-400" role="alert">
                                {{ Lang::get('pots::messages.recon.over_allocated', ['amount' => $fmt(abs($phoneRec->unallocatedMinor), $phoneRec->currency)]) }}
                            </p>
                        @endif
                        @if ($phoneRec->isPartial())
                            <p class="mt-1 text-xs text-slate-500 dark:text-slate-400" data-not-converted="true">{{ Lang::get('core::money.not_converted', ['list' => $phoneRec->unconvertedList()]) }}</p>
                        @endif
                    </div>
                @endforeach
                @foreach ($pots as $pot)
                    <div class="card-list-item flex-wrap">
                        <div class="flex-1 min-w-0">
                            <p class="primary truncate">{{ $pot->name }}</p>
                            <p class="secondary">{{ $pot->accountName }}</p>
                        </div>
                        <span class="amount">{{ $fmt($pot->balanceMinor, $pot->currency) }}</span>
                        {{-- Actions on their own line. Five 44px targets and
                             the amount left the name 6px wide on a 375pt
                             screen, so the row said which pot it was not.

                             flex-wrap because the captions are as long as the
                             language makes them: measured at 375px the German
                             set needs 333px against 309px of row, and the list
                             around it is overflow-hidden, so without the wrap
                             Archivieren is clipped away rather than scrolled
                             to. It takes a second line and every target stays
                             reachable. --}}
                        {{-- The strip the archive button arms. It existed only
                             in the desktop list, which this breakpoint hides, so
                             the phone's 🗄️ set the id and then nothing appeared
                             — the control read as dead because its answer was
                             rendered somewhere unreachable. Goals carries the
                             same pair; the guard below keeps them in step. --}}
                        @if ($archivingPotId === $pot->id)
                            <x-core::confirm-strip
                                class="mt-3 w-full"
                                :question="Lang::get('pots::messages.archive_confirm', ['amount' => $fmt($pot->balanceMinor, $pot->currency)])"
                                :cancel-label="Lang::get('pots::messages.common.cancel')"
                                :confirm-label="Lang::get('pots::messages.actions.archive')"
                                :confirm-aria="Lang::get('pots::messages.confirm_archive_aria', ['name' => $pot->name])"
                                cancel="cancelArchive"
                                :confirm="'archivePot('.$pot->id.')'"
                            />
                        @else
                        <div class="flex w-full flex-wrap items-center justify-end gap-1">
                        {{-- Row actions always visible on phone --}}
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
                        @endif
                    </div>
                @endforeach
            @endforeach
        </div>

        {{-- Desktop: Account groups: grouped by account, ordered by account name --}}
        <div class="pots-desktop-list space-y-8">
            @foreach ($groups as $accountId => $pots)
                @php
                    /** @var \Modules\Pots\Public\Dto\PotRow[] $pots */
                    $recs = $reconciliations[$accountId] ?? [];
                    $firstPot = $pots[0];
                @endphp

                <div>
                    {{-- Account group header row --}}
                    <div class="flex items-center justify-between gap-4 mb-2">
                        <x-core::section-heading :title="$firstPot->accountName" />
                        @if (in_array((int) $accountId, $allocatableAccountIds, true))
                            <x-core::secondary-button
                                size="sm"
                                x-on:click="$wire.set('accountId', '{{ $accountId }}'); $wire.set('editPotId', 0); if (window.innerWidth < 768) { $dispatch('open-sheet', { name: 'pot-form' }); } else { $flux.modal('pot-form').show(); }"
                            >{{ Lang::get('pots::messages.add_pot') }}</x-core::secondary-button>
                        @endif
                    </div>

                    {{-- One banner and one line per currency: the account's own
                         denomination first, then every other currency it still
                         holds pots in. --}}
                    @foreach ($recs as $rec)
                    @if ($rec->isOverAllocated)
                        <x-core::alert tone="warning" class="mb-2" role="alert">
                            <span class="mr-1" aria-hidden="true">
                                <flux:icon.exclamation-triangle class="inline-block h-4 w-4 align-text-bottom" />
                            </span>
                            {{ Lang::get('pots::messages.recon.over_allocated', ['amount' => $fmt(abs($rec->unallocatedMinor), $rec->currency)]) }}
                        </x-core::alert>
                    @endif

                        <p class="mb-4 text-xs text-slate-500 dark:text-slate-400" style="font-family: var(--font-mono, ui-monospace, monospace); font-variant-numeric: tabular-nums;">
                            {{ Lang::get('pots::messages.recon.real_balance') }} {{ $fmt($rec->realBalanceMinor, $rec->currency) }}
                            <span aria-hidden="true"> · </span>
                            {{ Lang::get('pots::messages.recon.allocated') }} {{ $fmt($rec->allocatedMinor, $rec->currency) }}
                            <span aria-hidden="true"> · </span>
                            {{ Lang::get('pots::messages.recon.unallocated') }} <span class="{{ $rec->isOverAllocated ? 'font-medium text-amber-600 dark:text-amber-400' : '' }}">{{ $fmt($rec->unallocatedMinor, $rec->currency) }}</span>
                        </p>
                        {{-- A currency the account holds that no line above
                             answers for is named, the way every other money
                             surface names what it could not price. --}}
                        @if ($rec->isPartial())
                            <p class="mb-4 -mt-2 text-xs text-slate-500 dark:text-slate-400" data-not-converted="true">{{ Lang::get('core::money.not_converted', ['list' => $rec->unconvertedList()]) }}</p>
                        @endif
                    @endforeach

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

                                {{-- Coverage insight: category-linked only --}}
                                @if ($pot->categoryId !== null && $pot->categorySpentMinor !== null)
                                    <p class="mt-0.5 text-xs text-slate-500 dark:text-slate-400" style="font-family: var(--font-mono, ui-monospace, monospace); font-variant-numeric: tabular-nums;">
                                        {{ $pot->categoryName }}: {{ $fmt($pot->categorySpentMinor, $pot->currency) }} {{ Lang::get('pots::messages.coverage.spent') }} · {{ $fmt($pot->balanceMinor, $pot->currency) }} {{ Lang::get('pots::messages.coverage.in_pot') }}
                                    </p>
                                    @if ($pot->categorySpentIsPartial())
                                        <p class="mt-0.5 text-xs text-slate-500 dark:text-slate-400" data-not-converted="true">{{ Lang::get('core::money.not_converted', ['list' => $pot->categorySpentUnconvertedList()]) }}</p>
                                    @endif
                                @endif

                                {{-- Archive micro-confirm or footer action row --}}
                                @if ($archivingPotId === $pot->id)
                                    <x-core::confirm-strip
                                        class="mt-3"
                                        :question="Lang::get('pots::messages.archive_confirm', ['amount' => $fmt($pot->balanceMinor, $pot->currency)])"
                                        :cancel-label="Lang::get('pots::messages.common.cancel')"
                                        :confirm-label="Lang::get('pots::messages.actions.archive')"
                                        :confirm-aria="Lang::get('pots::messages.confirm_archive_aria', ['name' => $pot->name])"
                                        cancel="cancelArchive"
                                        :confirm="'archivePot('.$pot->id.')'"
                                    />
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
                                            class="text-sm text-slate-600 hover:text-slate-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 min-w-[44px] min-h-[44px] flex items-center justify-center dark:hover:text-slate-100 dark:text-slate-400"
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
                                            class="text-sm text-slate-600 hover:text-slate-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 min-w-[44px] min-h-[44px] flex items-center justify-center dark:hover:text-slate-100 dark:text-slate-400"
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

                                {{-- Inline movement history — Alpine x-show / x-collapse --}}
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
                                            class="text-xs text-slate-600 hover:text-slate-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 dark:hover:text-slate-300 dark:text-slate-400"
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
                                                        $isIncoming = $movement->kind?->isIncoming() ?? false;
                                                        // A kind this build has no case for is named as such rather
                                                        // than folded into one of the four: the wording and the '+'
                                                        // both claim a direction only the newer version knows.
                                                        $label = match ($movement->kind) {
                                                            PotMovementKind::Fund => Lang::get('pots::messages.movement.fund'),
                                                            PotMovementKind::Withdraw => Lang::get('pots::messages.movement.withdraw'),
                                                            PotMovementKind::TransferIn => Lang::get('pots::messages.movement.moved_from', ['name' => $movement->counterpartPotName ?? Lang::get('pots::messages.pot_fallback')]),
                                                            PotMovementKind::TransferOut => Lang::get('pots::messages.movement.moved_to', ['name' => $movement->counterpartPotName ?? Lang::get('pots::messages.pot_fallback')]),
                                                            PotMovementKind::ReleasedOnArchive => Lang::get('pots::messages.movement.released_on_archive'),
                                                            null => Lang::get('pots::messages.movement.unreadable'),
                                                        };
                                                    @endphp
                                                    <li class="flex items-center justify-between gap-4 py-2 px-0 text-sm">
                                                        <div class="min-w-0">
                                                            <span class="text-xs text-slate-600 dark:text-slate-400 tabular-nums">{{ substr($movement->createdAt, 0, 10) }}</span>
                                                            <span class="ml-2 text-sm text-slate-500 dark:text-slate-400">{{ $label }}</span>
                                                            @if ($movement->memo !== null)
                                                                <span class="ml-1 text-xs italic text-slate-600 dark:text-slate-400">{{ $movement->memo }}</span>
                                                            @endif
                                                        </div>
                                                        <span
                                                            class="shrink-0 text-sm tabular-nums {{ $isIncoming ? 'text-emerald-700 dark:text-emerald-400' : 'text-slate-500 dark:text-slate-400' }}"
                                                            style="font-family: var(--font-mono, ui-monospace, monospace); font-variant-numeric: tabular-nums;"
                                                        >
                                                            {{ $movement->kind === null ? '' : ($isIncoming ? '+' : '') }}{{ $fmt($movement->amountMinor, $movement->currency) }}
                                                        </span>
                                                    </li>
                                                @endforeach
                                            </ul>
                                            @if ($pot->hasOlderMovements())
                                                <p class="pb-2 text-xs text-slate-500 dark:text-slate-400" style="font-variant-numeric: tabular-nums;">
                                                    {{ Lang::get('pots::messages.history.truncated', ['shown' => Fmt::number(count($pot->recentMovements)), 'count' => Fmt::number($pot->movementCount)]) }}
                                                </p>
                                            @endif
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
                <span>{{ Lang::choice('pots::messages.archived.toggle', count($archived)) }}</span>
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
    {{-- Phone bottom sheet: Create / Edit pot                                --}}
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
                    wire:model.live.blur="name"
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
                wire:model.live.blur="accountId"
                :disabled="$editPotId !== 0"
                :class="$editPotId ? 'opacity-50 cursor-not-allowed' : ''"
                style="font-size: 16px;"
            >
                <option value="">{{ Lang::get('pots::messages.form.select_account') }}</option>
                @foreach ($accounts as $account)
                    <option value="{{ $account->id }}">{{ $account->name }}</option>
                @endforeach
            </x-core::form-field>
            @if (! $editPotId)
                <div>
                    <x-core::form-field
                        name="amount"
                        field-id="pot-amount-sheet"
                        :label="Lang::get('pots::messages.form.initial_amount')"
                        :hint="Lang::get('pots::messages.form.initial_amount_help')"
                        size="base"
                        inputmode="{{ MoneyInput::decimalPlaces($newPotCurrency) === 0 ? 'numeric' : 'decimal' }}"
                        wire:model.live.blur="amount"
                        :placeholder="MoneyInput::formatAbsMinor(0, $newPotCurrency)"
                        :aria-invalid="$errorAmount !== '' ? 'true' : null"
                        :aria-describedby="$errorAmount !== '' ? 'pot-amount-sheet-error' : null"
                        style="font-size: 16px; font-variant-numeric: tabular-nums;"
                    />
                    @if ($errorAmount !== '')
                        <p id="pot-amount-sheet-error" class="mt-1 text-sm text-rose-600 dark:text-rose-400">{{ $errorAmount }}</p>
                    @endif
                </div>
            @endif
            <fieldset>
                <legend class="mb-1 block text-xs font-medium uppercase tracking-wide text-slate-500 dark:text-slate-400">{{ Lang::get('pots::messages.form.link_to') }}</legend>
                <div class="flex gap-2">
                    <label class="flex cursor-pointer items-center gap-1.5 rounded-md border px-3 py-2 text-sm {{ $linkType === PotLinkType::Goal->value ? 'border-slate-900 bg-slate-900 text-white dark:border-slate-100 dark:bg-slate-100 dark:text-slate-900' : 'border-slate-200 bg-white text-slate-600 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-400' }}">
                        <input type="radio" name="linkTypeSheet" wire:model.live="linkType" value="{{ PotLinkType::Goal->value }}" class="sr-only" />
                        {{ Lang::get('pots::messages.form.link_goal') }}
                    </label>
                    <label class="flex cursor-pointer items-center gap-1.5 rounded-md border px-3 py-2 text-sm {{ $linkType === PotLinkType::None->value ? 'border-slate-900 bg-slate-900 text-white dark:border-slate-100 dark:bg-slate-100 dark:text-slate-900' : 'border-slate-200 bg-white text-slate-600 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-400' }}">
                        <input type="radio" name="linkTypeSheet" wire:model.live="linkType" value="{{ PotLinkType::None->value }}" class="sr-only" />
                        {{ Lang::get('pots::messages.form.link_none') }}
                    </label>
                </div>
                {{-- A legend names the FIELDSET, not the controls inside it, so
                     this select reached a screen reader with no name at all. The
                     label is its own placeholder option, which every locale
                     already carries, and sr-only because the legend above it
                     already says this on screen. --}}
                @if ($linkType === PotLinkType::Goal->value)
                    <label for="pot-goal-picker-sheet" class="sr-only">{{ Lang::get('pots::messages.form.select_goal') }}</label>
                    <select
                        id="pot-goal-picker-sheet"
                        wire:model="goalId"
                        class="mt-2 block w-full rounded-md border border-slate-200 bg-white px-3 py-2 text-slate-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 dark:bg-slate-950 dark:border-slate-700 dark:text-slate-100"
                        style="font-size: 16px;"
                    >
                        <option value="">{{ Lang::get('pots::messages.form.select_goal') }}</option>
                        @foreach ($goalsForPicker as $goal)
                            <option value="{{ $goal->id }}">{{ $goal->name }}</option>
                        @endforeach
                    </select>
                @endif
            </fieldset>
            <div class="flex gap-3 pt-2">
                <x-core::neutral-button
                    block="flex"
                    type="submit"
                >{{ $editPotId ? Lang::get('pots::messages.form.save_changes') : Lang::get('pots::messages.form.save_pot') }}</x-core::neutral-button>
                {{-- Closes on the client too: the sheet's open flag is
                     Alpine's, so wire:click alone cleared the form and left
                     the panel on screen. Same defect as the goals sheet. --}}
                <x-core::secondary-button
                    x-on:click="open = false"
                    wire:click="cancel"
                >{{ Lang::get('pots::messages.common.cancel') }}</x-core::secondary-button>
            </div>
        </form>
    </x-core::bottom-sheet>

    {{-- ------------------------------------------------------------------- --}}
    {{-- Phone bottom sheet: Fund pot                                         --}}
    {{-- ------------------------------------------------------------------- --}}
    <x-core::bottom-sheet name="pot-fund" title="{{ Lang::get('pots::messages.fund.title') }}">
        <form wire:submit="fundPot" class="space-y-4">
            <div>
                <x-core::form-field
                    name="operationAmount"
                    field-id="fund-amount-sheet"
                    :label="Lang::get('pots::messages.common.amount')"
                    size="base"
                    inputmode="{{ MoneyInput::decimalPlaces($operationCurrency) === 0 ? 'numeric' : 'decimal' }}"
                    wire:model.live.blur="operationAmount"
                    :placeholder="MoneyInput::formatAbsMinor(0, $operationCurrency)"
                    :aria-invalid="$errorAmount !== '' ? 'true' : null"
                    :aria-describedby="$errorAmount !== '' ? 'fund-amount-sheet-error' : null"
                    style="font-size: 16px; font-variant-numeric: tabular-nums;"
                />
                @if ($errorAmount !== '')
                    <p id="fund-amount-sheet-error" class="mt-1 text-sm text-rose-600 dark:text-rose-400">{{ $errorAmount }}</p>
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
                <x-core::neutral-button
                    block="flex"
                    type="submit"
                >{{ Lang::get('pots::messages.fund.submit') }}</x-core::neutral-button>
                <x-core::secondary-button wire:click="$dispatch('modal-close', { name: 'pot-fund' })">{{ Lang::get('pots::messages.common.cancel') }}</x-core::secondary-button>
            </div>
        </form>
    </x-core::bottom-sheet>

    {{-- ------------------------------------------------------------------- --}}
    {{-- Phone bottom sheet: Move pot                                         --}}
    {{-- ------------------------------------------------------------------- --}}
    {{-- Withdraw sheet. The phone row's ↑ dispatches open-sheet for this
         name; without a sheet listening, the only thing that answered was a
         flux:modal the phone never opens, so Withdraw did nothing at all. --}}
    <x-core::bottom-sheet name="pot-withdraw" title="{{ Lang::get('pots::messages.withdraw.heading', ['name' => $operationPot?->name ?? Lang::get('pots::messages.pot_fallback')]) }}">
        <form wire:submit="withdrawPot" class="space-y-4">
            <div>
                <x-core::form-field
                    name="operationAmount"
                    field-id="withdraw-amount-sheet"
                    :label="Lang::get('pots::messages.common.amount')"
                    size="base"
                    inputmode="{{ MoneyInput::decimalPlaces($operationCurrency) === 0 ? 'numeric' : 'decimal' }}"
                    wire:model.live.blur="operationAmount"
                    :placeholder="MoneyInput::formatAbsMinor(0, $operationCurrency)"
                    :aria-invalid="$errorAmount !== '' ? 'true' : null"
                    :aria-describedby="$errorAmount !== '' ? 'withdraw-amount-sheet-error' : null"
                    style="font-size: 16px; font-variant-numeric: tabular-nums;"
                />
                @if ($errorAmount !== '')
                    <p id="withdraw-amount-sheet-error" class="mt-1 text-sm text-rose-600 dark:text-rose-400">{{ $errorAmount }}</p>
                @endif
                @if ($operationPot !== null)
                    <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">
                        {{ Lang::get('pots::messages.available_in', ['name' => $operationPot->name, 'amount' => $fmt($operationPot->balanceMinor, $operationPot->currency)]) }}
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
                <x-core::neutral-button
                    block="flex"
                    type="submit"
                >{{ Lang::get('pots::messages.actions.withdraw') }}</x-core::neutral-button>
                <x-core::secondary-button wire:click="$dispatch('modal-close', { name: 'pot-withdraw' })">{{ Lang::get('pots::messages.common.cancel') }}</x-core::secondary-button>
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
            <div>
                <x-core::form-field
                    name="transferTargetPotId"
                    field-id="move-to-sheet"
                    type="select"
                    :label="Lang::get('pots::messages.move.to')"
                    size="base"
                    wire:model.live="transferTargetPotId"
                    :aria-invalid="$errorTarget !== '' ? 'true' : null"
                    :aria-describedby="$errorTarget !== '' ? 'move-to-sheet-error' : null"
                    style="font-size: 16px;"
                >
                    <option value="">{{ count($moveDestPotsSheet) === 0 ? Lang::get('pots::messages.move.no_others_short') : Lang::get('pots::messages.move.select_pot') }}</option>
                    @foreach ($moveDestPotsSheet as $destPot)
                        <option value="{{ $destPot->id }}">{{ $destPot->name }}</option>
                    @endforeach
                </x-core::form-field>
                @if ($errorTarget !== '')
                    <p id="move-to-sheet-error" class="mt-1 text-sm text-rose-600 dark:text-rose-400">{{ $errorTarget }}</p>
                @endif
            </div>
            <div>
                <x-core::form-field
                    name="operationAmount"
                    field-id="move-amount-sheet"
                    :label="Lang::get('pots::messages.common.amount')"
                    size="base"
                    inputmode="{{ MoneyInput::decimalPlaces($operationCurrency) === 0 ? 'numeric' : 'decimal' }}"
                    wire:model.live.blur="operationAmount"
                    :placeholder="MoneyInput::formatAbsMinor(0, $operationCurrency)"
                    :aria-invalid="$errorAmount !== '' ? 'true' : null"
                    :aria-describedby="$errorAmount !== '' ? 'move-amount-sheet-error' : null"
                    style="font-size: 16px; font-variant-numeric: tabular-nums;"
                />
                @if ($errorAmount !== '')
                    <p id="move-amount-sheet-error" class="mt-1 text-sm text-rose-600 dark:text-rose-400">{{ $errorAmount }}</p>
                @endif
            </div>
            <div class="flex gap-3 pt-2">
                <x-core::neutral-button
                    block="flex"
                    class="disabled:opacity-50"
                    :disabled="count($moveDestPotsSheet) === 0"
                    type="submit"
                >{{ Lang::get('pots::messages.move.submit') }}</x-core::neutral-button>
                <x-core::secondary-button wire:click="$dispatch('modal-close', { name: 'pot-move' })">{{ Lang::get('pots::messages.common.cancel') }}</x-core::secondary-button>
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
                        wire:model.live.blur="name"
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
                    wire:model.live.blur="accountId"
                    :disabled="$editPotId !== 0"
                    :class="$editPotId ? 'opacity-50 cursor-not-allowed' : ''"
                >
                    <option value="">{{ Lang::get('pots::messages.form.select_account') }}</option>
                    @foreach ($accounts as $account)
                        <option value="{{ $account->id }}">{{ $account->name }}</option>
                    @endforeach
                </x-core::form-field>

                {{-- Initial amount (create only) --}}
                @if (! $editPotId)
                    <div>
                        <x-core::form-field
                            name="amount"
                            field-id="pot-amount"
                            :label="Lang::get('pots::messages.form.initial_amount')"
                            :hint="Lang::get('pots::messages.form.initial_amount_help')"
                            inputmode="{{ MoneyInput::decimalPlaces($newPotCurrency) === 0 ? 'numeric' : 'decimal' }}"
                            wire:model.live.blur="amount"
                            :placeholder="MoneyInput::formatAbsMinor(0, $newPotCurrency)"
                            :aria-invalid="$errorAmount !== '' ? 'true' : null"
                            :aria-describedby="$errorAmount !== '' ? 'pot-amount-error' : null"
                            style="font-variant-numeric: tabular-nums;"
                        />
                        @if ($errorAmount !== '')
                            <p id="pot-amount-error" class="mt-1 text-sm text-rose-600 dark:text-rose-400">{{ $errorAmount }}</p>
                        @endif
                    </div>
                @endif

                {{-- Link to: Goal | None — category-linking is retired --}}
                {{-- "Link to" was a <label> with no `for` and nothing inside it,
                     which labels exactly nothing. wire:model emits no name=, so
                     the two radios were not one group to the browser either. --}}
                <fieldset>
                    <legend class="mb-1 block text-xs font-medium uppercase tracking-wide text-slate-500 dark:text-slate-400">{{ Lang::get('pots::messages.form.link_to') }}</legend>
                    <div class="flex gap-2">
                        <label class="flex cursor-pointer items-center gap-1.5 rounded-md border px-3 py-1.5 text-sm {{ $linkType === PotLinkType::Goal->value ? 'border-slate-900 bg-slate-900 text-white dark:border-slate-100 dark:bg-slate-100 dark:text-slate-900' : 'border-slate-200 bg-white text-slate-600 hover:bg-slate-50 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-400 dark:hover:bg-slate-900' }}">
                            <input type="radio" name="linkType" wire:model.live="linkType" value="{{ PotLinkType::Goal->value }}" class="sr-only" />
                            {{ Lang::get('pots::messages.form.link_goal') }}
                        </label>
                        <label class="flex cursor-pointer items-center gap-1.5 rounded-md border px-3 py-1.5 text-sm {{ $linkType === PotLinkType::None->value ? 'border-slate-900 bg-slate-900 text-white dark:border-slate-100 dark:bg-slate-100 dark:text-slate-900' : 'border-slate-200 bg-white text-slate-600 hover:bg-slate-50 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-400 dark:hover:bg-slate-900' }}">
                            <input type="radio" name="linkType" wire:model.live="linkType" value="{{ PotLinkType::None->value }}" class="sr-only" />
                            {{ Lang::get('pots::messages.form.link_none') }}
                        </label>
                    </div>
                    {{-- Hand-rolled rather than x-core::form-field, which would
                         demand a visible label and a new string in 26 locales.
                         The accessible name is the placeholder option every
                         locale already carries; the legend names the group, and
                         a group name is not a control name. --}}
                    @if ($linkType === PotLinkType::Goal->value)
                        <label for="pot-goal-picker-modal" class="sr-only">{{ Lang::get('pots::messages.form.select_goal') }}</label>
                        <select
                            id="pot-goal-picker-modal"
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
                    <x-core::neutral-button type="submit">{{ $editPotId ? Lang::get('pots::messages.form.save_changes') : Lang::get('pots::messages.form.save_pot') }}</x-core::neutral-button>
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
                $fundRec = null;
                foreach ($fundPot === null ? [] : ($reconciliations[$fundPot->accountId] ?? []) as $candidate) {
                    if ($candidate->currency === $fundPot->currency) {
                        $fundRec = $candidate;
                        break;
                    }
                }
            @endphp
            <x-core::section-heading :title="Lang::get('pots::messages.fund.heading', ['name' => $fundPot?->name ?? Lang::get('pots::messages.pot_fallback')])" />
            <form wire:submit="fundPot" class="mt-6 space-y-4">
                <div>
                    <x-core::form-field
                        name="operationAmount"
                        field-id="fund-amount"
                        :label="Lang::get('pots::messages.common.amount')"
                        inputmode="{{ MoneyInput::decimalPlaces($operationCurrency) === 0 ? 'numeric' : 'decimal' }}"
                        wire:model.live.blur="operationAmount"
                        :placeholder="MoneyInput::formatAbsMinor(0, $operationCurrency)"
                        :aria-invalid="$errorAmount !== '' ? 'true' : null"
                        :aria-describedby="$errorAmount !== '' ? 'fund-amount-error' : null"
                        style="font-variant-numeric: tabular-nums;"
                    />
                    @if ($fundRec !== null)
                        <p class="mt-1 text-xs text-slate-600 dark:text-slate-400" style="font-variant-numeric: tabular-nums;">
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
                    <x-core::neutral-button type="submit">{{ Lang::get('pots::messages.fund.submit') }}</x-core::neutral-button>
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
                <div>
                    <x-core::form-field
                        name="transferTargetPotId"
                        field-id="move-to"
                        type="select"
                        :label="Lang::get('pots::messages.move.to')"
                        wire:model.live="transferTargetPotId"
                        :aria-invalid="$errorTarget !== '' ? 'true' : null"
                        :aria-describedby="$errorTarget !== '' ? 'move-to-error' : null"
                    >
                        <option value="">{{ count($moveDestPots) === 0 ? Lang::get('pots::messages.move.no_others') : Lang::get('pots::messages.move.select_pot') }}</option>
                        @foreach ($moveDestPots as $destPot)
                            <option value="{{ $destPot->id }}">{{ $destPot->name }}</option>
                        @endforeach
                    </x-core::form-field>
                    @if ($errorTarget !== '')
                        <p id="move-to-error" class="mt-1 text-sm text-rose-600 dark:text-rose-400">{{ $errorTarget }}</p>
                    @endif
                </div>
                <div>
                    <x-core::form-field
                        name="operationAmount"
                        field-id="move-amount"
                        :label="Lang::get('pots::messages.common.amount')"
                        inputmode="{{ MoneyInput::decimalPlaces($operationCurrency) === 0 ? 'numeric' : 'decimal' }}"
                        wire:model.live.blur="operationAmount"
                        :placeholder="MoneyInput::formatAbsMinor(0, $operationCurrency)"
                        :aria-invalid="$errorAmount !== '' ? 'true' : null"
                        :aria-describedby="$errorAmount !== '' ? 'move-amount-error' : null"
                        style="font-variant-numeric: tabular-nums;"
                    />
                    @if ($moveSrcPot !== null)
                        <p class="mt-1 text-xs text-slate-600 dark:text-slate-400" style="font-variant-numeric: tabular-nums;">
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
                    <x-core::neutral-button
                        class="disabled:opacity-50 disabled:cursor-not-allowed"
                        :disabled="count($moveDestPots) === 0"
                        type="submit"
                    >{{ Lang::get('pots::messages.move.submit') }}</x-core::neutral-button>
                </div>
            </form>
        </div>
    </flux:modal>

    {{-- ------------------------------------------------------------------- --}}
    {{-- Withdraw modal (pot-withdraw)                                        --}}
    {{-- ------------------------------------------------------------------- --}}
    <flux:modal name="pot-withdraw" dismissible>
        <div class="pt-[44px]" style="max-width: 480px;">
            <x-core::section-heading :title="Lang::get('pots::messages.withdraw.heading', ['name' => $operationPot?->name ?? Lang::get('pots::messages.pot_fallback')])" />
            <form wire:submit="withdrawPot" class="mt-6 space-y-4">
                <div>
                    <x-core::form-field
                        name="operationAmount"
                        field-id="withdraw-amount"
                        :label="Lang::get('pots::messages.common.amount')"
                        inputmode="{{ MoneyInput::decimalPlaces($operationCurrency) === 0 ? 'numeric' : 'decimal' }}"
                        wire:model.live.blur="operationAmount"
                        :placeholder="MoneyInput::formatAbsMinor(0, $operationCurrency)"
                        :aria-invalid="$errorAmount !== '' ? 'true' : null"
                        :aria-describedby="$errorAmount !== '' ? 'withdraw-amount-error' : null"
                        style="font-variant-numeric: tabular-nums;"
                    />
                    @if ($operationPot !== null)
                        <p class="mt-1 text-xs text-slate-600 dark:text-slate-400" style="font-variant-numeric: tabular-nums;">
                            {{ Lang::get('pots::messages.available_in', ['name' => $operationPot->name, 'amount' => $fmt($operationPot->balanceMinor, $operationPot->currency)]) }}
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
                    <x-core::neutral-button type="submit">{{ Lang::get('pots::messages.actions.withdraw') }}</x-core::neutral-button>
                </div>
            </form>
        </div>
    </flux:modal>
</div>
