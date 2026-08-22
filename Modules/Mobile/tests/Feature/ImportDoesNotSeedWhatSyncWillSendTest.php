<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Mobile\Internal\Http\Livewire\MobileImportBootstrap;
use Modules\Mobile\Internal\Http\Livewire\MobilePairingScan;
use Modules\Mobile\Internal\Http\Middleware\MobileEnsureImportCompleted;

// `tax_deduction_categories` is a synced table, and the phone that joins an
// account is deliberately epoch-less, so PreSyncHistoryCapture::holdsNoEpoch()
// short-circuits and nothing this device seeds is ever pushed. The desktop's
// rows arrive through OpLogEntryApplier under the op's OWN primary key with
// insertOrIgnore, so whatever already sits at that id wins in silence.

function joinAnAccountFromTheImportScreen(string $country): User
{
    Livewire::test(MobileImportBootstrap::class)
        ->set('username', 'phone-joiner')
        ->set('password', 'a-genuinely-long-password')
        ->set('passwordConfirmation', 'a-genuinely-long-password')
        ->set('pin', '426900')
        ->set('confirmPin', '426900')
        ->set('country', $country)
        ->call('submit')
        ->assertSet('step', 'recovery_codes');

    return User::query()->firstOrFail();
}

function importingPairingScreen(User $user): Testable
{
    test()->actingAs($user);
    app()->instance(Request::class, Request::create('/mobile/pair', 'GET', ['mode' => 'import']));

    return Livewire::test(MobilePairingScan::class)->assertSet('importing', true);
}

function deductionCategoryNames(int $userId): array
{
    return DB::table('tax_deduction_categories')
        ->where('user_id', $userId)
        ->orderBy('id')
        ->pluck('name')
        ->all();
}

// End state 1 of 3: pairing completes. The desktop's own rows are what this
// device must end up with, renames and all.
it('leaves the ids free for the rows the desktop is about to send', function (): void {
    $user = joinAnAccountFromTheImportScreen('nl');

    expect(deductionCategoryNames($user->id))->toBe([]);

    // Exactly what OpLogEntryApplier::insertCreatedRow() does with a create
    // op: the op's own pk, and insertOrIgnore, so a local row already sitting
    // at that id is never overwritten and never reported.
    $now = Carbon::now()->toDateTimeString();
    DB::table('tax_deduction_categories')->insertOrIgnore([
        'id' => 1,
        'user_id' => $user->id,
        'name' => 'Zorgkosten 2024',
        'short_name' => 'Zorg',
        'hint' => null,
        'corpus_key' => 'nl_zorgkosten',
        'country_code' => 'nl',
        'status' => 'active',
        'sort_order' => 0,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    expect(deductionCategoryNames($user->id))->toBe(['Zorgkosten 2024']);
});

// End state 2 of 3: pairing is abandoned. Nothing will ever arrive over sync
// now, so the corpus the import path withheld has to be made good — the same
// gap abandonImport() already heals for the categorization rules.
it('seeds the country corpus when the reader gives up on pairing', function (): void {
    $user = joinAnAccountFromTheImportScreen('nl');

    expect(deductionCategoryNames($user->id))->toBe([]);

    importingPairingScreen($user)->call('abandonImport');

    expect(DB::table('tax_deduction_categories')
        ->where('user_id', $user->id)
        ->where('country_code', 'nl')
        ->count())->toBeGreaterThan(0);
});

// Not choosing a country is a real answer, and the exit must not invent one.
it('seeds nothing on the way out when the phone owner skipped the picker', function (): void {
    $user = joinAnAccountFromTheImportScreen('');

    importingPairingScreen($user)->call('abandonImport');

    expect(deductionCategoryNames($user->id))->toBe([]);
});

// End state 3 of 3: the ceremony is never finished and the app is closed. The
// missing corpus is unobservable because MobileEnsureImportCompleted returns
// every gated route to the pairing screen while the marker stands.
it('holds the reader on the pairing screen rather than showing an empty corpus', function (): void {
    $user = joinAnAccountFromTheImportScreen('nl');

    Route::middleware(['web', 'auth', MobileEnsureImportCompleted::class])
        ->get('/__test/unfinished-import', static fn (): string => 'REACHED-THE-APP')
        ->name('unfinished-import-probe');

    test()->actingAs($user)
        ->get('/__test/unfinished-import')
        ->assertRedirect(route('mobile.pair', ['mode' => 'import']));

    expect(DB::table('users')->where('id', $user->id)->value('country_code'))->toBe('nl')
        ->and(deductionCategoryNames($user->id))->toBe([]);
});
