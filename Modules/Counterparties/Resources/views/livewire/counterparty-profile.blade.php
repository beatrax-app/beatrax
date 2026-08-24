@use('Modules\Core\Public\Navigation\Destination')
@use('Modules\Core\Public\Support\Lang')
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

    All copy is verbatim from 17-UI-SPEC.md.
--}}
@use('Modules\Counterparties\Public\Enums\CounterpartyType')
@use('Modules\Ledger\Public\Services\BaseCurrency')
@use('Modules\Ledger\Public\ValueObjects\Money')
@php
    $isSelf = $profile->type === CounterpartyType::SelfAccount->value;
@endphp

<div style="padding: var(--space-6) var(--space-4); max-width: 980px; margin: 0 auto;" class="space-y-6">
    {{-- Tax tag picker — rendered once per profile (not per row). --}}
    @include('tax::components.tax-tag-popover')
    {{-- Mobile top bar back affordance: shown at <1024px with ← to /counterparties.
         Must live INSIDE the root div — Livewire allows only one root element. --}}
    <x-core::mobile-top-bar
        :backUrl="Destination::Counterparties->url()"
        :title="$isSelf ? ($profile->displayName ?? Lang::get('counterparties::profile.fallback_account')) : ($profile->displayName ?? Lang::get('counterparties::profile.fallback_counterparty'))"
    />
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
                {{ Lang::get('counterparties::profile.edit_display_name') }}
            </span>
        </header>

        {{-- Hero stats strip: single-column at phone width, auto-fit at >=768px --}}
        <section style="display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: var(--space-4);"
                 class="cp-profile-hero-stats">
            <div class="frame frame-tight">
                <div style="font-size: var(--text-xs); color: var(--color-text-muted); text-transform: uppercase; letter-spacing: 0.05em;">
                    @if ($profile->type === CounterpartyType::Personal->value){{ Lang::get('counterparties::profile.hero_net_received') }}@else{{ Lang::get('counterparties::profile.hero_12mo_total') }}@endif
                </div>
                <div style="font-size: var(--text-2xl); font-weight: 600; color: var(--color-text); font-variant-numeric: tabular-nums;">
                    {{ Money::ofMinor(abs($profile->total12mMinor), $profile->currency)->format() }}
                </div>
            </div>
            <div class="frame frame-tight">
                <div style="font-size: var(--text-xs); color: var(--color-text-muted); text-transform: uppercase; letter-spacing: 0.05em;">
                    {{ Lang::get('counterparties::profile.hero_transactions') }}
                </div>
                <div style="font-size: var(--text-2xl); font-weight: 600; color: var(--color-text); font-variant-numeric: tabular-nums;">
                    {{ $profile->transactionCount }}
                </div>
            </div>
            @if ($profile->firstSeenDate !== null)
                <div class="frame frame-tight">
                    <div style="font-size: var(--text-xs); color: var(--color-text-muted); text-transform: uppercase; letter-spacing: 0.05em;">
                        {{ Lang::get('counterparties::profile.hero_first_seen') }}
                    </div>
                    <div style="font-size: var(--text-base); font-weight: 600; color: var(--color-text); font-variant-numeric: tabular-nums;">
                        {{ $profile->firstSeenDate }}
                    </div>
                </div>
            @endif
        </section>

        {{-- Tab bar — varies per type; horizontal scroll at phone width so no tabs clip --}}
        @php
            $tabBars = [
                CounterpartyType::Merchant->value => [
                    ['key' => 'overview', 'label' => Lang::get('counterparties::profile.tabs.overview')],
                    ['key' => 'transactions', 'label' => Lang::get('counterparties::profile.tabs.transactions')],
                    ['key' => 'chains', 'label' => Lang::get('counterparties::profile.tabs.chains')],
                    ['key' => 'aliases', 'label' => Lang::get('counterparties::profile.tabs.aliases')],
                ],
                CounterpartyType::Personal->value => [
                    ['key' => 'overview', 'label' => Lang::get('counterparties::profile.tabs.overview')],
                    ['key' => 'transfers', 'label' => Lang::get('counterparties::profile.tabs.transfers')],
                    ['key' => 'aliases', 'label' => Lang::get('counterparties::profile.tabs.aliases')],
                ],
                CounterpartyType::Bank->value => [
                    ['key' => 'overview', 'label' => Lang::get('counterparties::profile.tabs.overview')],
                    ['key' => 'entries', 'label' => Lang::get('counterparties::profile.tabs.entries')],
                    ['key' => 'aliases', 'label' => Lang::get('counterparties::profile.tabs.aliases')],
                ],
                CounterpartyType::Government->value => [
                    ['key' => 'overview', 'label' => Lang::get('counterparties::profile.tabs.overview')],
                    ['key' => 'payments', 'label' => Lang::get('counterparties::profile.tabs.payments')],
                    ['key' => 'tax-years', 'label' => Lang::get('counterparties::profile.tabs.tax_years')],
                    ['key' => 'aliases', 'label' => Lang::get('counterparties::profile.tabs.aliases')],
                ],
                CounterpartyType::Unknown->value => [
                    ['key' => 'overview', 'label' => Lang::get('counterparties::profile.tabs.overview')],
                    ['key' => 'transactions', 'label' => Lang::get('counterparties::profile.tabs.transactions')],
                    ['key' => 'aliases', 'label' => Lang::get('counterparties::profile.tabs.aliases')],
                ],
            ];
            $tabs = $tabBars[$profile->type] ?? $tabBars[CounterpartyType::Unknown->value];
            $tabNote = match ($profile->type) {
                CounterpartyType::Personal->value => Lang::get('counterparties::profile.tab_note_personal'),
                CounterpartyType::Bank->value => Lang::get('counterparties::profile.tab_note_bank'),
                CounterpartyType::Government->value => Lang::get('counterparties::profile.tab_note_government'),
                default => null,
            };
        @endphp
        {{-- overflow-x: auto ensures the tab bar scrolls at phone width rather than clipping --}}
        {{-- The strip carried no tab semantics at all, so a reader heard
             "button" four times and never which one was showing. The tablist and
             its name stay here because this is what knows the tabs; x-core::tab
             brings role and aria-selected, and its slate values are the same
             pair --color-text already resolved to. --}}
        <nav
            style="border-bottom: 1px solid var(--color-border); display: flex; align-items: center; gap: 0; overflow-x: auto; -webkit-overflow-scrolling: touch;"
            role="tablist"
            aria-label="{{ Lang::get('counterparties::profile.tablist_aria') }}"
            x-data="tabStrip()"
            x-on:keydown="onKey($event)"
        >
            @foreach ($tabs as $tab)
                <x-core::tab
                    :active="$activeTab === $tab['key']"
                    class="shrink-0 whitespace-nowrap"
                    id="counterparty-tab-{{ $tab['key'] }}"
                    aria-controls="counterparty-tab-panel"
                    tabindex="{{ $activeTab === $tab['key'] ? '0' : '-1' }}"
                    wire:click="switchTab('{{ $tab['key'] }}')"
                >{{ $tab['label'] }}</x-core::tab>
            @endforeach
            @if ($tabNote !== null)
                <span style="font-size: var(--text-xs); color: var(--color-text-faint); margin-left: var(--space-3); white-space: nowrap; flex-shrink: 0;">
                    {{ $tabNote }}
                </span>
            @endif
        </nav>

        {{-- Per-type body partial. One panel for every tab: only the selected
             tab's partial is included, so the panel is named by that tab. --}}
        <div id="counterparty-tab-panel" role="tabpanel" aria-labelledby="counterparty-tab-{{ $activeTab }}">
            @include($partial, [
                'profile' => $profile,
                'recentActivity' => $recentActivity,
                'categoryBreakdown' => $categoryBreakdown,
                'fundingChain' => $fundingChain,
                'taxYears' => $taxYears,
                'recurringSeries' => $recurringSeries ?? [],
                'taxState' => $taxState ?? [],
            ])

            @if ($activeTab === 'overview' && $supportResource !== null && $supportResource->hasAny())
                @include('counterparties::livewire.profile-tabs.partials.support-resources', [
                    'resource' => $supportResource,
                ])
            @endif
        </div>
    @endif
</div>
