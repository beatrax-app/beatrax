<?php

declare(strict_types=1);

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Modules\Import\Internal\Http\Livewire\UploadWizard;
use Modules\Import\Public\Contracts\ConfirmsImports;
use Modules\Ledger\Models\ImportRun;
use Modules\Ledger\Models\Transaction;
use Tests\Helpers\UploadIsolation;

beforeEach(function (): void {
    UploadIsolation::isolate();

    $seeded = $this->seedFixtureUserAndAccount();
    $this->fixtureUser = $seeded['user'];
    $this->paypalAccount = $seeded['paypalAccount'];
    $this->actingAs($this->fixtureUser);
});

it('iterates a 3-message mbox archive and routes each through the matcher pipeline', function (): void {
    $mboxBytes = (string) file_get_contents(__DIR__.'/../fixtures/mbox/paypal-mixed.mbox');
    $file = UploadedFile::fake()->createWithContent('paypal-mixed.mbox', $mboxBytes);

    Livewire::test(UploadWizard::class)
        ->set('importType', 'email')
        ->set('sourceFormat', 'mbox')
        ->set('file', $file)
        ->call('submit')
        ->assertHasNoErrors();

    $importRunId = ImportRun::query()->latest('id')->value('id');
    expect($importRunId)->not->toBeNull();
    $confirm = $this->app->make(ConfirmsImports::class);
    $confirm($importRunId, $this->fixtureUser);

    // 3 file_imports rows — one per mbox message.
    $rows = DB::table('file_imports')->where('user_id', $this->fixtureUser->id)->orderBy('id')->get();
    expect($rows)->toHaveCount(3);

    // Row 1: PayPal receipt — parsed.
    expect($rows[0]->status)->toBe('parsed');
    expect($rows[0]->matcher_key)->toBe('paypal-receipt');

    // Row 2: Non-PayPal sender — unmatched.
    expect($rows[1]->status)->toBe('unmatched');

    // Row 3: PayPal login notification — skipped.
    expect($rows[2]->status)->toBe('skipped');

    // Exactly one canonical transaction (the parsed receipt).
    expect(Transaction::query()->where('user_id', $this->fixtureUser->id)->count())->toBe(1);
});
