<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Livewire\Livewire;
use Mockery\MockInterface;
use Modules\Import\Internal\Http\Livewire\UploadWizard;
use Modules\Import\Public\Contracts\ConfirmsImports;
use Modules\Import\Public\Events\TransactionImported;
use Modules\Ledger\Models\ImportRun;
use Modules\Ledger\Models\Transaction;
use Modules\Receipts\Internal\Listeners\DispatchChainHintsFromReceipt;
use Modules\Sync\Tests\Support\EnablesEncryptionForUser;
use Tests\Helpers\UploadIsolation;

uses(EnablesEncryptionForUser::class);

beforeEach(function (): void {
    UploadIsolation::isolate();
});

// The listener runs synchronously in the same call frame as RecordTransactions'
// write, so whatever KEK availability applied at write time still applies to
// this read. A raw_payload that is present but will not decode has to warn
// rather than silently no-op.

it('resolves chain hints from an encrypted raw_payload (request-context write and read share the same KEK availability)', function (): void {
    $seeded = $this->seedFixtureUserAndAccount();
    $this->fixtureUser = $seeded['user'];
    $this->enablesEncryptionForUser($this->fixtureUser);
    $this->actingAs($this->fixtureUser);

    $emlBytes = (string) file_get_contents(__DIR__.'/../fixtures/ics/current-receipt.eml');
    $file = UploadedFile::fake()->createWithContent('ics-receipt.eml', $emlBytes);

    Livewire::test(UploadWizard::class)
        ->set('issuer', 'email-file')
        ->set('sourceFormat', 'eml')
        ->set('file', $file)
        ->call('submit')
        ->assertHasNoErrors();

    $importRunId = ImportRun::query()->latest('id')->value('id');
    $confirm = $this->app->make(ConfirmsImports::class);
    $confirm($importRunId, $this->fixtureUser);

    $tx = Transaction::query()->where('user_id', $this->fixtureUser->id)->firstOrFail();

    // Ciphertext at rest proves the scenario genuinely exercised encryption
    // instead of passing on a decrypt-of-plaintext no-op.
    $stored = DB::table('transactions')->where('id', $tx->id)->first();
    expect($stored->raw_payload)->not->toContain('funded_by_card');

    // EncryptedJsonCast decrypts it back on read.
    expect($tx->raw_payload)->toBeArray();
    expect($tx->raw_payload['chain_hints'][0]['hint_type'])->toBe('funded_by_card');

    $chainLinks = DB::table('chain_links')->where('user_id', $this->fixtureUser->id)->get();
    expect($chainLinks)->toHaveCount(1);
    expect($chainLinks->first()->kind)->toBe('funded_by_card_hint');
});

it('logs a warning instead of silently dropping hints when raw_payload fails to decode', function (): void {
    $seeded = $this->seedFixtureUserAndAccount();
    $user = $seeded['user'];

    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);
    $txId = $db->connection()->table('transactions')->insertGetId([
        'user_id' => $user->id,
        'account_id' => $seeded['icsAccount']->id,
        'type' => 'expense',
        'posted_at' => '2026-05-15',
        'booked_at' => '2026-05-15 12:00:00',
        'value_date' => '2026-05-15',
        'amount_minor' => -1299,
        'currency' => 'EUR',
        'settled_amount_minor' => -1299,
        'settled_currency' => 'EUR',
        'counterparty_name' => 'Fixture Merchant',
        'counterparty_normalized' => 'fixture merchant',
        'normalization_version' => 3,
        'raw_payload' => 'not-decodable-garbage',
        'source_format' => 'eml',
        'import_run_id' => ImportRun::query()->create([
            'user_id' => $user->id,
            'source_format' => 'eml',
            'raw_file_path' => '/tmp/rpd-listener.eml',
            'sha256' => str_repeat('e', 64),
            'uploaded_at' => now(),
            'status' => 'confirmed',
        ])->id,
        'source_row_index' => 0,
        'fingerprint' => str_repeat('e', 64),
        'fingerprint_version' => 3,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $tx = Transaction::query()->findOrFail($txId);
    expect($tx->raw_payload)->toBeNull();

    /** @var MockInterface $logSpy */
    $logSpy = Log::spy();

    $this->app->make(DispatchChainHintsFromReceipt::class)->handle(new TransactionImported(
        transaction: $tx,
        user: $user,
    ));

    $logSpy->shouldHaveReceived('warning')
        ->withArgs(function (string $message, array $ctx) use ($txId): bool {
            return str_starts_with($message, 'DispatchChainHintsFromReceipt:')
                && ($ctx['transaction_id'] ?? null) === $txId;
        })
        ->once();
});
