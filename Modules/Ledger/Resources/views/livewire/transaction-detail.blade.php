@use('Modules\Core\Public\Navigation\Destination')
@use('Modules\Core\Public\Support\Lang')
@use('Modules\Ledger\Public\Enums\ClearedStatus')
@use('Modules\Sync\Public\Transport\SensitiveTextBudget')
@php
    use Modules\Core\Public\Support\SafeDate;
    use Modules\Ledger\Public\ValueObjects\Money;
    use Modules\Ledger\Public\ValueObjects\MoneyInput;
    use Modules\Ledger\Public\ValueObjects\Rate;

    $fmt = static fn (Money $money): string => $money->format();

    // Rate, not number_format(): a float cast would silently corrupt the
    // persisted decimal(18,8) precision, which is the whole reason the
    // column is read as a string.
    $fxRate = $transaction->fx_rate_used === null ? null : Rate::of($transaction->fx_rate_used);
    $fxRateDisplay = $fxRate?->forDisplay();

    // booked_at is a DATETIME and posted_at a DATE, so the two are compared as
    // days: a same-day pair differs by the time-of-day the importer bolts on to
    // keep fingerprints apart, and that is not a second date to show.
    $postedDay = SafeDate::normalisedDayOrNull((string) $transaction->posted_at);
    $bookedDay = SafeDate::normalisedDayOrNull((string) $transaction->booked_at);
@endphp

<div>
    {{-- Tax tag picker — rendered once per page (not per row). --}}
    @include('tax::components.tax-tag-popover')

    {{-- Mobile top bar: back affordance targeting /transactions parent list.
         Visible only at <1024px (CSS .top-bar rule sets display:none at >=1024px).
         The page title is "Transaction" + the posted date for context. --}}
    <x-core::mobile-top-bar
        :backUrl="Destination::Transactions->url()"
        :title="Lang::get('ledger::detail.heading')"
    />

    {{-- A div, not a <main>: layouts.app already opened one, and a main
         inside a main is a nesting the HTML spec does not allow. The gutter
         stays this page's own -- page-shell's px-8 at phone width breaks
         the "Transaction" heading across two lines mid-word. --}}
    <div class="min-h-screen bg-white dark:bg-slate-950">
        <div class="mx-auto max-w-3xl px-4 py-6 space-y-6 sm:px-8" data-testid="transaction-detail">
            <header class="space-y-1">
                <div class="flex items-center gap-3">
                    <x-core::page-heading>{{ Lang::get('ledger::detail.heading') }}</x-core::page-heading>
                    {{-- Cleared/uncleared/reconciled badge + toggle. --}}
                    <x-ledger::cleared-badge :transaction="['id' => $transaction->id, 'status' => $clearedStatus ?? \Modules\Ledger\Public\Enums\ClearedStatus::Cleared->value]" />
                    {{-- The reclassify control below offers to override the
                         detected type and drops it from its own option list,
                         so without this the page never says what it is. --}}
                    <x-core::status-pill data-testid="tx-detail-type">{{ Lang::get('ledger::detail.type_label.'.$transaction->type) }}</x-core::status-pill>
                </div>
                <p class="text-sm text-slate-500 dark:text-slate-400" data-testid="tx-detail-posted-at">
                    {{ $postedDay?->translatedFormat('j M Y') }}
                </p>
                {{-- A card statement carries two dates and they are a day apart:
                     the day it was spent and the day the issuer booked it. Only
                     the second one is drawn, and only when it is a different
                     day, because every other source writes the two equal and a
                     repeated date on every row is noise. --}}
                @if ($bookedDay !== null && $postedDay !== null && ! $bookedDay->isSameDay($postedDay))
                    <p class="text-xs text-slate-500 dark:text-slate-400" data-testid="tx-detail-booked-at">
                        {{ Lang::get('ledger::detail.booked_on', ['date' => $bookedDay->translatedFormat('j M Y')]) }}
                    </p>
                @endif
            </header>

            {{-- Where the reader looks after a reconciled-lock toast: beside
                 the badge that announced the lock, and drawn only while it
                 holds. The row keeps every field it had, so the question the
                 strip asks names the effect rather than a loss. --}}
            @if ($clearedStatus === ClearedStatus::Reconciled->value)
                <section
                    aria-labelledby="unreconcile-heading"
                    class="space-y-3"
                    data-testid="unreconcile-section"
                >
                    <x-core::alert>
                        <h2 id="unreconcile-heading" class="font-medium">
                            {{ Lang::get('ledger::detail.unreconcile.heading') }}
                        </h2>
                        <p class="mt-1">{{ Lang::get('ledger::detail.unreconcile.help') }}</p>
                    </x-core::alert>

                    @if ($confirmingUnreconcile)
                        <x-core::confirm-strip
                            :question="Lang::get('ledger::detail.unreconcile.confirm_question')"
                            :cancel-label="Lang::get('ledger::detail.unreconcile.cancel')"
                            :confirm-label="Lang::get('ledger::detail.unreconcile.button')"
                            cancel="cancelUnreconcile"
                            confirm="unreconcile"
                            data-testid="unreconcile-confirm"
                        />
                    @else
                        <x-core::secondary-button
                            size="sm"
                            class="shadow-sm"
                            wire:click="startUnreconcile"
                            data-testid="unreconcile-button"
                        >
                            {{ Lang::get('ledger::detail.unreconcile.button') }}
                        </x-core::secondary-button>
                    @endif
                </section>
            @endif

            {{-- The bank's own narrative for the line, and the string the
                 counterparty above was RESOLVED from. Search matches on it and
                 the list renders a snippet of it, so a reader could find a
                 transaction by words this page then refused to show. Full
                 width and above the grid: it is free text and wraps. --}}
            @if (($transaction->description ?? '') !== '')
                <dl class="space-y-1">
                    <dt class="text-sm text-slate-500 dark:text-slate-400">{{ Lang::get('ledger::detail.description') }}</dt>
                    <dd class="break-words text-sm text-slate-900 dark:text-slate-100" data-testid="tx-detail-description">{{ $transaction->description }}</dd>
                </dl>
            @endif

            <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                <dl class="space-y-1">
                    <dt class="text-sm text-slate-500 dark:text-slate-400">{{ Lang::get('ledger::detail.counterparty') }}</dt>
                    <dd class="text-sm text-slate-900 dark:text-slate-100">
                        @if ($transaction->counterparty !== null && $transaction->counterparty->slug !== '')
                            <a
                                href="{{ route('counterparties.profile', ['slug' => $transaction->counterparty->slug]) }}"
                                wire:navigate
                                class="tap-link break-words underline-offset-2 hover:underline focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 focus-visible:ring-offset-2 dark:focus-visible:ring-slate-100"
                                data-testid="tx-detail-counterparty-link"
                            >{{ $transaction->counterparty_name ?? '—' }}</a>
                        @else
                            <span class="break-words" data-testid="tx-detail-counterparty-text">{{ $transaction->counterparty_name ?? '—' }}</span>
                        @endif
                    </dd>
                </dl>

                <dl class="space-y-1">
                    <dt class="text-sm text-slate-500 dark:text-slate-400">{{ Lang::get('ledger::detail.amount_native') }}</dt>
                    <dd class="text-sm text-slate-900 dark:text-slate-100" style="font-variant-numeric: tabular-nums;">
                        {{ $fmt(Money::ofMinor($transaction->amount_minor, $transaction->currency)) }} {{ $transaction->currency }}
                    </dd>
                </dl>

                <dl class="space-y-1">
                    <dt class="text-sm text-slate-500 dark:text-slate-400">{{ Lang::get('ledger::detail.amount_settled') }}</dt>
                    <dd class="text-sm text-slate-900 dark:text-slate-100" style="font-variant-numeric: tabular-nums;">
                        {{ $fmt(Money::ofMinor($transaction->settled_amount_minor, $transaction->settled_currency)) }} {{ $transaction->settled_currency }}
                    </dd>
                </dl>

                @if ($fxRateDisplay !== null)
                    <dl class="space-y-1" data-testid="fx-rate-row">
                        <dt class="text-sm text-slate-500 dark:text-slate-400">{{ Lang::get('ledger::detail.effective_rate') }}</dt>
                        <dd class="text-sm text-slate-900 dark:text-slate-100" style="font-variant-numeric: tabular-nums;">
                            {{ Money::symbolFor($transaction->settled_currency) }}{{ $fxRateDisplay }} / {{ $transaction->currency }}
                        </dd>
                        <dd class="text-xs text-slate-500 dark:text-slate-400">{{ Lang::get('ledger::detail.ics_markup') }}</dd>
                    </dl>
                @endif
            </div>

            {{-- Split editor (UI-SPEC §7): inline, gated to
                 non-transfer types. Placed between the
                 money dl and Reclassify per §7.1 — category is the most
                 fundamental fact about a transaction after its amount. --}}
            @if ($isSplittable ?? false)
                <section
                    aria-labelledby="split-heading"
                    class="border-t border-slate-200 pt-6 space-y-3 dark:border-slate-700"
                    data-testid="split-section"
                >
                    @if (! $editingSplit)
                        {{-- §7.2 Not-yet-split empty state. --}}
                        <div class="flex flex-wrap items-center justify-between gap-3">
                            <div class="space-y-1">
                                <h2 id="split-heading" class="text-base font-medium text-slate-900 dark:text-slate-100">
                                    {{ Lang::get('ledger::detail.split.category') }}
                                </h2>
                                <p class="text-sm text-slate-900 dark:text-slate-100" data-testid="split-current-category">
                                    {{ $currentCategoryName ?? '—' }}
                                </p>
                            </div>
                            <x-core::secondary-button
                                size="sm"
                                class="shadow-sm"
                                wire:click="openSplitEditor"
                                data-testid="split-open-button"
                            >
                                {{ Lang::get('ledger::detail.split.open') }}
                            </x-core::secondary-button>
                        </div>
                    @else
                        {{-- §7.3 Editor — open state. --}}
                        <div class="space-y-1">
                            <h2 id="split-heading" class="text-base font-medium text-slate-900 dark:text-slate-100">
                                {{ Lang::get('ledger::detail.split.heading') }}
                            </h2>
                            <p class="text-sm text-slate-500 dark:text-slate-400" style="font-variant-numeric: tabular-nums;">
                                {{ Lang::get('ledger::detail.split.total', ['amount' => $fmt(Money::ofMinor($transaction->settled_amount_minor, $transaction->settled_currency))]) }}
                            </p>
                            @if ($hasPersistedSplit)
                                {{-- Tax ownership moves to legs once a split is persisted. --}}
                                <p class="text-xs text-slate-600 dark:text-slate-400" data-testid="split-tax-ownership-note">
                                    {{ Lang::get('ledger::detail.split.tax_per_category') }}
                                </p>
                            @endif
                        </div>

                        @if ($splitError)
                            <div class="wiz-error" role="alert" data-testid="split-error-banner">
                                {{ $splitError }}
                            </div>
                        @endif

                        <div data-testid="split-legs">
                            @foreach ($legs as $index => $leg)
                                <div class="split-leg-row" data-testid="split-leg-row-{{ $index }}">
                                    <div>
                                        <label class="sr-only" for="split-leg-category-{{ $index }}">{{ Lang::get('ledger::detail.split.category') }}</label>
                                        <select
                                            wire:model.live="legs.{{ $index }}.categoryId"
                                            id="split-leg-category-{{ $index }}"
                                            class="rounded-md border border-slate-200 bg-white px-2 py-1.5 text-sm text-slate-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 focus-visible:ring-offset-2 dark:bg-slate-950 dark:border-slate-700 dark:text-slate-100"
                                            data-testid="split-leg-category-{{ $index }}"
                                        >
                                            <option value="">{{ Lang::get('ledger::detail.split.choose_category') }}</option>
                                            @foreach ($splitCategories as $cat)
                                                <option value="{{ $cat->id }}" @selected(($leg['categoryId'] ?? null) !== null && (int) $leg['categoryId'] === $cat->id)>{{ $cat->path }}</option>
                                            @endforeach
                                        </select>
                                    </div>

                                    <span class="budget-step-amount">
                                        {{-- The leg is read at the transaction's own currency, so a
                                             pinned euro sign over a yen figure named the wrong money
                                             and the box under it offered a decimal the parser refuses. --}}
                                        <span aria-hidden="true">{{ Money::symbolFor($transaction->settled_currency) }}</span>
                                        <label class="sr-only" for="split-leg-amount-{{ $index }}">{{ Lang::get('ledger::list.table.amount') }}</label>
                                        <input
                                            type="text"
                                            inputmode="{{ MoneyInput::decimalPlaces($transaction->settled_currency) === 0 ? 'numeric' : 'decimal' }}"
                                            id="split-leg-amount-{{ $index }}"
                                            placeholder="{{ MoneyInput::formatAbsMinor(0, $transaction->settled_currency) }}"
                                            wire:model.live.debounce.300ms="legs.{{ $index }}.amount"
                                            data-testid="split-leg-amount-{{ $index }}"
                                        >
                                    </span>

                                    <div class="flex flex-1 min-w-[10rem] flex-col gap-1">
                                        <label class="sr-only" for="split-leg-note-{{ $index }}">{{ Lang::get('ledger::detail.split.note_label') }}</label>
                                        <input
                                            type="text"
                                            id="split-leg-note-{{ $index }}"
                                            placeholder="{{ Lang::get('ledger::detail.split.note_placeholder') }}"
                                            wire:model="legs.{{ $index }}.note"
                                            class="w-full rounded-md border border-slate-200 bg-white px-2 py-1.5 text-sm text-slate-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 focus-visible:ring-offset-2 dark:bg-slate-950 dark:border-slate-700 dark:text-slate-100"
                                            data-testid="split-leg-note-{{ $index }}"
                                        >

                                        {{-- A span, not a label: a label associates with a form
                                             control, and this wraps a button. The button carries its
                                             own aria-label, so the visible text beside it is the
                                             same string rather than a second, unattached name. --}}
                                        <span class="inline-flex items-center gap-2 text-xs text-slate-500 dark:text-slate-400">
                                            {{-- :disabled, not @disabled — a directive inside a component
                                                 tag defeats the tag compiler and ships it as raw HTML. --}}
                                            <x-core::switch
                                                :on="(bool) ($leg['tax'] ?? false)"
                                                :label="Lang::get('ledger::detail.split.tax_deductible')"
                                                wire:click="toggleLegTax({{ $index }})"
                                                :disabled="$leg['id'] === null"
                                                data-testid="split-leg-tax-toggle-{{ $index }}"
                                            />
                                            {{ Lang::get('ledger::detail.split.tax_deductible') }}
                                        </span>
                                    </div>

                                    <x-core::emoji-action
                                        :label="Lang::get('ledger::detail.split.remove_leg_aria')"
                                        :caption="Lang::get('ledger::detail.split.remove_leg_caption')"
                                        tone="danger"
                                        wire:click="removeLeg({{ $index }})"
                                        data-testid="split-leg-remove-{{ $index }}"
                                    >🗑️</x-core::emoji-action>
                                </div>
                            @endforeach
                        </div>

                        <div class="flex flex-wrap items-center gap-3">
                            <button
                                type="button"
                                wire:click="addLeg"
                                class="text-sm text-slate-500 hover:text-slate-900 dark:text-slate-400 dark:hover:text-slate-100"
                                data-testid="split-add-leg"
                            >
                                {{ Lang::get('ledger::detail.split.add_category') }}
                            </button>

                            @if (count($legs) >= 18)
                                <span class="text-xs text-slate-600 dark:text-slate-400" data-testid="split-soft-cap-advisory">
                                    {{ Lang::get('ledger::detail.split.soft_cap', ['count' => count($legs)]) }}
                                </span>
                            @endif
                        </div>

                        @php
                            $remainingAbsDisplay = $fmt(Money::ofMinor(abs($remainingMinor), $transaction->settled_currency));
                        @endphp
                        <div
                            class="split-remaining-bar {{ $remainingMinor === 0 ? 'split-remaining-bar--zero' : ($remainingMinor > 0 ? 'split-remaining-bar--positive' : 'split-remaining-bar--over') }}"
                            aria-atomic="true"
                            aria-live="polite"
                            data-testid="split-remaining-bar"
                        >
                            @if ($remainingMinor === 0)
                                {{ Lang::get('ledger::detail.split.remaining_zero', ['amount' => $remainingAbsDisplay]) }}
                            @elseif ($remainingMinor > 0)
                                {{ Lang::get('ledger::detail.split.remaining_to_assign', ['amount' => $remainingAbsDisplay]) }}
                            @else
                                {{ Lang::get('ledger::detail.split.over_allocated', ['amount' => $remainingAbsDisplay]) }}
                            @endif
                        </div>

                        <div class="flex flex-wrap items-center gap-3">
                            <x-core::neutral-button
                                size="sm"
                                class="shadow-sm disabled:cursor-not-allowed disabled:bg-slate-300"
                                :disabled="$remainingMinor !== 0 || count($legs) < 2"
                                wire:click="saveSplit"
                                wire:loading.attr="disabled"
                                wire:target="saveSplit"
                                data-testid="split-save-button"
                            >
                                <span wire:loading.remove wire:target="saveSplit">{{ Lang::get('ledger::detail.split.save') }}</span>
                                <span wire:loading wire:target="saveSplit">{{ Lang::get('ledger::detail.split.saving') }}</span>
                            </x-core::neutral-button>

                            <button
                                type="button"
                                wire:click="unsplit"
                                class="text-sm font-medium text-slate-900 underline-offset-2 hover:underline dark:text-slate-100"
                                data-testid="split-unsplit-link"
                            >
                                {{ Lang::get('ledger::detail.split.unsplit') }}
                            </button>
                        </div>

                        @if ($confirmRemoveToOne && $pendingRemoveIndex !== null && array_key_exists($pendingRemoveIndex === 0 ? 1 : 0, $legs))
                            @php
                                $survivorIdx = $pendingRemoveIndex === 0 ? 1 : 0;
                                $survivorCat = collect($splitCategories)->firstWhere('id', (int) ($legs[$survivorIdx]['categoryId'] ?? 0));
                                $survivorName = $survivorCat->path ?? Lang::get('ledger::detail.split.remove_to_one_fallback');
                            @endphp
                            <div class="flex flex-wrap items-center gap-3" aria-live="polite" aria-atomic="true" data-testid="split-remove-to-one-confirm">
                                <span class="text-sm text-slate-700 dark:text-slate-300">
                                    {{ Lang::get('ledger::detail.split.remove_to_one', ['category' => $survivorName]) }}
                                </span>
                                <button
                                    type="button"
                                    wire:click="cancelRemoveToOne"
                                    class="text-sm text-slate-500 hover:text-slate-700 dark:text-slate-400"
                                >
                                    {{ Lang::get('ledger::detail.split.keep_category') }}
                                </button>
                                <button
                                    type="button"
                                    wire:click="confirmRemoveToOneAction"
                                    class="rounded-md border border-rose-200 bg-white px-3 py-1.5 text-sm font-medium text-rose-700 shadow-sm hover:bg-rose-50 dark:border-rose-800 dark:bg-slate-950 dark:text-rose-400"
                                    data-testid="split-remove-to-one-confirm-button"
                                >
                                    {{ Lang::get('ledger::detail.split.remove_category') }}
                                </button>
                            </div>
                        @endif

                        @if ($confirmUnsplit)
                            <div class="space-y-2" aria-live="polite" aria-atomic="true" data-testid="split-unsplit-confirm">
                                <p class="text-sm text-slate-700 dark:text-slate-300">{{ Lang::get('ledger::detail.split.restore_single') }}</p>
                                {{-- Without the legend a screen reader reads each radio with
                                     nothing saying what is being chosen. sr-only because the
                                     sentence above already asks it on screen. --}}
                                <fieldset>
                                    <legend class="sr-only">{{ Lang::get('ledger::detail.split.survivor_legend') }}</legend>
                                    <div class="flex flex-wrap items-center gap-3">
                                        @foreach ($legs as $index => $leg)
                                            @php
                                                $radioCat = collect($splitCategories)->firstWhere('id', (int) ($leg['categoryId'] ?? 0));

                                                // Parsed back from the string the user typed so the symbol
                                                // and grouping come from the leg's currency, not a literal €.
                                                $radioMinor = MoneyInput::tryToMinor((string) ($leg['amount'] ?? ''), $transaction->settled_currency);
                                            @endphp
                                            <label class="inline-flex items-center gap-2 text-sm text-slate-700 dark:text-slate-300">
                                                <input
                                                    type="radio"
                                                    name="unsplit-survivor"
                                                    wire:click="selectUnsplitSurvivor({{ $index }})"
                                                    @checked($unsplitSurvivorIndex === $index)
                                                >
                                                {{ $radioCat->path ?? '—' }} {{ $radioMinor === null ? '—' : $fmt(Money::ofMinor($radioMinor, $transaction->settled_currency)) }}
                                            </label>
                                        @endforeach
                                    </div>
                                </fieldset>
                                <div class="flex items-center gap-3">
                                    <button
                                        type="button"
                                        wire:click="cancelUnsplit"
                                        class="text-sm text-slate-500 hover:text-slate-700 dark:text-slate-400"
                                    >
                                        {{ Lang::get('ledger::detail.split.keep_split') }}
                                    </button>
                                    <button
                                        type="button"
                                        wire:click="confirmUnsplitAction"
                                        class="rounded-md border border-rose-200 bg-white px-3 py-1.5 text-sm font-medium text-rose-700 shadow-sm hover:bg-rose-50 dark:border-rose-800 dark:bg-slate-950 dark:text-rose-400"
                                        data-testid="split-unsplit-confirm-button"
                                    >
                                        {{ Lang::get('ledger::detail.split.confirm_unsplit') }}
                                    </button>
                                </div>
                            </div>
                        @endif
                    @endif
                </section>
            @endif

            {{-- Tax badge: sits next to the reclassify section.
                 Suppressed once the transaction is split — tax
                 ownership moves to the legs (see the Split section above). --}}
            @if (isset($txTaxRow) && ! ($hasPersistedSplit ?? false))
                <section
                    aria-label="{{ Lang::get('ledger::detail.tax.section_aria') }}"
                    class="border-t border-slate-200 pt-6 dark:border-slate-700"
                    data-testid="tax-tag-section"
                >
                    <div class="flex items-center gap-3">
                        <span class="text-sm text-slate-500 dark:text-slate-400">{{ Lang::get('ledger::detail.tax.label') }}</span>
                        <x-tax::tax-badge :transaction="$txTaxRow" :showAlways="true" />
                    </div>
                </section>
            @endif

            <section
                aria-labelledby="reclassify-heading"
                class="border-t border-slate-200 pt-6 space-y-3 dark:border-slate-700"
                data-testid="reclassify-control"
            >
                <div class="space-y-1">
                    <h2 id="reclassify-heading" class="text-base font-medium text-slate-900 dark:text-slate-100">
                        {{ Lang::get('ledger::detail.reclassify.heading') }}
                    </h2>
                    <p class="text-sm text-slate-500 dark:text-slate-400">
                        {{ Lang::get('ledger::detail.reclassify.help') }}
                    </p>
                </div>

                <div
                    class="flex items-center gap-3"
                    x-data="{ toast: null }"
                    x-on:toast.window="toast = $event.detail?.message ?? null; setTimeout(() => toast = null, 3000)"
                >
                    <label class="sr-only" for="reclassify-type">{{ Lang::get('ledger::detail.reclassify.choose_aria') }}</label>
                    <select
                        wire:model.live="reclassifyType"
                        id="reclassify-type"
                        class="rounded-md border border-slate-200 bg-white px-3 py-1.5 text-sm text-slate-900 shadow-sm focus:border-slate-400 focus:outline-none focus:ring-1 focus:ring-slate-400 dark:bg-slate-950 dark:text-slate-100 dark:border-slate-700"
                    >
                        <option value="">{{ Lang::get('ledger::detail.reclassify.choose_option') }}</option>
                        @foreach (\Modules\Ledger\Public\Enums\TransactionType::cases() as $type)
                            @if ($type->value !== $transaction->type)
                                <option value="{{ $type->value }}">{{ Lang::get('ledger::detail.type_label.'.$type->value) }}</option>
                            @endif
                        @endforeach
                    </select>

                    <x-core::neutral-button
                        size="sm"
                        class="shadow-sm disabled:cursor-not-allowed disabled:bg-slate-300"
                        :disabled="$reclassifyType === ''"
                        wire:click="reclassify($wire.reclassifyType)"
                    >
                        {{ Lang::get('ledger::detail.reclassify.save') }}
                    </x-core::neutral-button>

                    <span
                        x-show="toast"
                        x-cloak
                        x-transition.opacity
                        class="text-sm text-slate-600 dark:text-slate-400"
                        aria-atomic="true"
                        aria-live="polite"
                        x-text="toast"
                    ></span>
                </div>
            </section>

            {{-- Categorization provenance panel.

                 Renders inline between the Reclassify section and the
                 View chain section. Three variants per UI-SPEC:
                 rule / memory / none (nothing). Embeds as a Livewire
                 sub-component so the per-transaction state stays
                 scoped to the row currently on screen. --}}
            @livewire(
                'categorization.categorization-provenance-panel',
                ['transactionId' => $transaction->id],
                key('provenance-' . $transaction->id),
            )

            {{-- Note editor (OQ-A): calm textarea + Save button. --}}
            <section
                aria-labelledby="note-heading"
                class="border-t border-slate-200 pt-6 space-y-3 dark:border-slate-700"
                data-testid="note-editor"
            >
                <div class="space-y-1">
                    <h2 id="note-heading" class="text-base font-medium text-slate-900 dark:text-slate-100">
                        {{ Lang::get('ledger::detail.note.heading') }}
                    </h2>
                    <p class="text-sm text-slate-500 dark:text-slate-400">
                        {{ Lang::get('ledger::detail.note.help') }}
                    </p>
                </div>

                <div class="space-y-2">
                    <label class="sr-only" for="transaction-note">{{ Lang::get('ledger::detail.note.label') }}</label>
                    <textarea
                        wire:model="note"
                        id="transaction-note"
                        rows="3"
                        maxlength="{{ SensitiveTextBudget::MAX_PLAINTEXT_CHARACTERS }}"
                        placeholder="{{ Lang::get('ledger::detail.note.placeholder') }}"
                        class="w-full rounded-md border border-slate-200 bg-white px-3 py-2 text-sm text-slate-900 shadow-sm focus:border-slate-400 focus:outline-none focus:ring-1 focus:ring-slate-400 dark:bg-slate-950 dark:text-slate-100 dark:border-slate-700"
                        data-testid="note-textarea"
                    ></textarea>

                    <div class="flex items-center gap-3">
                        <x-core::neutral-button
                            size="sm"
                            class="shadow-sm"
                            wire:click="saveNote"
                            data-testid="note-save-button"
                        >
                            {{ Lang::get('ledger::detail.note.save') }}
                        </x-core::neutral-button>

                        @if ($noteSaved)
                            <span
                                class="text-sm text-emerald-700 dark:text-emerald-400"
                                aria-live="polite" aria-atomic="true"
                                data-testid="note-saved-indicator"
                            >{{ Lang::get('ledger::detail.note.saved') }}</span>
                        @endif
                    </div>
                </div>
            </section>

            {{-- Counterparty reassignment (OQ-C): select picker — user-driven write only.
                 Import-pipeline / GC writes are NOT user-driven and are NOT captured. --}}
            @if (isset($counterparties) && $counterparties->isNotEmpty())
                <section
                    aria-labelledby="counterparty-heading"
                    class="border-t border-slate-200 pt-6 space-y-3 dark:border-slate-700"
                    data-testid="counterparty-reassignment"
                    x-data="{ selectedCp: '' }"
                >
                    <div class="space-y-1">
                        <h2 id="counterparty-heading" class="text-base font-medium text-slate-900 dark:text-slate-100">
                            {{ Lang::get('ledger::detail.reassign.heading') }}
                        </h2>
                        <p class="text-sm text-slate-500 dark:text-slate-400">
                            {{ Lang::get('ledger::detail.reassign.help') }}
                        </p>
                    </div>

                    <div class="flex items-center gap-3">
                        <label class="sr-only" for="counterparty-select">{{ Lang::get('ledger::detail.reassign.choose_aria') }}</label>
                        <select
                            id="counterparty-select"
                            x-model="selectedCp"
                            class="rounded-md border border-slate-200 bg-white px-3 py-1.5 text-sm text-slate-900 shadow-sm focus:border-slate-400 focus:outline-none focus:ring-1 focus:ring-slate-400 dark:bg-slate-950 dark:text-slate-100 dark:border-slate-700"
                            data-testid="counterparty-select"
                        >
                            <option value="">{{ Lang::get('ledger::detail.reassign.choose_option') }}</option>
                            @foreach ($counterparties as $cp)
                                <option value="{{ $cp->id }}"
                                    {{ $transaction->counterparty_id == $cp->id ? 'selected' : '' }}
                                >{{ $cp->display_name }}</option>
                            @endforeach
                        </select>

                        <x-core::neutral-button
                            size="sm"
                            class="shadow-sm disabled:cursor-not-allowed disabled:bg-slate-300"
                            x-bind:disabled="!selectedCp"
                            x-on:click="$wire.reassignCounterparty(Number(selectedCp))"
                            data-testid="counterparty-reassign-button"
                        >
                            {{ Lang::get('ledger::detail.reassign.submit') }}
                        </x-core::neutral-button>
                    </div>
                </section>
            @endif

            {{-- Savings-goal attribution: the transaction detail screen is the
                 one place a transaction is assigned to anything, so goal
                 attribution sits alongside the category, split and
                 counterparty pickers rather than on the goals page. --}}
            @if (! empty($goalOptions) || ! empty($attributedGoals))
                <section
                    aria-labelledby="goal-attribution-heading"
                    class="border-t border-slate-200 pt-6 space-y-3 dark:border-slate-700"
                    data-testid="goal-attribution"
                    x-data="{ selectedGoal: '' }"
                >
                    <div class="space-y-1">
                        <h2 id="goal-attribution-heading" class="text-base font-medium text-slate-900 dark:text-slate-100">
                            {{ Lang::get('ledger::detail.goal.heading') }}
                        </h2>
                        <p class="text-sm text-slate-500 dark:text-slate-400">
                            {{ Lang::get('ledger::detail.goal.help') }}
                        </p>
                    </div>

                    @if (! empty($attributedGoals))
                        <ul class="flex flex-wrap gap-2" data-testid="goal-attribution-list">
                            @foreach ($attributedGoals as $attributed)
                                <li class="inline-flex items-center gap-1.5 rounded-full bg-slate-100 py-1 pl-3 pr-1.5 text-sm text-slate-700 dark:bg-slate-800 dark:text-slate-200">
                                    <span>{{ $attributed->goalName }}</span>
                                    <button
                                        type="button"
                                        wire:click="removeGoalAttribution({{ $attributed->goalId }})"
                                        aria-label="{{ Lang::get('ledger::detail.goal.remove_aria', ['name' => $attributed->goalName]) }}"
                                        class="rounded-full px-1.5 text-slate-600 hover:text-slate-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-400 dark:hover:text-slate-100 dark:text-slate-400"
                                        data-testid="goal-attribution-remove"
                                    >
                                        <span aria-hidden="true">&times;</span>
                                    </button>
                                </li>
                            @endforeach
                        </ul>
                    @endif

                    @if (! empty($goalOptions))
                        <div class="flex items-center gap-3">
                            <label class="sr-only" for="goal-attribution-select">{{ Lang::get('ledger::detail.goal.choose_aria') }}</label>
                            <select
                                id="goal-attribution-select"
                                x-model="selectedGoal"
                                class="rounded-md border border-slate-200 bg-white px-3 py-1.5 text-sm text-slate-900 shadow-sm focus:border-slate-400 focus:outline-none focus:ring-1 focus:ring-slate-400 dark:bg-slate-950 dark:text-slate-100 dark:border-slate-700"
                                data-testid="goal-attribution-select"
                            >
                                <option value="">{{ Lang::get('ledger::detail.goal.choose_option') }}</option>
                                @foreach ($goalOptions as $goalOption)
                                    <option value="{{ $goalOption->goalId }}">{{ $goalOption->goalName }}</option>
                                @endforeach
                            </select>

                            <x-core::neutral-button
                                size="sm"
                                class="shadow-sm disabled:cursor-not-allowed disabled:bg-slate-300"
                                x-bind:disabled="!selectedGoal"
                                x-on:click="$wire.attributeToGoal(Number(selectedGoal)); selectedGoal = ''"
                                data-testid="goal-attribution-submit"
                            >
                                {{ Lang::get('ledger::detail.goal.submit') }}
                            </x-core::neutral-button>
                        </div>
                    @endif
                </section>
            @endif

            {{-- Delete affordance: confirm-guarded delete with a danger-tone button. --}}
            <section
                aria-labelledby="delete-heading"
                class="border-t border-slate-200 pt-6 space-y-3 dark:border-slate-700"
                data-testid="delete-section"
                x-data="{ confirmDelete: false }"
            >
                <div class="space-y-1">
                    <h2 id="delete-heading" class="text-base font-medium text-slate-900 dark:text-slate-100">
                        {{ Lang::get('ledger::detail.delete.heading') }}
                    </h2>
                    <p class="text-sm text-slate-500 dark:text-slate-400">
                        {{ Lang::get('ledger::detail.delete.help') }}
                    </p>
                </div>

                <div class="flex items-center gap-3">
                    <button
                        type="button"
                        x-show="!confirmDelete"
                        @click="confirmDelete = true"
                        class="rounded-md border border-rose-200 bg-white px-3 py-1.5 text-sm font-medium text-rose-700 shadow-sm hover:bg-rose-50 dark:border-rose-800 dark:bg-slate-950 dark:text-rose-400"
                        data-testid="delete-button"
                    >
                        {{ Lang::get('ledger::detail.delete.button') }}
                    </button>

                    {{-- flex-wrap and shrink-0 are the 44px touch floor's trap: the
                         coarse-pointer min-width REPLACES a button's min-width: auto,
                         so a shrinkable answer squeezes to 44px and breaks its
                         label one word per line. --}}
                    <template x-if="confirmDelete">
                        <div class="flex flex-wrap items-center gap-3">
                            <span class="text-sm text-slate-700 dark:text-slate-300">{{ Lang::get('ledger::detail.delete.confirm_prompt') }}</span>
                            <button
                                type="button"
                                @click="confirmDelete = false"
                                class="shrink-0 text-sm text-slate-500 hover:text-slate-700 dark:text-slate-400"
                            >
                                {{ Lang::get('ledger::detail.delete.cancel') }}
                            </button>
                            <button
                                type="button"
                                wire:click="deleteTransaction"
                                class="shrink-0 rounded-md bg-rose-700 px-3 py-1.5 text-sm font-medium text-white shadow-sm hover:bg-rose-800"
                                data-testid="delete-confirm-button"
                            >
                                {{ Lang::get('ledger::detail.delete.confirm') }}
                            </button>
                        </div>
                    </template>
                </div>
            </section>

            @if (($chainAvailable ?? false) === true)
                {{-- "View chain" trigger opens the chain
                     drill-down drawer (the first Flux flyout in the
                     project). Text-link styling per UI-SPEC § Chain
                     drill-down drawer — visually subordinate to the
                     Reclassify save button so the page focal hierarchy
                     stays calm. The wire:click dispatch carries the
                     transactionId payload; the drawer Livewire SFC
                     listens via #[On('chain-drawer:open')]. --}}
                <section class="border-t border-slate-200 pt-6 dark:border-slate-700">
                    {{-- The drawer component's #[On('chain-drawer:open')]
                         listener sets its transactionId AND dispatches
                         the Flux modal-show event itself. Both the
                         data load and the modal open happen on the same
                         wire round-trip, so the modal name match is
                         deterministic — no Alpine + wire race. --}}
                    <button
                        type="button"
                        wire:click="$dispatch('chain-drawer:open', { transactionId: {{ $transaction->id }} })"
                        class="text-sm font-medium text-slate-900 underline-offset-2 hover:underline focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 focus-visible:ring-offset-2 dark:text-slate-100"
                    >
                        {{ Lang::get('ledger::detail.chain.view') }}
                    </button>
                </section>

                @livewire('chains.chain-drawer')
            @endif
        </div>
    </div>
</div>
