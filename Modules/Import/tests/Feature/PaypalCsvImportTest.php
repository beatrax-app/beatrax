<?php

declare(strict_types=1);

use Livewire\Livewire;
use Modules\Core\Public\Support\PatternScan;
use Modules\Import\Internal\Http\Livewire\PreviewWizard;
use Modules\Import\Public\Contracts\RunsImports;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Models\Transaction;

beforeEach(function (): void {
    $this->seedFixtureUserAndAccount();
    $this->actingAs($this->fixtureUser);
    $this->importer = $this->app->make(RunsImports::class);
    $this->fixture = base_path('Modules/Ingestion/tests/fixtures/paypal/paypal-sample-1.csv');
});

// 41 purchase parents plus the 41 funding legs that settled them. The legs
// used to fold into their parent and contribute nothing, which left the
// bank-side debit unpaired and the same euros counted twice.
it('imports the redacted fixture end-to-end with 82 canonical rows', function (): void {
    $result = $this->importer->runAndConfirm($this->fixture, 'paypal-csv', $this->fixtureUser);

    expect($result->inserted)->toBe(82);
    expect(Transaction::count())->toBe(82);
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
        expect(PatternScan::matches('/^O-\d{17}$/', (string) $row->source_ref))->toBeTrue();
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

    expect($first->inserted)->toBe(82);
    expect($second->inserted)->toBe(0);
    expect($second->duplicates)->toBe(82);
})->group('phase-4');

it('prompts the user to name the PayPal Account on the first PayPal upload', function (): void {
    // seedFixtureUserAndAccount() ships a PayPal account; the naming branch
    // only fires when the row is absent.
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
        // Confirm always renders in the page header; the gate is the `disabled`
        // attribute plus the server-side guard in PreviewWizard::confirm().
        ->assertSee('Confirm import', false)
        ->assertSeeHtmlInOrder(['wire:click="confirm"', 'disabled', 'Confirm import']);
})->group('phase-4');

it('refuses to confirm a PayPal preview while the account name is unset (server-side guard)', function (): void {
    // Stripping the `disabled` attribute in devtools has to leave confirm a
    // no-op, or rows insert against a missing account mapping.
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
