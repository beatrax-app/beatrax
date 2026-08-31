<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Ledger\Models\Account;
use Modules\Onboarding\Internal\Http\Livewire\SetupWizard;
use Modules\Onboarding\Internal\Http\Livewire\StartingBalanceCard;

uses(RefreshDatabase::class);

// The card is mounted per account inside the first-import step, which needs a
// previewed import to reach. Its own rendered snapshot is the same one the
// browser would hold there, and it is what the update endpoint verifies.
function startingBalanceSnapshotOf(string $html): string
{
    if (preg_match('/wire:snapshot="([^"]*)"/', $html, $match) !== 1) {
        throw new RuntimeException('The rendered component carries no wire:snapshot.');
    }

    return html_entity_decode($match[1], ENT_QUOTES);
}

/**
 * @param  array<string, mixed>  $updates
 */
function startingBalanceTamper(string $snapshot, array $updates): TestResponse
{
    return test()->withHeaders(['X-Livewire' => 'true'])->postJson(route('default-livewire.update'), [
        '_token' => csrf_token(),
        'components' => [[
            'snapshot' => $snapshot,
            'updates' => $updates,
            'calls' => [],
        ]],
    ]);
}

beforeEach(function (): void {
    $this->user = User::query()->create([
        'username' => 'starting-balance-denomination',
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
    ]);
    $this->actingAs($this->user);

    /** @var Account $account */
    $account = Account::query()->create([
        'user_id' => $this->user->id,
        'name' => 'ASN account',
        'slug' => 'starting-balance-denomination-asn',
        'kind' => 'bank',
        'iban' => 'NL03ASNB0123450003',
        'default_currency' => 'EUR',
    ]);

    $this->snapshot = startingBalanceSnapshotOf(Livewire::test(StartingBalanceCard::class, [
        'accountId' => $account->id,
        'accountLabel' => 'ASN account',
        'accountShort' => 'ASN',
        'detectedMinor' => 12_345,
        'detectedDate' => '2026-01-15',
    ])->html());
});

it('refuses a denomination the payload chose for the card however the bundle was built', function (bool $debug): void {
    config()->set('app.debug', $debug);

    startingBalanceTamper($this->snapshot, [
        'state' => 'detected',
        'detectedMinor' => 100,
        'currency' => 'ZZZ',
    ])->assertForbidden();
})->with([
    'debug build' => [true],
    'production build' => [false],
]);

it('leaves the two boxes the edit form is bound to writable', function (): void {
    startingBalanceTamper($this->snapshot, ['editedMinor' => 250, 'editedDate' => '2026-01-16'])->assertOk();
});

it('throws rather than accepting a write to the denomination', function (): void {
    Livewire::test(StartingBalanceCard::class, [
        'accountId' => 1,
        'accountLabel' => 'ASN account',
        'accountShort' => 'ASN',
    ])->set('currency', 'ZZZ');
})->throws(CannotUpdateLockedPropertyException::class);

// mount() does not run on an update, so the progress map's "rebuilt on every
// mount" was never a defence. The strip reads $progress[$key]['status'], which
// is a subscript on a string when a step's slot holds one.
it('refuses a wizard progress map whose steps are not steps however the bundle was built', function (bool $debug): void {
    config()->set('app.debug', $debug);

    $snapshot = startingBalanceSnapshotOf($this->get('/setup-wizard')->assertOk()->getContent());

    startingBalanceTamper($snapshot, ['progress' => ['welcome' => 'zzz']])->assertForbidden();
})->with([
    'debug build' => [true],
    'production build' => [false],
]);

it('throws rather than accepting a write to the wizard progress map', function (): void {
    Livewire::test(SetupWizard::class)->set('progress', ['welcome' => 'zzz']);
})->throws(CannotUpdateLockedPropertyException::class);

// The pair the detector found and the conflict list beside it. confirm() and
// pickConflictCandidate() dispatch straight to the writer, so the browser
// choosing either would choose the opening balance itself.
it('throws rather than accepting a write to the figures the detector found', function (string $property, mixed $value): void {
    Livewire::test(StartingBalanceCard::class, [
        'accountId' => 1,
        'accountLabel' => 'ASN account',
        'accountShort' => 'ASN',
    ])->set($property, $value);
})->with([
    'the amount' => ['detectedMinor', PHP_INT_MAX],
    'the date' => ['detectedDate', '2099-01-01'],
    'the conflict list' => ['alternativeCandidates', [['minor' => 1, 'date' => '2099-01-01', 'sourceLabel' => 'x']]],
])->throws(CannotUpdateLockedPropertyException::class);
