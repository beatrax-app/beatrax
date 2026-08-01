@use('Modules\Core\Public\Support\Lang')
{{--
    Budgets step (optional, D-24) — assign this month's money per expense
    category before finishing setup. Reuses the wizard chrome (wiz-eyebrow /
    wiz-h1 / wiz-lede / wiz-actions). Each category row binds an amount to
    `amounts.<id>`; Continue persists the non-empty ones as month-1
    envelope_assignments rows through the ownership-checked EnvelopeWriter,
    Skip bubbles `wizard.step.skipped`. Blade default `{{ }}` escaping
    throughout.
--}}
<section class="wiz-step wiz-step-budgets" aria-labelledby="wiz-budgets-h1">
    <p class="wiz-eyebrow">{{ Lang::get('onboarding::budgets.eyebrow') }}</p>
    {{-- {!! !!}: app-static heading; the straight apostrophe must render
         literally (not &#039;) so the wizard copy stays verbatim. --}}
    <h1 id="wiz-budgets-h1" class="wiz-h1">{!! Lang::get('onboarding::budgets.h1') !!}</h1>
    <p class="wiz-lede">
        {{ Lang::get('onboarding::budgets.lede') }}
    </p>

    @if (count($categories) === 0)
        <p class="wiz-lede">
            {{ Lang::get('onboarding::budgets.empty') }}
        </p>
    @else
        <div class="budget-step-list" role="group" aria-label="{{ Lang::get('onboarding::budgets.list_aria') }}">
            <ul role="list">
                @foreach ($categories as $id => $name)
                    <li>
                        <label for="budget-{{ $id }}">{{ $name }}</label>
                        <span class="budget-step-amount">
                            <span aria-hidden="true">€</span>
                            <input
                                id="budget-{{ $id }}"
                                type="text"
                                inputmode="decimal"
                                placeholder="0.00"
                                wire:model="amounts.{{ $id }}"
                                aria-label="{{ Lang::get('onboarding::budgets.row_aria', ['name' => $name]) }}"
                            >
                        </span>
                    </li>
                @endforeach
            </ul>
        </div>
    @endif

    <x-onboarding::wiz-actions>
        <button type="button" class="pill-btn-ghost" wire:click="skip">
            {{ Lang::get('onboarding::budgets.skip') }}
        </button>
        <button type="button" class="pill-btn-primary" wire:click="continue">
            {{ Lang::get('onboarding::budgets.continue') }}
        </button>
    </x-onboarding::wiz-actions>
</section>
