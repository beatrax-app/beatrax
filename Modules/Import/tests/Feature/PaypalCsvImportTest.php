<?php

declare(strict_types=1);

use Livewire\Livewire;
use Modules\Import\Internal\Http\Livewire\PreviewWizard;
use Modules\Import\Public\Contracts\RunsImports;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Models\Transaction;

/*
 * Wire-level coverage for the PayPal CSV import path:
 *  - end-to-end import of the redacted fixture — 41 canonical rows
 *    persist with the right native + settled pair shapes and
 *    source_format = 'paypal-csv'
 *  - idempotency on re-import (the IdempotencyContractTest dataset
 *    covers the contract; this test exists alongside it to anchor the
 *    PayPal-specific narrative)
 *  - "name your PayPal account" wizard branch fires when no PayPal
 *    Account exists for the user, hides once one is named
 */

beforeEach(function (): void {
    $this->seedFixtureUserAndAccount();
    $this->actingAs($this->fixtureUser);
    $this->importer = $this->app->make(RunsImports::class);
    $this->fixture = base_path('Modules/Ingestion/tests/fixtures/paypal/paypal-sample-1.csv');
});

it('imports the redacted fixture end-to-end with 41 canonical rows', function (): void {
    $result = $this->importer->runAndConfirm($this->fixture, 'paypal-csv', $this->fixtureUser);

    expect($result->inserted)->toBe(41);
    expect(Transaction::count())->toBe(41);
})->group('phase-4');

it('persists source_format = paypal-csv on every imported row', function (): void {
    $this->importer->runAndConfirm($this->fixture, 'paypal-csv', $this->fixtureUser);

    foreach (Transaction::all() as $row) {
        expect($row->source_format)->toBe('paypal-csv');
    }
})->group('phase-4');

it('persists source_ref as the parent Transaction ID on every imported row', function (): void {
    $this->importer->runAndConfirm($this->fixture, 'paypal-csv', $this->fixtureUser);

    foreach (Transaction::all() as $row) {
        expect($row->source_ref)->toBeString();
        expect($row->source_ref)->not->toBe('');
        // The redaction script pads Transaction IDs to `O-<17-digit-counter>`.
        expect((bool) preg_match('/^O-\d{17}$/', (string) $row->source_ref))->toBeTrue();
    }
})->group('phase-4');

it('persists rawPayload.format = paypal-csv and an events manifest per imported row', function (): void {
    $this->importer->runAndConfirm($this->fixture, 'paypal-csv', $this->fixtureUser);

    foreach (Transaction::all() as $row) {
        $payload = $row->raw_payload;
        expect($payload)->toBeArray();
        /** @var array<string, mixed> $payload */
        expect($payload['format'] ?? null)->toBe('paypal-csv');
        expect($payload)->toHaveKey('events');
        expect($payload['events'])->toBeArray();
    }
})->group('phase-4');

it('preserves the dual-amount pair for the Cloudflare USD row', function (): void {
    $this->importer->runAndConfirm($this->fixture, 'paypal-csv', $this->fixtureUser);

    /** @var Transaction|null $cloudflare */
    $cloudflare = Transaction::query()
        ->where('counterparty_name', 'Cloudflare Inc')
        ->where('currency', 'USD')
        ->where('amount_minor', -1046)
        ->first();

    expect($cloudflare)->not->toBeNull();
    /** @var Transaction $cloudflare */
    expect($cloudflare->currency)->toBe('USD');
    expect($cloudflare->amount_minor)->toBe(-1046);
    expect($cloudflare->settled_currency)->toBe('EUR');
    expect($cloudflare->settled_amount_minor)->toBe(-927);
})->group('phase-4');

it('returns zero new rows when re-importing the same PayPal CSV (idempotency)', function (): void {
    $first = $this->importer->runAndConfirm($this->fixture, 'paypal-csv', $this->fixtureUser);
    $second = $this->importer->runAndConfirm($this->fixture, 'paypal-csv', $this->fixtureUser);

    expect($first->inserted)->toBe(41);
    expect($second->inserted)->toBe(0);
    expect($second->duplicates)->toBe(41);
})->group('phase-4');

it('prompts the user to name the PayPal Account on the first PayPal upload', function (): void {
    // Remove the seeded PayPal Account so this run is the user's first
    // PayPal upload (the seedFixtureUserAndAccount() helper seeds a
    // PayPal account by default; the wizard-naming branch requires the
    // row to be absent).
    Account::query()
        ->where('user_id', $this->fixtureUser->id)
        ->where('kind', 'paypal')
        ->delete();

    $importer = $this->app->make(RunsImports::class);
    $preview = $importer->runFromUpload(
        $this->fixture,
        'paypal-csv',
        $this->fixtureUser,
        'paypal-sample-1.csv',
    );

    Livewire::test(PreviewWizard::class, ['id' => $preview->importRunId])
        ->assertSee('Name your PayPal account.', false)
        ->assertSee("first time you've imported PayPal data", false)
        ->assertSee('Save name', false)
        // The Confirm button lives in the page header and is always rendered;
        // the naming-step gate is enforced by the `disabled` attribute (UI)
        // and the server-side guard in PreviewWizard::confirm() (defense).
        ->assertSee('Confirm import', false)
        ->assertSeeHtmlInOrder(['wire:click="confirm"', 'disabled', 'Confirm import']);
})->group('phase-4');

it('refuses to confirm a PayPal preview while the account name is unset (server-side guard)', function (): void {
    // Mirror the first-upload setup: no PayPal Account exists, so
    // needsPaypalAccountName() returns true. A devtools-stripped
    // disabled attribute on the always-rendered Confirm button must
    // still no-op server-side, otherwise rows insert against a missing
    // account mapping.
    Account::query()
        ->where('user_id', $this->fixtureUser->id)
        ->where('kind', 'paypal')
        ->delete();

    $importer = $this->app->make(RunsImports::class);
    $preview = $importer->runFromUpload(
        $this->fixture,
        'paypal-csv',
        $this->fixtureUser,
        'paypal-sample-1.csv',
    );

    Livewire::test(PreviewWizard::class, ['id' => $preview->importRunId])
        ->call('confirm')
        ->assertNoRedirect()
        ->assertSee('Name your PayPal account.', false);

    expect(
        Account::query()
            ->where('user_id', $this->fixtureUser->id)
            ->where('kind', 'paypal')
            ->exists()
    )->toBeFalse();
})->group('phase-4');

it('skips the name-your-account step on subsequent PayPal uploads', function (): void {
    expect(
        Account::query()
            ->where('user_id', $this->fixtureUser->id)
            ->where('kind', 'paypal')
            ->exists()
    )->toBeTrue();

    $importer = $this->app->make(RunsImports::class);
    $preview = $importer->runFromUpload(
        $this->fixture,
        'paypal-csv',
        $this->fixtureUser,
        'paypal-sample-1.csv',
    );

    Livewire::test(PreviewWizard::class, ['id' => $preview->importRunId])
        ->assertDontSee('Name your PayPal account.', false)
        ->assertSee('Confirm import', false);
})->group('phase-4');

it('saves a PayPal Account with kind=paypal and synthetic IBAN PAYPAL on the naming step', function (): void {
    Account::query()
        ->where('user_id', $this->fixtureUser->id)
        ->where('kind', 'paypal')
        ->delete();

    $importer = $this->app->make(RunsImports::class);
    $preview = $importer->runFromUpload(
        $this->fixture,
        'paypal-csv',
        $this->fixtureUser,
        'paypal-sample-1.csv',
    );

    Livewire::test(PreviewWizard::class, ['id' => $preview->importRunId])
        ->set('paypalAccountName', 'My PayPal')
        ->call('savePaypalAccountName');

    $account = Account::query()
        ->where('user_id', $this->fixtureUser->id)
        ->where('kind', 'paypal')
        ->first();

    expect($account)->not->toBeNull();
    /** @var Account $account */
    expect($account->iban)->toBe('PAYPAL');
    expect($account->default_currency)->toBe('EUR');
    expect($account->name)->toBe('My PayPal');
})->group('phase-4');
