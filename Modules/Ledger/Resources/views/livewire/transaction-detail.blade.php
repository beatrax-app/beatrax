@php
    use Brick\Math\BigDecimal;
    use Brick\Math\RoundingMode;
    use Carbon\CarbonImmutable;
    use Modules\Ledger\Public\ValueObjects\Money;

    // EUR amounts render in Dutch locale; non-EUR amounts in US English
    // locale so the symbol prefix matches the user's mental model.
    // brick/money routes the locale through ext-intl's NumberFormatter.
    $fmt = static fn (Money $money): string => $money->currency() === 'EUR'
        ? $money->format('nl_NL')
        : $money->format('en_US');

    // FX-rate display: scale the persisted decimal(18,8) string to three
    // decimals via BigDecimal so the value never crosses the float
    // boundary. number_format() with a float cast would silently corrupt
    // FX precision; the integer-only money rule extends to rate display.
    $fxRateDisplay = $transaction->fx_rate_used === null
        ? null
        : (string) BigDecimal::of($transaction->fx_rate_used)
            ->toScale(3, RoundingMode::HALF_UP);
@endphp

<div>
    {{-- Tax tag picker — rendered once per page (not per row). --}}
    @include('tax::components.tax-tag-popover')

    {{-- Mobile top bar (D-05): back affordance targeting /transactions parent list.
         Visible only at <1024px (CSS .top-bar rule sets display:none at >=1024px).
         The page title is "Transaction" + the posted date for context. --}}
    <x-core::mobile-top-bar
        :backUrl="route('transactions.index')"
        title="Transaction"
    />

    <main class="min-h-screen bg-white dark:bg-slate-950">
        <div class="mx-auto max-w-3xl px-8 py-12 space-y-6" data-testid="transaction-detail">
            <header class="space-y-1">
                <div class="flex items-center gap-3">
                    <h1 class="text-2xl font-semibold tracking-tight text-slate-900 dark:text-slate-100">Transaction</h1>
                    {{-- Cleared/uncleared/reconciled badge + toggle (SC-1, D-11). --}}
                    <x-ledger::cleared-badge :transaction="['id' => $transaction->id, 'status' => $clearedStatus ?? \Modules\Ledger\Public\Enums\ClearedStatus::Cleared->value]" />
                </div>
                <p class="text-sm text-slate-500 dark:text-slate-400">
                    {{ CarbonImmutable::parse($transaction->posted_at)->format('j M Y') }}
                </p>
            </header>

            <dl class="grid grid-cols-1 gap-4 md:grid-cols-2">
                <div class="space-y-1">
                    <dt class="text-sm text-slate-500 dark:text-slate-400">Counterparty</dt>
                    <dd class="text-sm text-slate-900 dark:text-slate-100">
                        @if ($transaction->counterparty !== null && $transaction->counterparty->slug !== '')
                            <a
                                href="{{ route('counterparties.profile', ['slug' => $transaction->counterparty->slug]) }}"
                                wire:navigate
                                class="underline-offset-2 hover:underline focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 focus-visible:ring-offset-2 dark:focus-visible:ring-slate-100"
                                data-testid="tx-detail-counterparty-link"
                            >{{ $transaction->counterparty_name ?? '—' }}</a>
                        @else
                            <span data-testid="tx-detail-counterparty-text">{{ $transaction->counterparty_name ?? '—' }}</span>
                        @endif
                    </dd>
                </div>

                <div class="space-y-1">
                    <dt class="text-sm text-slate-500 dark:text-slate-400">Amount (native)</dt>
                    <dd class="text-sm text-slate-900 dark:text-slate-100" style="font-variant-numeric: tabular-nums;">
                        {{ $fmt(Money::ofMinor($transaction->amount_minor, $transaction->currency)) }} {{ $transaction->currency }}
                    </dd>
                </div>

                <div class="space-y-1">
                    <dt class="text-sm text-slate-500 dark:text-slate-400">Amount (settled EUR)</dt>
                    <dd class="text-sm text-slate-900 dark:text-slate-100" style="font-variant-numeric: tabular-nums;">
                        {{ $fmt(Money::ofMinor($transaction->settled_amount_minor, $transaction->settled_currency)) }} {{ $transaction->settled_currency }}
                    </dd>
                </div>

                @if ($fxRateDisplay !== null)
                    <div class="space-y-1" data-testid="fx-rate-row">
                        <dt class="text-sm text-slate-500 dark:text-slate-400">Effective rate</dt>
                        <dd class="text-sm text-slate-900 dark:text-slate-100" style="font-variant-numeric: tabular-nums;">
                            €{{ $fxRateDisplay }} / {{ $transaction->currency }}
                        </dd>
                        <p class="text-xs text-slate-500 dark:text-slate-400">Includes any ICS markup.</p>
                    </div>
                @endif
            </dl>

            {{-- Split editor (Phase 13.1 Plan 05, UI-SPEC §7): inline, gated to
                 non-transfer types (Req 6, D-07/D-08). Placed between the
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
                        <div class="flex items-center justify-between gap-3">
                            <div class="space-y-1">
                                <h2 id="split-heading" class="text-base font-medium text-slate-900 dark:text-slate-100">
                                    Category
                                </h2>
                                <p class="text-sm text-slate-900 dark:text-slate-100" data-testid="split-current-category">
                                    {{ $transaction->category?->name ?? '—' }}
                                </p>
                            </div>
                            <button
                                type="button"
                                wire:click="openSplitEditor"
                                class="rounded-md border border-slate-200 bg-white px-3 py-1.5 text-sm font-medium text-slate-700 shadow-sm hover:bg-slate-50 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-200"
                                data-testid="split-open-button"
                            >
                                Split into categories
                            </button>
                        </div>
                    @else
                        {{-- §7.3 Editor — open state. --}}
                        <div class="space-y-1">
                            <h2 id="split-heading" class="text-base font-medium text-slate-900 dark:text-slate-100">
                                Split across categories
                            </h2>
                            <p class="text-sm text-slate-500 dark:text-slate-400" style="font-variant-numeric: tabular-nums;">
                                Total {{ $fmt(Money::ofMinor($transaction->settled_amount_minor, $transaction->settled_currency)) }}
                            </p>
                            @if ($hasPersistedSplit)
                                {{-- D-06: tax ownership moves to legs once a split is persisted. --}}
                                <p class="text-xs text-slate-400 dark:text-slate-500" data-testid="split-tax-ownership-note">
                                    Tax tags are set per category below.
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
                                        <label class="sr-only" for="split-leg-category-{{ $index }}">Category</label>
                                        <select
                                            wire:model.live="legs.{{ $index }}.categoryId"
                                            id="split-leg-category-{{ $index }}"
                                            class="rounded-md border border-slate-200 bg-white px-2 py-1.5 text-sm text-slate-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 focus-visible:ring-offset-2 dark:bg-slate-950 dark:border-slate-700 dark:text-slate-100"
                                            data-testid="split-leg-category-{{ $index }}"
                                        >
                                            <option value="">Choose a category</option>
                                            @foreach ($splitCategories as $cat)
                                                <option value="{{ $cat->id }}" @selected(($leg['categoryId'] ?? null) !== null && (int) $leg['categoryId'] === $cat->id)>{{ $cat->path }}</option>
                                            @endforeach
                                        </select>
                                    </div>

                                    <span class="budget-step-amount">
                                        <span aria-hidden="true">€</span>
                                        <label class="sr-only" for="split-leg-amount-{{ $index }}">Amount</label>
                                        <input
                                            type="text"
                                            inputmode="decimal"
                                            id="split-leg-amount-{{ $index }}"
                                            placeholder="0,00"
                                            wire:model.live.debounce.300ms="legs.{{ $index }}.amount"
                                            data-testid="split-leg-amount-{{ $index }}"
                                        >
                                    </span>

                                    <div class="flex flex-1 min-w-[10rem] flex-col gap-1">
                                        <label class="sr-only" for="split-leg-note-{{ $index }}">Note</label>
                                        <input
                                            type="text"
                                            id="split-leg-note-{{ $index }}"
                                            placeholder="Note (optional)"
                                            wire:model="legs.{{ $index }}.note"
                                            class="w-full rounded-md border border-slate-200 bg-white px-2 py-1.5 text-sm text-slate-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 focus-visible:ring-offset-2 dark:bg-slate-950 dark:border-slate-700 dark:text-slate-100"
                                            data-testid="split-leg-note-{{ $index }}"
                                        >

                                        {{-- A span, not a label: a label associates with a form
                                             control, and this wraps a button. The button carries its
                                             own aria-label, so the visible text beside it is the
                                             same string rather than a second, unattached name. --}}
                                        <span class="inline-flex items-center gap-2 text-xs text-slate-500 dark:text-slate-400">
                                            <button
                                                type="button"
                                                wire:click="toggleLegTax({{ $index }})"
                                                @disabled($leg['id'] === null)
                                                role="switch"
                                                aria-checked="{{ ($leg['tax'] ?? false) ? 'true' : 'false' }}"
                                                aria-label="Tax-deductible"
                                                class="switch {{ ($leg['tax'] ?? false) ? 'switch--on' : '' }}"
                                                data-testid="split-leg-tax-toggle-{{ $index }}"
                                            >
                                                <span class="switch__thumb"></span>
                                            </button>
                                            Tax-deductible
                                        </span>
                                    </div>

                                    <button
                                        type="button"
                                        wire:click="removeLeg({{ $index }})"
                                        class="inline-flex h-11 w-11 shrink-0 items-center justify-center rounded-md text-slate-400 hover:text-slate-600 dark:text-slate-500 dark:hover:text-slate-300"
                                        aria-label="Remove this category"
                                        data-testid="split-leg-remove-{{ $index }}"
                                    >
                                        &times;
                                    </button>
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
                                + Add category
                            </button>

                            @if (count($legs) >= 18)
                                <span class="text-xs text-slate-400 dark:text-slate-500" data-testid="split-soft-cap-advisory">
                                    {{ count($legs) }} of ~20 categories — consider grouping small amounts.
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
                                Remaining {{ $remainingAbsDisplay }} ✓
                            @elseif ($remainingMinor > 0)
                                Remaining to assign: {{ $remainingAbsDisplay }}
                            @else
                                Over-allocated by {{ $remainingAbsDisplay }} — reduce a leg.
                            @endif
                        </div>

                        <div class="flex flex-wrap items-center gap-3">
                            <button
                                type="button"
                                wire:click="saveSplit"
                                wire:loading.attr="disabled"
                                wire:target="saveSplit"
                                @disabled($remainingMinor !== 0 || count($legs) < 2)
                                class="rounded-md bg-slate-900 px-3 py-1.5 text-sm font-medium text-white shadow-sm hover:bg-slate-800 disabled:cursor-not-allowed disabled:bg-slate-300 dark:bg-slate-100 dark:text-slate-900"
                                data-testid="split-save-button"
                            >
                                <span wire:loading.remove wire:target="saveSplit">Save split</span>
                                <span wire:loading wire:target="saveSplit">Saving…</span>
                            </button>

                            <button
                                type="button"
                                wire:click="unsplit"
                                class="text-sm font-medium text-slate-900 underline-offset-2 hover:underline dark:text-slate-100"
                                data-testid="split-unsplit-link"
                            >
                                Unsplit transaction
                            </button>
                        </div>

                        @if ($confirmRemoveToOne && $pendingRemoveIndex !== null && array_key_exists($pendingRemoveIndex === 0 ? 1 : 0, $legs))
                            @php
                                $survivorIdx = $pendingRemoveIndex === 0 ? 1 : 0;
                                $survivorCat = collect($splitCategories)->firstWhere('id', (int) ($legs[$survivorIdx]['categoryId'] ?? 0));
                                $survivorName = $survivorCat->path ?? 'this category';
                            @endphp
                            <div class="flex flex-wrap items-center gap-3" aria-live="polite" aria-atomic="true" data-testid="split-remove-to-one-confirm">
                                <span class="text-sm text-slate-700 dark:text-slate-300">
                                    Removing this leaves one category — the transaction becomes {{ $survivorName }}.
                                </span>
                                <button
                                    type="button"
                                    wire:click="confirmRemoveToOneAction"
                                    class="rounded-md border border-rose-200 bg-white px-3 py-1.5 text-sm font-medium text-rose-700 shadow-sm hover:bg-rose-50 dark:border-rose-800 dark:bg-slate-950 dark:text-rose-400"
                                    data-testid="split-remove-to-one-confirm-button"
                                >
                                    Remove category
                                </button>
                                <button
                                    type="button"
                                    wire:click="cancelRemoveToOne"
                                    class="text-sm text-slate-500 hover:text-slate-700 dark:text-slate-400"
                                >
                                    Keep this category
                                </button>
                            </div>
                        @endif

                        @if ($confirmUnsplit)
                            <div class="space-y-2" aria-live="polite" aria-atomic="true" data-testid="split-unsplit-confirm">
                                <p class="text-sm text-slate-700 dark:text-slate-300">Restore as a single category?</p>
                                <div class="flex flex-wrap items-center gap-3">
                                    @foreach ($legs as $index => $leg)
                                        @php
                                            $radioCat = collect($splitCategories)->firstWhere('id', (int) ($leg['categoryId'] ?? 0));
                                        @endphp
                                        <label class="inline-flex items-center gap-2 text-sm text-slate-700 dark:text-slate-300">
                                            <input
                                                type="radio"
                                                name="unsplit-survivor"
                                                wire:click="selectUnsplitSurvivor({{ $index }})"
                                                @checked($unsplitSurvivorIndex === $index)
                                            >
                                            {{ $radioCat->path ?? '—' }} €{{ $leg['amount'] }}
                                        </label>
                                    @endforeach
                                </div>
                                <div class="flex items-center gap-3">
                                    <button
                                        type="button"
                                        wire:click="confirmUnsplitAction"
                                        class="rounded-md border border-rose-200 bg-white px-3 py-1.5 text-sm font-medium text-rose-700 shadow-sm hover:bg-rose-50 dark:border-rose-800 dark:bg-slate-950 dark:text-rose-400"
                                        data-testid="split-unsplit-confirm-button"
                                    >
                                        Yes, unsplit
                                    </button>
                                    <button
                                        type="button"
                                        wire:click="cancelUnsplit"
                                        class="text-sm text-slate-500 hover:text-slate-700 dark:text-slate-400"
                                    >
                                        Keep split
                                    </button>
                                </div>
                            </div>
                        @endif
                    @endif
                </section>
            @endif

            {{-- Tax badge: sits next to the reclassify section per D-01.
                 Suppressed once the transaction is split (D-06) — tax
                 ownership moves to the legs (see the Split section above). --}}
            @if (isset($txTaxRow) && ! ($hasPersistedSplit ?? false))
                <section
                    aria-label="Tax tag"
                    class="border-t border-slate-200 pt-6 dark:border-slate-700"
                    data-testid="tax-tag-section"
                >
                    <div class="flex items-center gap-3">
                        <span class="text-sm text-slate-500 dark:text-slate-400">Tax deductible</span>
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
                        Reclassify
                    </h2>
                    <p class="text-sm text-slate-500 dark:text-slate-400">
                        Override the detected type. If this transaction is paired with another,
                        choosing a non-transfer type will unpair both sides.
                    </p>
                </div>

                <div
                    class="flex items-center gap-3"
                    x-data="{ toast: null }"
                    x-on:toast.window="toast = $event.detail?.message ?? null; setTimeout(() => toast = null, 3000)"
                >
                    <label class="sr-only" for="reclassify-type">Choose new transaction type</label>
                    <select
                        wire:model.live="reclassifyType"
                        id="reclassify-type"
                        class="rounded-md border border-slate-200 bg-white px-3 py-1.5 text-sm text-slate-900 shadow-sm focus:border-slate-400 focus:outline-none focus:ring-1 focus:ring-slate-400 dark:bg-slate-950 dark:text-slate-100 dark:border-slate-700"
                    >
                        <option value="">Choose a type…</option>
                        @foreach (\Modules\Ledger\Public\Enums\TransactionType::cases() as $type)
                            @if ($type->value !== $transaction->type)
                                <option value="{{ $type->value }}">{{ $type->value }}</option>
                            @endif
                        @endforeach
                    </select>

                    <button
                        type="button"
                        wire:click="reclassify($wire.reclassifyType)"
                        @disabled($reclassifyType === '')
                        class="rounded-md bg-slate-900 px-3 py-1.5 text-sm font-medium text-white shadow-sm hover:bg-slate-800 disabled:cursor-not-allowed disabled:bg-slate-300 dark:bg-slate-100"
                    >
                        Save
                    </button>

                    <span
                        x-show="toast"
                        x-cloak
                        x-transition.opacity
                        class="text-sm text-slate-600"
                        aria-atomic="true"
                        aria-live="polite"
                        x-text="toast"
                    ></span>
                </div>
            </section>

            {{-- Categorization provenance panel (D-712).

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
                        Note
                    </h2>
                    <p class="text-sm text-slate-500 dark:text-slate-400">
                        Personal note for this transaction. Visible only to you.
                    </p>
                </div>

                <div class="space-y-2">
                    <label class="sr-only" for="transaction-note">Note</label>
                    <textarea
                        wire:model="note"
                        id="transaction-note"
                        rows="3"
                        placeholder="Add a note…"
                        class="w-full rounded-md border border-slate-200 bg-white px-3 py-2 text-sm text-slate-900 shadow-sm focus:border-slate-400 focus:outline-none focus:ring-1 focus:ring-slate-400 dark:bg-slate-950 dark:text-slate-100 dark:border-slate-700"
                        data-testid="note-textarea"
                    ></textarea>

                    <div class="flex items-center gap-3">
                        <button
                            type="button"
                            wire:click="saveNote"
                            class="rounded-md bg-slate-900 px-3 py-1.5 text-sm font-medium text-white shadow-sm hover:bg-slate-800 dark:bg-slate-100 dark:text-slate-900 dark:hover:bg-slate-200"
                            data-testid="note-save-button"
                        >
                            Save note
                        </button>

                        @if ($noteSaved)
                            <span
                                class="text-sm text-emerald-600 dark:text-emerald-400"
                                aria-live="polite" aria-atomic="true"
                                data-testid="note-saved-indicator"
                            >Saved</span>
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
                            Reassign counterparty
                        </h2>
                        <p class="text-sm text-slate-500 dark:text-slate-400">
                            Override the resolved counterparty for this transaction.
                        </p>
                    </div>

                    <div class="flex items-center gap-3">
                        <label class="sr-only" for="counterparty-select">Choose counterparty</label>
                        <select
                            id="counterparty-select"
                            x-model="selectedCp"
                            class="rounded-md border border-slate-200 bg-white px-3 py-1.5 text-sm text-slate-900 shadow-sm focus:border-slate-400 focus:outline-none focus:ring-1 focus:ring-slate-400 dark:bg-slate-950 dark:text-slate-100 dark:border-slate-700"
                            data-testid="counterparty-select"
                        >
                            <option value="">Choose a counterparty…</option>
                            @foreach ($counterparties as $cp)
                                <option value="{{ $cp->id }}"
                                    {{ $transaction->counterparty_id == $cp->id ? 'selected' : '' }}
                                >{{ $cp->display_name }}</option>
                            @endforeach
                        </select>

                        <button
                            type="button"
                            :disabled="!selectedCp"
                            @click="$wire.reassignCounterparty(Number(selectedCp))"
                            class="rounded-md bg-slate-900 px-3 py-1.5 text-sm font-medium text-white shadow-sm hover:bg-slate-800 disabled:cursor-not-allowed disabled:bg-slate-300 dark:bg-slate-100 dark:text-slate-900"
                            data-testid="counterparty-reassign-button"
                        >
                            Reassign
                        </button>
                    </div>
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
                        Delete transaction
                    </h2>
                    <p class="text-sm text-slate-500 dark:text-slate-400">
                        Permanently removes this transaction. This action cannot be undone.
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
                        Delete
                    </button>

                    <template x-if="confirmDelete">
                        <div class="flex items-center gap-3">
                            <span class="text-sm text-slate-700 dark:text-slate-300">Are you sure?</span>
                            <button
                                type="button"
                                wire:click="deleteTransaction"
                                class="rounded-md bg-rose-700 px-3 py-1.5 text-sm font-medium text-white shadow-sm hover:bg-rose-800"
                                data-testid="delete-confirm-button"
                            >
                                Yes, delete
                            </button>
                            <button
                                type="button"
                                @click="confirmDelete = false"
                                class="text-sm text-slate-500 hover:text-slate-700 dark:text-slate-400"
                            >
                                Cancel
                            </button>
                        </div>
                    </template>
                </div>
            </section>

            @if (($chainAvailable ?? false) === true)
                {{-- UI-02 / CHN-04: "View chain" trigger opens the chain
                     drill-down drawer (D-90, first Flux flyout in the
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
                        View chain
                    </button>
                </section>

                @livewire('chains.chain-drawer')
            @endif
        </div>
    </main>
</div>
