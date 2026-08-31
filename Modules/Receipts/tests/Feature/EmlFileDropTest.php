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

it('lands a single canonical transaction for a dropped PayPal .eml receipt', function (): void {
    $emlBytes = (string) file_get_contents(__DIR__.'/../fixtures/paypal/current-receipt.eml');
    $file = UploadedFile::fake()->createWithContent('paypal-receipt.eml', $emlBytes);

    Livewire::test(UploadWizard::class)
        ->set('importType', 'email')
        ->set('sourceFormat', 'eml')
        ->set('file', $file)
        ->call('submit')
        ->assertHasNoErrors();

    // The wizard only gets as far as a previewed run — confirming is a separate
    // user step, so drive it here to exercise both halves.
    $importRunId = ImportRun::query()->latest('id')->value('id');
    expect($importRunId)->not->toBeNull();
    $confirm = $this->app->make(ConfirmsImports::class);
    $confirm($importRunId, $this->fixtureUser);

    $fileImports = DB::table('file_imports')->where('user_id', $this->fixtureUser->id)->get();
    expect($fileImports)->toHaveCount(1);
    $row = $fileImports->first();
    expect($row->status)->toBe('parsed');
    expect($row->matcher_key)->toBe('paypal-receipt');
    expect($row->sender_email)->toBe('service@paypal.com');
    expect($row->source_kind)->toBe('eml');

    $transactions = Transaction::query()->where('user_id', $this->fixtureUser->id)->get();
    expect($transactions)->toHaveCount(1);
    $tx = $transactions->first();
    expect($tx->counterparty_name)->toBe('Netflix BV');
    expect($tx->amount_minor)->toBe(-1299);
    expect($tx->source_ref)->toBe('PAYPALTXN17052026');
    expect($tx->account_id)->toBe($this->paypalAccount->id);
});

it('treats a re-dropped .eml as a no-op (UNIQUE constraint on user_id + provider_message_id)', function (): void {
    $emlBytes = (string) file_get_contents(__DIR__.'/../fixtures/paypal/current-receipt.eml');

    $drop = function () use ($emlBytes): void {
        $file = UploadedFile::fake()->createWithContent('paypal-receipt.eml', $emlBytes);
        Livewire::test(UploadWizard::class)
            ->set('importType', 'email')
            ->set('sourceFormat', 'eml')
            ->set('file', $file)
            ->call('submit');
        $importRunId = ImportRun::query()->latest('id')->value('id');
        if ($importRunId !== null) {
            $confirm = $this->app->make(ConfirmsImports::class);
            $confirm($importRunId, $this->fixtureUser);
        }
    };

    $drop();
    $drop();

    expect(DB::table('file_imports')->where('user_id', $this->fixtureUser->id)->count())->toBe(1);
    expect(Transaction::query()->where('user_id', $this->fixtureUser->id)->count())->toBe(1);
});
