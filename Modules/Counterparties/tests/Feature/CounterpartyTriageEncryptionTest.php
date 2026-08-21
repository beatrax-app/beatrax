<?php

declare(strict_types=1);

use Illuminate\Contracts\Session\Session;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Counterparties\Internal\Http\Livewire\CounterpartyTriage;
use Modules\Counterparties\Models\Counterparty;
use Modules\Counterparties\Public\Queries\CounterpartyTriageQueue;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Models\ImportRun;
use Modules\Ledger\Models\Transaction;
use Modules\Sync\Public\Services\SensitiveColumnCodec;
use Modules\Sync\Tests\Support\EnablesEncryptionForUser;

// Two ways the triage flow leaks or breaks under encryption: the Counterparty
// model has no auto-encrypt hook, so a bare save() writes plaintext through;
// and suggestionFor() feeding an undecrypted description to
// MerchantNameResolver matches against ciphertext and never suggests anything.

uses(RefreshDatabase::class, EnablesEncryptionForUser::class);

function cpteUser(string $username): User
{
    return User::query()->create([
        'username' => $username,
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
    ]);
}

function cpteAccount(User $user): Account
{
    return Account::query()->create([
        'user_id' => $user->id,
        'name' => 'Triage-encryption fixture ASN',
        'slug' => 'cpte-asn-'.bin2hex(random_bytes(4)),
        'kind' => 'bank',
        'iban' => 'NL57ASNB'.strtoupper(bin2hex(random_bytes(5))),
        'default_currency' => 'EUR',
    ]);
}

function cpteImportRun(User $user): ImportRun
{
    return ImportRun::query()->create([
        'user_id' => $user->id,
        'source_format' => 'asn_csv',
        'raw_file_path' => 'fixture://cpte-triage-test',
        'sha256' => hash('sha256', 'cpte-'.bin2hex(random_bytes(8))),
        'uploaded_at' => now(),
        'confirmed_at' => now(),
        'inserted_count' => 0,
        'duplicate_count' => 0,
        'error_count' => 0,
        'status' => 'confirmed',
    ]);
}

function cpteUnknown(User $user, string $slug, ?string $iban = null): Counterparty
{
    return Counterparty::query()->create([
        'user_id' => $user->id,
        'type' => 'unknown',
        'slug' => $slug,
        'display_name' => $iban ?? $slug,
        'iban' => $iban,
        'merchant_name' => null,
        'metadata' => null,
    ]);
}

function cpteTx(User $user, Account $account, Counterparty $counterparty, ImportRun $run, string $description, ?SensitiveColumnCodec $codec = null, ?Session $session = null): void
{
    $storedDescription = $codec !== null && $session !== null
        ? $codec->encryptValue('transactions', 'description', $description, $user->id, $session)
        : $description;

    Transaction::query()->create([
        'user_id' => $user->id,
        'account_id' => $account->id,
        'counterparty_id' => $counterparty->id,
        'type' => 'expense',
        'posted_at' => now()->toDateString(),
        'booked_at' => now()->toDateTimeString(),
        'value_date' => now()->toDateString(),
        'amount_minor' => -1500,
        'currency' => 'EUR',
        'settled_amount_minor' => -1500,
        'settled_currency' => 'EUR',
        'counterparty_normalized' => substr($description, 0, 80),
        'normalization_version' => 1,
        'description' => $storedDescription,
        'source_format' => 'asn_csv',
        'import_run_id' => $run->id,
        'source_row_index' => random_int(1, 1_000_000),
        'fingerprint' => str_pad((string) random_int(1, 1_000_000_000), 64, '0', STR_PAD_LEFT),
        'fingerprint_version' => 1,
        'status' => 'cleared',
        'payment_type' => 'unknown',
    ]);
}

it('encrypts display_name/merchant_name at rest when acceptSuggestion() promotes an unknown counterparty', function (): void {
    $user = cpteUser('cpte-accept');
    $session = $this->enablesEncryptionForUser($user);
    $account = cpteAccount($user);
    $run = cpteImportRun($user);
    $unknown = cpteUnknown($user, 'cpte-mystery-spotify', 'NL17RABO0123456790');

    /** @var SensitiveColumnCodec $codec */
    $codec = $this->app->make(SensitiveColumnCodec::class);
    // Plaintext description on purpose: this case is only about what
    // acceptSuggestion() writes, not about whether a suggestion can be found
    // under encryption.
    cpteTx($user, $account, $unknown, $run, 'SPOTIFY P AMSTERDAM');

    DB::table('merchant_aliases')->insert([
        'user_id' => $user->id,
        'pattern' => 'SPOTIFY P AMSTERDAM',
        'generalized_pattern' => 'spotify',
        'friendly_name' => 'Spotify',
        'merged_from' => null,
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ]);

    Livewire::actingAs($user)->test(CounterpartyTriage::class)->call('acceptSuggestion');

    $rawRow = DB::table('counterparties')->where('id', $unknown->id)->first();
    expect($rawRow)->not->toBeNull();
    expect($rawRow->display_name)->not->toBe('Spotify');
    expect($rawRow->merchant_name)->not->toBe('Spotify');

    expect($codec->decryptValue('counterparties', 'display_name', $rawRow->display_name, $user->id, $session)['value'])->toBe('Spotify');
    expect($codec->decryptValue('counterparties', 'merchant_name', $rawRow->merchant_name, $user->id, $session)['value'])->toBe('Spotify');

    $cp = Counterparty::query()->where('id', $unknown->id)->firstOrFail();
    expect($cp->type)->toBe('merchant');
});

it('encrypts display_name/merchant_name at rest when manualLabel() labels an unknown counterparty as a merchant', function (): void {
    $user = cpteUser('cpte-manual-merchant');
    $session = $this->enablesEncryptionForUser($user);
    cpteUnknown($user, 'cpte-mystery-manual-1');

    /** @var SensitiveColumnCodec $codec */
    $codec = $this->app->make(SensitiveColumnCodec::class);

    Livewire::actingAs($user)->test(CounterpartyTriage::class)
        ->call('manualLabel', 'Corner Bakery', 'merchant');

    $rawRow = DB::table('counterparties')->where('slug', 'cpte-mystery-manual-1')->first();
    expect($rawRow)->not->toBeNull();
    expect($rawRow->display_name)->not->toBe('Corner Bakery');
    expect($rawRow->merchant_name)->not->toBe('Corner Bakery');

    expect($codec->decryptValue('counterparties', 'display_name', $rawRow->display_name, $user->id, $session)['value'])->toBe('Corner Bakery');
    expect($codec->decryptValue('counterparties', 'merchant_name', $rawRow->merchant_name, $user->id, $session)['value'])->toBe('Corner Bakery');
});

it('encrypts display_name (merchant_name left null) when manualLabel() labels an unknown counterparty as personal', function (): void {
    $user = cpteUser('cpte-manual-personal');
    $session = $this->enablesEncryptionForUser($user);
    cpteUnknown($user, 'cpte-mystery-manual-2');

    /** @var SensitiveColumnCodec $codec */
    $codec = $this->app->make(SensitiveColumnCodec::class);

    Livewire::actingAs($user)->test(CounterpartyTriage::class)
        ->call('manualLabel', 'Jane Doe', 'personal');

    $rawRow = DB::table('counterparties')->where('slug', 'cpte-mystery-manual-2')->first();
    expect($rawRow)->not->toBeNull();
    expect($rawRow->display_name)->not->toBe('Jane Doe');
    expect($rawRow->merchant_name)->toBeNull();

    expect($codec->decryptValue('counterparties', 'display_name', $rawRow->display_name, $user->id, $session)['value'])->toBe('Jane Doe');
});

it('leaves display_name/merchant_name as plaintext for a non-encrypted user', function (): void {
    $user = cpteUser('cpte-plaintext');
    cpteUnknown($user, 'cpte-mystery-plaintext');

    Livewire::actingAs($user)->test(CounterpartyTriage::class)
        ->call('manualLabel', 'Plain Corp', 'merchant');

    $rawRow = DB::table('counterparties')->where('slug', 'cpte-mystery-plaintext')->first();
    expect($rawRow->display_name)->toBe('Plain Corp');
    expect($rawRow->merchant_name)->toBe('Plain Corp');
});

it('decrypts each candidate description before matching so suggestionFor() returns a real suggestion under encryption', function (): void {
    $user = cpteUser('cpte-suggest');
    $session = $this->enablesEncryptionForUser($user);
    $account = cpteAccount($user);
    $run = cpteImportRun($user);
    $unknown = cpteUnknown($user, 'cpte-mystery-netflix', 'NL44RABO0123456789');

    /** @var SensitiveColumnCodec $codec */
    $codec = $this->app->make(SensitiveColumnCodec::class);

    for ($i = 0; $i < 3; $i++) {
        cpteTx($user, $account, $unknown, $run, 'NETFLIX SUBSCRIPTION ROW '.$i, $codec, $session);
    }

    DB::table('merchant_aliases')->insert([
        'user_id' => $user->id,
        'pattern' => 'NETFLIX',
        'generalized_pattern' => 'netflix',
        'friendly_name' => 'Netflix',
        'merged_from' => null,
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ]);

    // Without this the case passes vacuously: a still-broken matcher would
    // match fine against a fixture that was never encrypted.
    $storedDescription = DB::table('transactions')->where('counterparty_id', $unknown->id)->value('description');
    expect($storedDescription)->not->toContain('NETFLIX');

    /** @var CounterpartyTriageQueue $queue */
    $queue = $this->app->make(CounterpartyTriageQueue::class);
    $this->actingAs($user);

    $suggestion = $queue->suggestionFor($unknown->fresh());

    expect($suggestion)->not->toBeNull();
    expect($suggestion->suggestedCounterpartyName)->toBe('Netflix');
    expect($suggestion->confidence)->toBe('high');
});

// A row that blanks cannot resolve to anything, so counting it in the
// denominator turned a unanimous suggestion into a weak one and the triage
// banner presented a correct answer as a guess.
it('leaves an undecryptable row out of the confidence denominator', function (): void {
    $user = cpteUser('cpte-confidence');
    $session = $this->enablesEncryptionForUser($user);
    $account = cpteAccount($user);
    $run = cpteImportRun($user);
    $unknown = cpteUnknown($user, 'cpte-mystery-confidence', 'NL44RABO0123456799');

    /** @var SensitiveColumnCodec $codec */
    $codec = $this->app->make(SensitiveColumnCodec::class);

    for ($i = 0; $i < 3; $i++) {
        cpteTx($user, $account, $unknown, $run, 'NETFLIX SUBSCRIPTION ROW '.$i, $codec, $session);
    }
    for ($i = 0; $i < 3; $i++) {
        cpteTx($user, $account, $unknown, $run, base64_encode(random_bytes(48)));
    }

    DB::table('merchant_aliases')->insert([
        'user_id' => $user->id,
        'pattern' => 'NETFLIX',
        'generalized_pattern' => 'netflix',
        'friendly_name' => 'Netflix',
        'merged_from' => null,
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ]);

    /** @var CounterpartyTriageQueue $queue */
    $queue = $this->app->make(CounterpartyTriageQueue::class);
    $this->actingAs($user);

    $suggestion = $queue->suggestionFor($unknown->fresh());

    expect($suggestion)->not->toBeNull();
    expect($suggestion->confidence)->toBe('high');
});

it('keeps the suggestionFor() candidate read bounded to the existing per-counterparty limit (no full-history decrypt scan)', function (): void {
    $source = file_get_contents(base_path('Modules/Counterparties/Public/Queries/CounterpartyTriageQueue.php'));
    expect($source)->not->toBeFalse();
    expect($source)->toContain('->limit(20)');
});

it('decrypts counterparties.iban and display_name before the triage card renders them', function (): void {
    $user = cpteUser('cpte-iban-render');
    $session = $this->enablesEncryptionForUser($user);
    $unknown = cpteUnknown($user, 'cpte-mystery-iban', 'NL41BANK0000000022');

    /** @var SensitiveColumnCodec $codec */
    $codec = $this->app->make(SensitiveColumnCodec::class);

    // cpteUnknown() writes through the model, which has no encrypt hook; put
    // the row into the at-rest shape an encrypted device actually holds.
    DB::table('counterparties')->where('id', $unknown->id)->update([
        'iban' => $codec->encryptValue('counterparties', 'iban', 'NL41BANK0000000022', $user->id, $session),
        'display_name' => $codec->encryptValue('counterparties', 'display_name', 'Cafe Bloem', $user->id, $session),
    ]);

    $storedIban = DB::table('counterparties')->where('id', $unknown->id)->value('iban');
    expect($storedIban)->toBeString()->and($storedIban)->not->toBe('NL41BANK0000000022');

    /** @var CounterpartyTriageQueue $queue */
    $queue = $this->app->make(CounterpartyTriageQueue::class);
    $this->actingAs($user);

    $items = $queue->forUser($user);
    expect($items)->toHaveCount(1);
    expect($items[0]->iban)->toBe('NL41BANK0000000022');
    expect($items[0]->display_name)->toBe('Cafe Bloem');

    Livewire::actingAs($user)->test(CounterpartyTriage::class)
        ->assertSee('NL · ·· BANK ···· ···· 22')
        ->assertDontSee(substr((string) $storedIban, 0, 16));
});

it('does not write the decrypted iban back as plaintext when markIgnored() saves only metadata', function (): void {
    $user = cpteUser('cpte-ignore-roundtrip');
    $session = $this->enablesEncryptionForUser($user);
    $unknown = cpteUnknown($user, 'cpte-mystery-ignore', 'NL41BANK0000000099');

    /** @var SensitiveColumnCodec $codec */
    $codec = $this->app->make(SensitiveColumnCodec::class);

    DB::table('counterparties')->where('id', $unknown->id)->update([
        'iban' => $codec->encryptValue('counterparties', 'iban', 'NL41BANK0000000099', $user->id, $session),
        'display_name' => $codec->encryptValue('counterparties', 'display_name', 'Nordwind Media BV', $user->id, $session),
    ]);
    $before = DB::table('counterparties')->where('id', $unknown->id)->first();

    Livewire::actingAs($user)->test(CounterpartyTriage::class)->call('markIgnored');

    $after = DB::table('counterparties')->where('id', $unknown->id)->first();
    expect($after->iban)->toBe($before->iban);
    expect($after->display_name)->toBe($before->display_name);
    expect($after->iban)->not->toBe('NL41BANK0000000099');
});
