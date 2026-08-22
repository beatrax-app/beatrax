<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Core\Public\Events\UserInstalled;
use Modules\Mobile\Internal\Http\Livewire\MobilePairingScan;
use Modules\Mobile\Internal\Http\Middleware\MobileEnsureImportCompleted;
use Modules\Mobile\Internal\Sync\MobileImportIntentGate;
use Modules\Sync\Public\Enums\PairingWizardStep;

uses(RefreshDatabase::class);

// iphone-02. "Import from another device" landed on a pairing screen with two
// controls and no exit, and every gated route — dashboard, transactions,
// budgets, settings — was returned to it. The choice could only be undone by
// reinstalling the app.

function wayOutUser(string $prefix): User
{
    return User::query()->create([
        'username' => $prefix.'-'.bin2hex(random_bytes(4)),
        'password' => 'fixture',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
}

function wayOutGatedRoute(): void
{
    Route::middleware(['web', 'auth', MobileEnsureImportCompleted::class])
        ->get('/__test/way-out-gated', static fn (): string => 'REACHED-THE-APP')
        ->name('way-out-probe');
}

function wayOutImportingScreen(User $user): Testable
{
    test()->actingAs($user);
    app()->instance(Request::class, Request::create('/mobile/pair', 'GET', ['mode' => 'import']));

    return Livewire::test(MobilePairingScan::class)->assertSet('importing', true);
}

it('holds every gated route on the pairing screen until the exit is taken', function (): void {
    $user = wayOutUser('wayout');
    wayOutGatedRoute();

    $screen = wayOutImportingScreen($user);

    test()->actingAs($user)
        ->get('/__test/way-out-gated')
        ->assertRedirect(route('mobile.pair', ['mode' => 'import']));

    $screen->call('abandonImport')->assertRedirect(route('dashboard'));

    test()->actingAs($user)
        ->get('/__test/way-out-gated')
        ->assertOk()
        ->assertSee('REACHED-THE-APP');
});

// Retiring the marker is the gate's own convergence move, so the exempt list is
// untouched: a device that has NOT taken the exit is held exactly as before.
it('leaves the gate holding a device that never took the exit', function (): void {
    $stuck = wayOutUser('wayoutstuck');
    app(MobileImportIntentGate::class)->markImporting($stuck->id);
    wayOutGatedRoute();

    test()->actingAs($stuck)
        ->get('/__test/way-out-gated')
        ->assertRedirect(route('mobile.pair', ['mode' => 'import']));

    expect(app(MobileImportIntentGate::class)->isImporting($stuck->id))->toBeTrue();
});

it('offers the exit on the camera arm as well as the keypad', function (): void {
    $user = wayOutUser('wayoutarms');

    $screen = wayOutImportingScreen($user);

    // With no native scanner the component lands on enter_code, which is also
    // the only arm a phone whose camera is refused can ever reach.
    $screen->assertSet('step', PairingWizardStep::EnterCode->value)
        ->assertSeeHtml('wire:click="abandonImport"');

    $screen->set('step', PairingWizardStep::Scan->value)
        ->assertSeeHtml('wire:click="abandonImport"');
});

it('does not offer the import exit to a device that is not importing', function (): void {
    $user = wayOutUser('wayoutplain');
    test()->actingAs($user);
    app()->instance(Request::class, Request::create('/mobile/pair', 'GET'));

    Livewire::test(MobilePairingScan::class)
        ->assertSet('importing', false)
        ->assertDontSeeHtml('wire:click="abandonImport"')
        // The camera arm carried no way back either, and layouts.lock draws no
        // navigation, so leaving it meant killing the app.
        ->set('step', PairingWizardStep::Scan->value)
        ->assertSeeHtml('wire:click="cancelPairing"');
});

// The import path signs up with seedsStarterData: false because those rules
// were to arrive over sync. Abandoning means nothing ever will, and the reader
// is left with an account quietly poorer than a plain signup's.
it('seeds the starter data the import path withheld', function (): void {
    $user = wayOutUser('wayoutseed');

    $screen = wayOutImportingScreen($user);

    expect(DB::table('categorization_rules')->where('user_id', $user->id)->count())->toBe(0);

    $screen->call('abandonImport');

    expect(DB::table('categorization_rules')->where('user_id', $user->id)->count())
        ->toBeGreaterThan(0);
});

it('asks for the starter data rather than skipping it, as the import signup did', function (): void {
    $user = wayOutUser('wayoutevent');

    $seeded = [];
    app('events')->listen(UserInstalled::class, function (UserInstalled $event) use (&$seeded): void {
        $seeded[] = $event->seedsStarterData;
    });

    wayOutImportingScreen($user)->call('abandonImport');

    expect($seeded)->toBe([true]);
});
