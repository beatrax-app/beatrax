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
    <main class="min-h-screen bg-white dark:bg-slate-950">
        <div class="mx-auto max-w-3xl px-8 py-12 space-y-6" data-testid="transaction-detail">
            <header class="space-y-1">
                <h1 class="text-2xl font-semibold tracking-tight text-slate-900 dark:text-slate-100">Transaction</h1>
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
                        @foreach (\Modules\Ledger\Models\Transaction::TYPES as $type)
                            @if ($type !== $transaction->type)
                                <option value="{{ $type }}">{{ $type }}</option>
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
                        role="status"
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
