{{--
    /counterparties/{slug} profile shell. Renders the type-aware tab
    bar + hero, then yields the body to the per-type partial selected
    by `CounterpartyProfile::render()`.

    Variables exposed by `CounterpartyProfile::render()`:
      $profile          CounterpartyProfileDto
      $partial          string  — view path to include for the body
      $recentActivity   Collection
      $categoryBreakdown Collection
      $fundingChain     ChainSummary|null
      $taxYears         Collection
      $activeTab        string
      $ibanRevealed     bool

    All copy is verbatim from 17-UI-SPEC.md.
--}}
@php
    use Illuminate\Support\Number;
    $isSelf = $profile->type === 'self_account';
@endphp

<div style="padding: var(--space-6) var(--space-4); max-width: 980px; margin: 0 auto;" class="space-y-6">
    @if ($isSelf)
        {{-- Self-account: stub redirect, no hero / no tabs --}}
        @include('counterparties::livewire.profile-tabs.self', ['profile' => $profile])
    @else
        {{-- Profile hero --------------------------------------------- --}}
        <header style="display: flex; align-items: center; gap: var(--space-4); flex-wrap: wrap;">
            <h1 style="font-size: var(--text-xl); font-weight: 600; color: var(--color-text); margin: 0;">
                {{ $profile->displayName }}
            </h1>
            <x-counterparties::type-chip :type="$profile->type" />
            <span style="font-size: var(--text-xs); color: var(--color-text-muted); margin-left: auto;">
                Edit display name
            </span>
        </header>

        {{-- Hero stats strip ---------------------------------------- --}}
        <section style="display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: var(--space-4);">
            <div class="frame frame-tight">
                <div style="font-size: var(--text-xs); color: var(--color-text-muted); text-transform: uppercase; letter-spacing: 0.05em;">
                    @if ($profile->type === 'personal') Net received @else 12-month total @endif
                </div>
                <div style="font-size: var(--text-2xl); font-weight: 600; color: var(--color-text); font-variant-numeric: tabular-nums;">
                    {{ Number::currency(abs($profile->total12mMinor) / 100, 'EUR', 'nl') }}
                </div>
            </div>
            <div class="frame frame-tight">
                <div style="font-size: var(--text-xs); color: var(--color-text-muted); text-transform: uppercase; letter-spacing: 0.05em;">
                    Transactions
                </div>
                <div style="font-size: var(--text-2xl); font-weight: 600; color: var(--color-text); font-variant-numeric: tabular-nums;">
                    {{ $profile->transactionCount }}
                </div>
            </div>
            @if ($profile->firstSeenDate !== null)
                <div class="frame frame-tight">
                    <div style="font-size: var(--text-xs); color: var(--color-text-muted); text-transform: uppercase; letter-spacing: 0.05em;">
                        First seen
                    </div>
                    <div style="font-size: var(--text-base); font-weight: 600; color: var(--color-text); font-variant-numeric: tabular-nums;">
                        {{ $profile->firstSeenDate }}
                    </div>
                </div>
            @endif
        </section>

        {{-- Tab bar — varies per type ------------------------------- --}}
        @php
            $tabBars = [
                'merchant' => [
                    ['key' => 'overview', 'label' => 'Overview'],
                    ['key' => 'transactions', 'label' => 'Transactions'],
                    ['key' => 'chains', 'label' => 'Chains'],
                    ['key' => 'aliases', 'label' => 'Aliases'],
                ],
                'personal' => [
                    ['key' => 'overview', 'label' => 'Overview'],
                    ['key' => 'transfers', 'label' => 'Transfers'],
                    ['key' => 'aliases', 'label' => 'Aliases'],
                ],
                'bank' => [
                    ['key' => 'overview', 'label' => 'Overview'],
                    ['key' => 'entries', 'label' => 'Entries'],
                    ['key' => 'aliases', 'label' => 'Aliases'],
                ],
                'government' => [
                    ['key' => 'overview', 'label' => 'Overview'],
                    ['key' => 'payments', 'label' => 'Payments'],
                    ['key' => 'tax-years', 'label' => 'Tax years'],
                    ['key' => 'aliases', 'label' => 'Aliases'],
                ],
                'unknown' => [
                    ['key' => 'overview', 'label' => 'Overview'],
                    ['key' => 'transactions', 'label' => 'Transactions'],
                    ['key' => 'aliases', 'label' => 'Aliases'],
                ],
            ];
            $tabs = $tabBars[$profile->type] ?? $tabBars['unknown'];
            $tabNote = match ($profile->type) {
                'personal' => '— no funding chains for personal contacts',
                'bank' => "— bank-fee counterparty doesn't generate funding chains",
                'government' => '— no funding chains for government counterparties',
                default => null,
            };
        @endphp
        <nav style="border-bottom: 1px solid var(--color-border); display: flex; align-items: center; gap: 0;">
            @foreach ($tabs as $tab)
                <button
                    type="button"
                    class="focus-visible:ring-2 focus-visible:ring-offset-2 focus-visible:ring-slate-900"
                    style="padding: 8px 14px; border: 0; background: transparent; font-size: var(--text-sm); font-weight: 500; color: {{ $activeTab === $tab['key'] ? 'var(--color-text)' : 'var(--color-text-muted)' }}; border-bottom: 1px solid {{ $activeTab === $tab['key'] ? 'var(--color-text)' : 'transparent' }}; cursor: pointer;"
                    wire:click="switchTab('{{ $tab['key'] }}')"
                >{{ $tab['label'] }}</button>
            @endforeach
            @if ($tabNote !== null)
                <span style="font-size: var(--text-xs); color: var(--color-text-faint); margin-left: var(--space-3);">
                    {{ $tabNote }}
                </span>
            @endif
        </nav>

        {{-- Per-type body partial ----------------------------------- --}}
        <div>
            @include($partial, [
                'profile' => $profile,
                'recentActivity' => $recentActivity,
                'categoryBreakdown' => $categoryBreakdown,
                'fundingChain' => $fundingChain,
                'taxYears' => $taxYears,
                'ibanRevealed' => $ibanRevealed,
            ])
        </div>
    @endif
</div>
