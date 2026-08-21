<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Livewire\Livewire;
use Modules\Auth\Internal\Http\Livewire\SignupPage;
use Modules\Core\Models\User;
use Modules\Core\Public\Events\UserCountryChanged;
use Modules\Core\Public\Services\UserCountry;
use Modules\Shell\Internal\Http\Livewire\SettingsPage;
use Modules\Tax\Internal\Http\Livewire\TaxPage;

uses(RefreshDatabase::class);

// The country is a preference; the recovery codes are the only way back into
// an account whose passphrase is gone. Nothing about the first may cost the
// second, and a country that could not be seeded must not read as one that was.

const SIGNUP_CODES_SESSION_KEY = 'auth.signup.recovery_codes_plain';

// Registered after the Tax listener, so the corpus has already inserted its
// rows when this throws — which is what makes the rollback assertions real.
function breakTheCountrySeed(): void
{
    Event::listen(UserCountryChanged::class, static function (): void {
        throw new RuntimeException('the corpus could not be read');
    });
}

it('still shows the recovery codes when the country seed throws', function (): void {
    breakTheCountrySeed();

    Livewire::test(SignupPage::class)
        ->set('username', 'wessel')
        ->set('password', 'opensesame-long-enough')
        ->set('passwordConfirmation', 'opensesame-long-enough')
        ->set('country', 'nl')
        ->call('submit')
        ->assertRedirect(route('auth.recovery-codes-display'));

    expect(User::query()->count())->toBe(1)
        ->and(session(SIGNUP_CODES_SESSION_KEY))->toHaveCount(10);
});

// A country written anyway is the worse half: TaxPage reads it as "set up",
// suppresses the guided prompt, and leaves the reader with no way back to it.
it('leaves no country behind when the seed that follows it throws', function (): void {
    breakTheCountrySeed();

    Livewire::test(SignupPage::class)
        ->set('username', 'wessel')
        ->set('password', 'opensesame-long-enough')
        ->set('passwordConfirmation', 'opensesame-long-enough')
        ->set('country', 'nl')
        ->call('submit');

    $user = User::query()->firstOrFail();

    expect(DB::table('users')->where('id', $user->id)->value('country_code'))->toBeNull()
        ->and(DB::table('tax_deduction_categories')->where('user_id', $user->id)->count())->toBe(0);

    $this->actingAs($user->fresh());

    Livewire::test(TaxPage::class)->assertSee('Which country do you file taxes in?');
});

// Same seam, reached from Settings: the picker must not report success and
// leave the reader on an install with a country and no categories.
it('rolls the settings write back when the seed throws', function (): void {
    $user = User::query()->create([
        'username' => 'settings-country-user',
        'password' => 'opensesame-long-enough',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
    $this->actingAs($user);

    breakTheCountrySeed();

    try {
        Livewire::test(SettingsPage::class)->call('setCountry', 'nl');
    } catch (RuntimeException) {
        // The throw is the fixture, not the assertion.
    }

    expect(app(UserCountry::class)->current($user->id))->toBe('')
        ->and(DB::table('tax_deduction_categories')->where('user_id', $user->id)->count())->toBe(0);
});
