@use('Modules\Core\Public\Support\Lang')
{{--
    Country step (optional) — the same preference the signup screen and
    Settings offer, asked once more here so the per-country deduction
    categories are seeded before setup finishes.
    Reuses the wizard chrome (wiz-eyebrow / wiz-h1 / wiz-lede / wiz-actions).
    The select binds `taxCountryCode` live so the additive-seed reassurance
    note appears as soon as a country is chosen; Continue persists through
    Core's UserCountry seam, Skip bubbles `wizard.step.skipped`.
    Blade default {{ }} escaping throughout.
--}}
<section class="wiz-step wiz-step-tax-country" aria-labelledby="wiz-tax-country-h1">
    <p class="wiz-eyebrow">{{ Lang::get('onboarding::tax_country.eyebrow') }}</p>
    <h1 id="wiz-tax-country-h1" class="wiz-h1">{{ Lang::get('onboarding::tax_country.h1') }}</h1>
    <p class="wiz-lede">
        {{ Lang::get('onboarding::tax_country.lede') }}
    </p>

    <div class="space-y-2">
        <label for="wiz-tax-country-select" class="sr-only">{{ Lang::get('onboarding::tax_country.select_label') }}</label>
        <select
            id="wiz-tax-country-select"
            wire:model.live="taxCountryCode"
            class="w-full rounded-md border border-slate-200 bg-white px-3 py-2 text-sm text-slate-900 shadow-sm focus:outline-none focus:ring-2 focus:ring-slate-900 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-100"
            data-testid="wiz-tax-country-select"
        >
            <option value="">{{ Lang::get('onboarding::tax_country.select_placeholder') }}</option>
            @foreach ($countries as $code => $label)
                <option value="{{ $code }}">{{ $label }}</option>
            @endforeach
        </select>
        @if ($taxCountryCode !== '')
            <p class="text-xs text-[var(--color-amber)]">
                {{ Lang::get('onboarding::tax_country.additive_note') }}
            </p>
        @endif
    </div>

    <x-onboarding::wiz-actions>
        <button type="button" class="pill-btn-ghost" wire:click="skip">
            {{ Lang::get('onboarding::tax_country.skip') }}
        </button>
        <button type="button" class="pill-btn-primary" wire:click="continue">
            {{ Lang::get('onboarding::tax_country.continue') }}
        </button>
    </x-onboarding::wiz-actions>
</section>
