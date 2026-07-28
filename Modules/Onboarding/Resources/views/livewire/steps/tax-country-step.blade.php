{{--
    Tax country step (optional) — pick the country you file taxes in so the
    per-country deduction categories are seeded before finishing setup.
    Reuses the wizard chrome (wiz-eyebrow / wiz-h1 / wiz-lede / wiz-actions).
    The select binds `taxCountryCode` live so the additive-seed reassurance
    note appears as soon as a country is chosen; Continue persists through
    the Tax module's public TaxCountrySetup surface, Skip bubbles
    `wizard.step.skipped`. Blade default {{ }} escaping throughout.
--}}
<section class="wiz-step wiz-step-tax-country" aria-labelledby="wiz-tax-country-h1">
    <p class="wiz-eyebrow">Optional</p>
    <h1 id="wiz-tax-country-h1" class="wiz-h1">Pick your tax country</h1>
    <p class="wiz-lede">
        Sets which deduction categories are available when you tag
        transactions as tax-deductible. Pick the country you file in —
        or skip and set it anytime in Settings.
    </p>

    <div class="space-y-2">
        <label for="wiz-tax-country-select" class="sr-only">Tax country</label>
        <select
            id="wiz-tax-country-select"
            wire:model.live="taxCountryCode"
            class="w-full rounded-md border border-slate-200 bg-white px-3 py-2 text-sm text-slate-900 shadow-sm focus:outline-none focus:ring-2 focus:ring-slate-900 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-100"
            data-testid="wiz-tax-country-select"
        >
            <option value="">Choose a country…</option>
            @foreach ($countries as $code => $label)
                <option value="{{ $code }}">{{ $label }}</option>
            @endforeach
        </select>
        @if ($taxCountryCode !== '')
            <p class="text-xs text-[var(--color-amber)]">
                Switching adds new categories — existing tags are never changed.
            </p>
        @endif
    </div>

    <x-onboarding::wiz-actions>
        <button type="button" class="pill-btn-ghost" wire:click="skip">
            Skip for now
        </button>
        <button type="button" class="pill-btn-primary" wire:click="continue">
            Continue →
        </button>
    </x-onboarding::wiz-actions>
</section>
