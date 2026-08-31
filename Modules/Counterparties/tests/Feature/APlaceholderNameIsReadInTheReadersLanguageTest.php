<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Migrations\Migration;
use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Core\Public\Services\UserCountry;
use Modules\Counterparties\Internal\Http\Livewire\CounterpartyIndex;
use Modules\Counterparties\Public\Contracts\CounterpartyResolver;
use Modules\Counterparties\Public\Enums\CounterpartyType;
use Modules\Counterparties\Public\Queries\CounterpartyIndexQuery;
use Modules\Counterparties\Public\Support\CounterpartyDefaultName;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Public\Dto\CanonicalTransaction;

// A phone set to Dutch showed "Onbekend" four times on /counterparties and
// "Unknown" once, on the counterparty row itself: the four read the lang key
// and the fifth read `counterparties.display_name`, where the resolver had
// written its own English word as data. A user-facing word stored in a column
// is frozen in the language whichever pass wrote it happened to run in.
const PLACEHOLDER_MIGRATION = 'Modules/Counterparties/Database/Migrations/2026_08_30_000002_mark_the_counterparty_name_the_app_invented_as_its_own.php';

function placeholderUser(string $username): User
{
    return User::query()->create([
        'username' => $username,
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
    ]);
}

function placeholderAccount(User $user, string $slug): Account
{
    return Account::query()->create([
        'user_id' => $user->id,
        'name' => 'ASN '.$slug,
        'slug' => $slug,
        'kind' => 'bank',
        'iban' => 'NL57ASNB0123456789',
        'default_currency' => 'EUR',
    ]);
}

// No counterparty name and no IBAN, and a description no corpus tier matches,
// which is the one shape that reaches the resolver's own fallback name. It is
// the shape of the four paypal-csv rows the phone's row was built from.
function placeholderCanonical(User $user, Account $account, string $description = 'ZQXJ REFERENTIE 88213'): CanonicalTransaction
{
    return new CanonicalTransaction(
        userId: $user->id,
        accountId: $account->id,
        type: 'transfer_in',
        postedAt: CarbonImmutable::parse('2026-03-01'),
        bookedAt: CarbonImmutable::parse('2026-03-01 12:00:00'),
        valueDate: CarbonImmutable::parse('2026-03-01'),
        amountMinor: 2500,
        currency: 'EUR',
        settledAmountMinor: 2500,
        settledCurrency: 'EUR',
        counterpartyName: null,
        counterpartyIban: null,
        counterpartyNormalized: '',
        normalizationVersion: 1,
        description: $description,
        categoryId: null,
        sourceFormat: 'paypal-csv',
        importRunId: 1,
        sourceRowIndex: 1,
        sourceRef: 'placeholder:1',
    );
}

// Straight through the query builder, the way the rows already on disk were
// written: the point of the fixture is a row the marking code never saw.
function legacyPlaceholderRow(User $user, string $type, string $slug, string $name): int
{
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    return $db->connection()->table('counterparties')->insertGetId([
        'user_id' => $user->id,
        'type' => $type,
        'slug' => $slug,
        'display_name' => $name,
        'iban' => null,
        'merchant_name' => null,
        'metadata' => null,
        'created_at' => '2026-03-01 09:00:00',
        'updated_at' => '2026-03-01 09:00:00',
    ]);
}

function runPlaceholderMigration(): void
{
    $migration = require base_path(PLACEHOLDER_MIGRATION);
    assert($migration instanceof Migration);
    $migration->up();
}

function nameOnTheIndex(User $user): string
{
    return app(CounterpartyIndexQuery::class)->forUser($user)->firstOrFail()->displayName;
}

it('marks the placeholder name the resolver invents as the app\'s own word', function (): void {
    $user = placeholderUser('placeholder-marks');
    $account = placeholderAccount($user, 'placeholder-marks-asn');

    /** @var CounterpartyResolver $resolver */
    $resolver = app(CounterpartyResolver::class);
    $resolved = $resolver->resolve(placeholderCanonical($user, $account), $user);

    expect($resolved?->type)->toBe(CounterpartyType::Unknown->value);

    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $row = $db->connection()->table('counterparties')->where('user_id', $user->id)->firstOrFail();

    expect($row->display_name)->toBe('Unknown');
    expect(CounterpartyDefaultName::tokenIn($row->metadata))->toBe(CounterpartyDefaultName::UNKNOWN);
});

it('reads the resolver\'s placeholder back in the language the reader is in', function (): void {
    $user = placeholderUser('placeholder-dutch');
    $account = placeholderAccount($user, 'placeholder-dutch-asn');

    /** @var CounterpartyResolver $resolver */
    $resolver = app(CounterpartyResolver::class);
    $resolver->resolve(placeholderCanonical($user, $account), $user);

    app()->setLocale('nl');

    expect(nameOnTheIndex($user))->toBe('Onbekend');

    Livewire::actingAs($user)->test(CounterpartyIndex::class)->assertSee('Onbekend');
});

it('repairs a placeholder already stored in English on an existing install', function (): void {
    $user = placeholderUser('placeholder-legacy');
    legacyPlaceholderRow($user, CounterpartyType::Unknown->value, 'unknown', 'Unknown');

    app()->setLocale('nl');
    expect(nameOnTheIndex($user))->toBe('Unknown');

    runPlaceholderMigration();

    expect(nameOnTheIndex($user))->toBe('Onbekend');
    Livewire::actingAs($user)->test(CounterpartyIndex::class)->assertSee('Onbekend');
});

it('leaves a counterparty the reader named "Unknown" themselves alone', function (): void {
    $user = placeholderUser('placeholder-authored');
    legacyPlaceholderRow($user, CounterpartyType::Merchant->value, 'unknown', 'Unknown');

    runPlaceholderMigration();

    app()->setLocale('nl');

    expect(nameOnTheIndex($user))->toBe('Unknown');
});

it('keeps a triage decision the reader already made on the placeholder row', function (): void {
    $user = placeholderUser('placeholder-ignored');
    $account = placeholderAccount($user, 'placeholder-ignored-asn');

    /** @var CounterpartyResolver $resolver */
    $resolver = app(CounterpartyResolver::class);
    $resolver->resolve(placeholderCanonical($user, $account), $user);

    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $rows = $db->connection()->table('counterparties')->where('user_id', $user->id);
    $rows->update(['metadata' => json_encode(['ignored' => true])]);

    $resolver->resolve(placeholderCanonical($user, $account), $user);

    $stored = $db->connection()->table('counterparties')->where('user_id', $user->id)->firstOrFail();
    expect(json_decode((string) $stored->metadata, true))->toBe(['ignored' => true]);
});

// The same defect, on the other arm that names a row with the app's own word:
// a regex government rule carrying no name. `regex:\bKUNTA\b` in the Finnish
// corpus file is one, and the description names no counterparty of its own.
it('reads the government fallback back in the reader\'s language too', function (): void {
    $user = placeholderUser('placeholder-government');
    $account = placeholderAccount($user, 'placeholder-government-asn');
    app(UserCountry::class)->store($user->id, 'fi');

    $resolved = app(CounterpartyResolver::class)->resolve(
        placeholderCanonical($user, $account, 'MAKSU KUNTA 2026'),
        $user,
    );

    expect($resolved?->type)->toBe(CounterpartyType::Government->value);

    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $row = $db->connection()->table('counterparties')->where('user_id', $user->id)->firstOrFail();
    expect($row->display_name)->toBe('Government');

    app()->setLocale('nl');
    expect(nameOnTheIndex($user))->toBe('Overheid');
});
