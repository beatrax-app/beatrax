<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Onboarding\Internal\Http\Livewire\SetupWizard;
use Modules\Onboarding\Internal\Http\Livewire\Steps\TaxCountryStep;
use Modules\Onboarding\Internal\Services\WizardProgressInitializer;
use Modules\Onboarding\Internal\Services\WizardStepRegistry;

// Choosing a country writes users.tax_country_code AND seeds the
// per-country deduction categories; skipping writes nothing at all.

beforeEach(function (): void {
    $this->user = User::query()->create([
        'username' => 'tax-country-step',
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
    ]);
    $this->actingAs($this->user);

    /** @var WizardProgressInitializer $initializer */
    $initializer = $this->app->make(WizardProgressInitializer::class);
    $initializer->initialize($this->user->id);
});

it('registers tax-country in the step registry between budgets and done, and as skippable', function (): void {
    /** @var WizardStepRegistry $registry */
    $registry = $this->app->make(WizardStepRegistry::class);
    $steps = $registry->steps();

    $position = array_search('tax-country', $steps, strict: true);
    expect($position)->not->toBeFalse();
    expect($steps[$position - 1])->toBe('budgets');
    expect($steps[$position + 1])->toBe('done');

    expect($registry->isSkippable('tax-country'))->toBeTrue();
});

it('renders the tax-country step inside the wizard once every earlier step is complete', function (): void {
    DB::table('wizard_progress')
        ->where('user_id', $this->user->id)
        ->whereNotIn('step_key', ['tax-country', 'done'])
        ->update(['status' => 'done']);

    Livewire::test(SetupWizard::class)
        ->assertSet('currentStepKey', 'tax-country')
        ->assertSeeLivewire('onboarding.steps.tax-country-step');
});

it('renders the country choices with the settings-page labels', function (): void {
    Livewire::test(TaxCountryStep::class)
        ->assertOk()
        ->assertSee('Pick your tax country')
        ->assertSee('Netherlands')
        ->assertSee('United Kingdom');
});

it('persists tax_country_code and seeds the deduction categories on continue', function (): void {
    Livewire::test(TaxCountryStep::class)
        ->set('taxCountryCode', 'nl')
        ->call('continue')
        ->assertDispatched('wizard.step.completed');

    $countryCode = DB::table('users')->where('id', $this->user->id)->value('tax_country_code');
    expect($countryCode)->toBe('nl');

    $categoryCount = DB::table('tax_deduction_categories')
        ->where('user_id', $this->user->id)
        ->where('country_code', 'nl')
        ->count();
    expect($categoryCount)->toBeGreaterThan(0);
});

it('advances on continue without writing anything when no country is chosen', function (): void {
    Livewire::test(TaxCountryStep::class)
        ->call('continue')
        ->assertDispatched('wizard.step.completed');

    expect(DB::table('users')->where('id', $this->user->id)->value('tax_country_code'))->toBeNull();
    expect(DB::table('tax_deduction_categories')->where('user_id', $this->user->id)->count())->toBe(0);
});

it('advances without seeding on skip', function (): void {
    Livewire::test(TaxCountryStep::class)
        ->set('taxCountryCode', 'nl')
        ->call('skip')
        ->assertDispatched('wizard.step.skipped');

    expect(DB::table('users')->where('id', $this->user->id)->value('tax_country_code'))->toBeNull();
    expect(DB::table('tax_deduction_categories')->where('user_id', $this->user->id)->count())->toBe(0);
});

it('ignores a code outside the allow-list on continue (no write, still advances)', function (): void {
    Livewire::test(TaxCountryStep::class)
        ->set('taxCountryCode', 'xx')
        ->call('continue')
        ->assertDispatched('wizard.step.completed');

    expect(DB::table('users')->where('id', $this->user->id)->value('tax_country_code'))->toBeNull();
    expect(DB::table('tax_deduction_categories')->where('user_id', $this->user->id)->count())->toBe(0);
});

it('preselects the stored tax country when the wizard is re-run', function (): void {
    DB::table('users')->where('id', $this->user->id)->update(['tax_country_code' => 'de']);

    Livewire::test(TaxCountryStep::class)
        ->assertSet('taxCountryCode', 'de');
});
