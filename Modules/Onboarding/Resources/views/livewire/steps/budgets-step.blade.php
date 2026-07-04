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
    <p class="wiz-eyebrow">Optional</p>
    <h1 id="wiz-budgets-h1" class="wiz-h1">Assign this month's money</h1>
    <p class="wiz-lede">
        Put this month's money where it needs to go. Fill in the categories
        you care about — leave the rest for later. You can change these
        anytime on the Budgets page.
    </p>

    @if (count($categories) === 0)
        <p class="wiz-lede">
            No expense categories yet — you can assign money later from the
            Budgets page.
        </p>
    @else
        <div class="budget-step-list" role="group" aria-label="Monthly category budgets">
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
                                aria-label="Monthly budget for {{ $name }}"
                            >
                        </span>
                    </li>
                @endforeach
            </ul>
        </div>
    @endif

    <x-onboarding::wiz-actions>
        <button type="button" class="pill-btn-ghost" wire:click="skip">
            Skip for now
        </button>
        <button type="button" class="pill-btn-primary" wire:click="continue">
            Continue →
        </button>
    </x-onboarding::wiz-actions>
</section>
