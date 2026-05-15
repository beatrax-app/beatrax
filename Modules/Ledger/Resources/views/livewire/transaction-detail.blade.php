@php
    use Carbon\CarbonImmutable;
    use Modules\Ledger\Public\ValueObjects\Money;

    // EUR amounts render in Dutch locale; non-EUR amounts in US English
    // locale so the symbol prefix matches the user's mental model.
    // brick/money routes the locale through ext-intl's NumberFormatter.
    $fmt = static fn (Money $money): string => $money->currency() === 'EUR'
        ? $money->format('nl_NL')
        : $money->format('en_US');
@endphp

<div>
    <main class="min-h-screen bg-white">
        <div class="mx-auto max-w-3xl px-8 py-12 space-y-6" data-testid="transaction-detail">
            <header class="space-y-1">
                <h1 class="text-2xl font-semibold tracking-tight text-slate-900">Transaction</h1>
                <p class="text-sm text-slate-500">
                    {{ CarbonImmutable::parse($transaction->posted_at)->format('j M Y') }}
                </p>
            </header>

            <dl class="grid grid-cols-1 gap-4 md:grid-cols-2">
                <div class="space-y-1">
                    <dt class="text-sm text-slate-500">Counterparty</dt>
                    <dd class="text-sm text-slate-900">{{ $transaction->counterparty_name ?? '—' }}</dd>
                </div>

                <div class="space-y-1">
                    <dt class="text-sm text-slate-500">Amount (native)</dt>
                    <dd class="text-sm text-slate-900" style="font-variant-numeric: tabular-nums;">
                        {{ $fmt(Money::ofMinor($transaction->amount_minor, $transaction->currency)) }} {{ $transaction->currency }}
                    </dd>
                </div>

                <div class="space-y-1">
                    <dt class="text-sm text-slate-500">Amount (settled EUR)</dt>
                    <dd class="text-sm text-slate-900" style="font-variant-numeric: tabular-nums;">
                        {{ $fmt(Money::ofMinor($transaction->settled_amount_minor, $transaction->settled_currency)) }} {{ $transaction->settled_currency }}
                    </dd>
                </div>

                @if ($transaction->fx_rate_used !== null)
                    <div class="space-y-1" data-testid="fx-rate-row">
                        <dt class="text-sm text-slate-500">Effective rate</dt>
                        <dd class="text-sm text-slate-900" style="font-variant-numeric: tabular-nums;">
                            €{{ number_format((float) $transaction->fx_rate_used, 3, '.', '') }} / {{ $transaction->currency }}
                        </dd>
                        <p class="text-xs text-slate-500">Includes any ICS markup.</p>
                    </div>
                @endif
            </dl>
        </div>
    </main>
</div>
