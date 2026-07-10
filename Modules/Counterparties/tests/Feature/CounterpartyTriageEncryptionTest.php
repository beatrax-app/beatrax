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

/*
 * 14.1-13 (CRYPT-01, Cluster 2 gap closure) — Counterparty merchant-triage
 * flow encrypts at rest and matches suggestions correctly under an
 * encrypted user.
 *
 * Task 1 (write fix): acceptSuggestion()/manualLabel() must encrypt
 * counterparties.display_name/merchant_name before save() — the
 * Counterparty model has no auto-encrypt hook, so a plain Eloquent save()
 * writes plaintext straight through.
 *
 * Task 2 (match fix): CounterpartyTriageQueue::suggestionFor() must
 * decrypt each candidate transactions.description before feeding it to
 * MerchantNameResolver::resolve() — otherwise substring/corpus matching
 * runs against ciphertext and always returns no suggestion.
 */

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
        'kind' => 'asn',
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

it('encrypts display_name/merchant_name at rest when acceptSuggestion() promotes an unknown counterparty (CRYPT-01 write fix)', function (): void {
    $user = cpteUser('cpte-accept');
    $session = $this->enablesEncryptionForUser($user);
    $account = cpteAccount($user);
    $run = cpteImportRun($user);
    $unknown = cpteUnknown($user, 'cpte-mystery-spotify', 'NL17RABO0123456790');

    /** @var SensitiveColumnCodec $codec */
    $codec = $this->app->make(SensitiveColumnCodec::class);
    cpteTx($user, $account, $unknown, $run, 'SPOTIFY P AMSTERDAM', $codec, $session);

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
    // The stored bytes must not be the plaintext merchant name — proves
    // the write went through the codec rather than a bare assignment.
    expect($rawRow->display_name)->not->toBe('Spotify');
    expect($rawRow->merchant_name)->not->toBe('Spotify');

    // But it must decrypt back to the intended plaintext.
    expect($codec->decryptValue('counterparties', 'display_name', $rawRow->display_name, $user->id, $session)['value'])->toBe('Spotify');
    expect($codec->decryptValue('counterparties', 'merchant_name', $rawRow->merchant_name, $user->id, $session)['value'])->toBe('Spotify');

    $cp = Counterparty::query()->where('id', $unknown->id)->firstOrFail();
    expect($cp->type)->toBe('merchant');
});

it('encrypts display_name/merchant_name at rest when manualLabel() labels an unknown counterparty as a merchant (CRYPT-01 write fix)', function (): void {
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

it('encrypts display_name (merchant_name left null) when manualLabel() labels an unknown counterparty as personal (CRYPT-01 write fix)', function (): void {
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

it('leaves display_name/merchant_name as plaintext for a non-encrypted user (CRYPT-01 pass-through)', function (): void {
    $user = cpteUser('cpte-plaintext');
    cpteUnknown($user, 'cpte-mystery-plaintext');

    Livewire::actingAs($user)->test(CounterpartyTriage::class)
        ->call('manualLabel', 'Plain Corp', 'merchant');

    $rawRow = DB::table('counterparties')->where('slug', 'cpte-mystery-plaintext')->first();
    expect($rawRow->display_name)->toBe('Plain Corp');
    expect($rawRow->merchant_name)->toBe('Plain Corp');
});

it('decrypts each candidate description before matching so suggestionFor() returns a real suggestion under encryption (CRYPT-01 match fix)', function (): void {
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

    // Confirm the fixture rows are genuinely ciphertext at rest — a
    // still-broken matcher would otherwise pass vacuously against
    // plaintext (Pitfall 2 from the CR-04 precedent).
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

it('keeps the suggestionFor() candidate read bounded to the existing per-counterparty limit (no full-history decrypt scan)', function (): void {
    $source = file_get_contents(base_path('Modules/Counterparties/Public/Queries/CounterpartyTriageQueue.php'));
    expect($source)->not->toBeFalse();
    expect($source)->toContain('->limit(20)');
});
