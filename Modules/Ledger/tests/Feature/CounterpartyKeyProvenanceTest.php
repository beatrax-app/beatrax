<?php

declare(strict_types=1);

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Session\Session;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Ledger\Internal\Services\CounterpartyKeyProvenance;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Models\ImportRun;
use Modules\Ledger\Public\Services\CounterpartyKey;
use Modules\Sync\Public\Services\BlindIndexCodec;

uses(RefreshDatabase::class);

// Authorship is answered by re-deriving a digest from the row's OWN plaintext,
// so every arm of that walk is a shape a peer's replayed row could otherwise
// pass: an unreadable name, a name that normalises to nothing, a digest that
// only the merchants table holds.
/**
 * @link ../../../../.docs/features/sync/sensitive-columns-at-rest.md
 */
// Built rather than written out, which is how every other key fixture in this
// repository spells one: a 64-character hex literal sitting next to the word
// key is indistinguishable from a real one to a secret scanner. Nothing here
// depends on the digits, only on the two being different.
function ckpKey(): string
{
    return str_repeat('a1b2c3d4', 8);
}

function ckpOtherKey(): string
{
    return str_repeat('0f1e2d3c', 8);
}

function ckpUser(string $suffix): User
{
    return User::query()->create([
        'username' => 'ckp-'.$suffix.'-'.bin2hex(random_bytes(4)),
        'password' => 'fixture-password',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
}

function ckpTransaction(User $user, string $counterpartyName, string $normalized): void
{
    $account = Account::query()->create([
        'user_id' => $user->id,
        'name' => 'ckp account',
        'slug' => 'ckp-'.bin2hex(random_bytes(4)),
        'kind' => 'bank',
        'iban' => 'NL57ASNB0123456789',
        'default_currency' => 'EUR',
    ]);

    $run = ImportRun::query()->create([
        'user_id' => $user->id,
        'source_format' => 'asn-csv',
        'raw_file_path' => '/tmp/ckp.csv',
        'sha256' => hash('sha256', 'ckp-'.bin2hex(random_bytes(8))),
        'uploaded_at' => CarbonImmutable::parse('2026-07-01 09:00:00'),
        'status' => 'previewed',
    ]);

    app(DatabaseManager::class)->connection()->table('transactions')->insert([
        'user_id' => $user->id,
        'account_id' => $account->id,
        'type' => 'expense',
        'posted_at' => '2026-07-01',
        'booked_at' => '2026-07-01 12:00:00',
        'value_date' => '2026-07-01',
        'amount_minor' => -2450,
        'currency' => 'EUR',
        'settled_amount_minor' => -2450,
        'settled_currency' => 'EUR',
        'counterparty_name' => $counterpartyName,
        'counterparty_normalized' => $normalized,
        'normalization_version' => 3,
        'source_format' => 'asn-csv',
        'import_run_id' => $run->id,
        'source_row_index' => 0,
        'fingerprint' => str_pad('ckp'.bin2hex(random_bytes(6)), 64, 'c', STR_PAD_LEFT),
        'fingerprint_version' => 3,
        'created_at' => '2026-07-01 12:00:00',
        'updated_at' => '2026-07-01 12:00:00',
    ]);
}

function ckpDigest(User $user, string $normalized, string $keyHex): string
{
    return app(BlindIndexCodec::class)->deriveWithKey(CounterpartyKey::DOMAIN, $normalized, (int) $user->id, $keyHex);
}

function ckpReproduces(User $user, string $keyHex): bool
{
    /** @var Session $session */
    $session = app(Session::class);

    return app(CounterpartyKeyProvenance::class)->reproducesAStoredDigest((int) $user->id, $keyHex, $session);
}

it('recognises a digest the row derives from its own name under that key', function (): void {
    $user = ckpUser('own');
    ckpTransaction($user, 'Spotify AB', ckpDigest($user, 'spotify ab', ckpKey()));

    expect(ckpReproduces($user, ckpKey()))->toBeTrue();
});

it('does not recognise the same row under a key it was not written with', function (): void {
    $user = ckpUser('foreign');
    ckpTransaction($user, 'Spotify AB', ckpDigest($user, 'spotify ab', ckpKey()));

    expect(ckpReproduces($user, ckpOtherKey()))->toBeFalse();
});

// A sweep that could not read the names left the transaction column carrying a
// peer's digest while merchants got the local one, so the row's own column is
// not the last word on whether this device wrote the value.
it('recognises a digest only the merchants row holds', function (): void {
    $user = ckpUser('merchant');
    ckpTransaction($user, 'Spotify AB', ckpDigest($user, 'spotify ab', ckpOtherKey()));

    app(DatabaseManager::class)->connection()->table('merchants')->insert([
        'user_id' => $user->id,
        'name' => 'Spotify AB',
        'normalized_name' => ckpDigest($user, 'spotify ab', ckpKey()),
        'created_at' => '2026-07-01 12:00:00',
        'updated_at' => '2026-07-01 12:00:00',
    ]);

    expect(ckpReproduces($user, ckpKey()))->toBeTrue();
});

// Nothing to re-derive from: the probe picks the row up on the shape of its
// digest column, and answering true off a name that normalises to nothing
// would call a peer's row this device's own.
it('answers false for a row whose name normalises away', function (): void {
    $user = ckpUser('unnamed');
    ckpTransaction($user, '###', ckpDigest($user, 'spotify ab', ckpKey()));

    expect(ckpReproduces($user, ckpKey()))->toBeFalse();
});

it('answers false for a row carrying no counterparty name at all', function (): void {
    $user = ckpUser('blank');
    ckpTransaction($user, '', ckpDigest($user, 'spotify ab', ckpKey()));

    expect(ckpReproduces($user, ckpKey()))->toBeFalse();
});
