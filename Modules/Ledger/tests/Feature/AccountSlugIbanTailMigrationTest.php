<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Modules\Core\Models\User;
use Modules\Import\Public\Contracts\RunsImports;
use Modules\Import\Public\Enums\BankCsvFormatHint;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Models\Transaction;

function accountSlugIbanTailMigration(): object
{
    return require base_path(
        'Modules/Ledger/Database/Migrations/2026_08_21_000002_strip_the_iban_tail_from_account_slugs.php'
    );
}

/**
 * @return array{0: int, 1: string}
 */
function asitSeedAccount(int $userId, string $name, string $slug, string $iban): array
{
    $id = DB::table('accounts')->insertGetId([
        'user_id' => $userId,
        'name' => $name,
        'slug' => $slug,
        'kind' => 'bank',
        'iban' => $iban,
        'default_currency' => 'EUR',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return [$id, $slug];
}

function asitSlug(int $id): string
{
    $slug = DB::table('accounts')->where('id', $id)->value('slug');

    return is_string($slug) ? $slug : '';
}

beforeEach(function (): void {
    $this->freezeClockOnTheStatementFixtureWindow();
    $this->owner = User::query()->create([
        'username' => 'asit-owner',
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
    ]);
});

it('strips an eight-character IBAN tail written by the import namer', function (): void {
    [$id] = asitSeedAccount($this->owner->id, 'ASN Betaalrekening', 'asn-betaalrekening-23456789', 'NL57ASNB0123456789');

    accountSlugIbanTailMigration()->up();

    expect(asitSlug($id))->toBe('asn-betaalrekening');
});

it('strips a six-character IBAN tail written by the onboarding step', function (): void {
    [$id] = asitSeedAccount($this->owner->id, 'ASN bank', 'asn-bank-456789', 'NL57ASNB0123456789');

    accountSlugIbanTailMigration()->up();

    expect(asitSlug($id))->toBe('asn-bank');
});

it('separates two same-named accounts with a numeric suffix and keeps the slugs unique', function (): void {
    [$first] = asitSeedAccount($this->owner->id, 'ASN bank', 'asn-bank-456789', 'NL57ASNB0123456789');
    [$second] = asitSeedAccount($this->owner->id, 'ASN bank', 'asn-bank-999111', 'NL22ASNB0555999111');

    accountSlugIbanTailMigration()->up();

    expect(asitSlug($first))->toBe('asn-bank');
    expect(asitSlug($second))->toBe('asn-bank-2');
    expect(DB::table('accounts')->where('user_id', $this->owner->id)->distinct()->count('slug'))->toBe(2);
});

it('walks past a slug an untouched account already holds', function (): void {
    [$squatter] = asitSeedAccount($this->owner->id, 'Other', 'asn-bank', 'NL33ZZZZ0000000001');
    [$leaky] = asitSeedAccount($this->owner->id, 'ASN bank', 'asn-bank-456789', 'NL57ASNB0123456789');

    accountSlugIbanTailMigration()->up();

    expect(asitSlug($squatter))->toBe('asn-bank');
    expect(asitSlug($leaky))->toBe('asn-bank-2');
});

it('leaves slugs alone whose suffix is not a tail of that row own IBAN', function (): void {
    [$ics] = asitSeedAccount($this->owner->id, 'My card', 'my-card-ics-card', 'ICS-CARD');
    [$paypal] = asitSeedAccount($this->owner->id, 'My wallet', 'my-wallet-paypal', 'PAYPAL');
    [$cash] = asitSeedAccount($this->owner->id, 'Cash', 'cash-7', 'CASH7');
    [$numbered] = asitSeedAccount($this->owner->id, 'Savings', 'savings-2', 'NL44ZZZZ0000009999');
    [$demo] = asitSeedAccount($this->owner->id, 'ASN demo 1', 'asn-demo-1', 'NL55DEMO0000000001');

    accountSlugIbanTailMigration()->up();

    expect(asitSlug($ics))->toBe('my-card-ics-card');
    expect(asitSlug($paypal))->toBe('my-wallet-paypal');
    expect(asitSlug($cash))->toBe('cash-7');
    expect(asitSlug($numbered))->toBe('savings-2');
    expect(asitSlug($demo))->toBe('asn-demo-1');
});

// `accounts.name` is lww-synced and `slug` is not, so a name can change under
// a slug. Anchoring the match to the name skipped those rows and left the IBAN
// tail sitting in the plaintext column this migration exists to clear.
it('strips the tail from an account renamed since its slug was written', function (): void {
    [$id] = asitSeedAccount($this->owner->id, 'Spaargeld', 'asn-betaalrekening-23456789', 'NL57ASNB0123456789');

    accountSlugIbanTailMigration()->up();

    expect(asitSlug($id))->toBe('spaargeld');
});

it('scopes the walk per user, so two users keep the same clean slug', function (): void {
    $other = User::query()->create([
        'username' => 'asit-other',
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
    ]);

    [$mine] = asitSeedAccount($this->owner->id, 'ASN bank', 'asn-bank-456789', 'NL57ASNB0123456789');
    [$theirs] = asitSeedAccount($other->id, 'ASN bank', 'asn-bank-456789', 'NL57ASNB0123456789');

    accountSlugIbanTailMigration()->up();

    expect(asitSlug($mine))->toBe('asn-bank');
    expect(asitSlug($theirs))->toBe('asn-bank');
});

it('is idempotent — a second pass changes nothing', function (): void {
    [$first] = asitSeedAccount($this->owner->id, 'ASN bank', 'asn-bank-456789', 'NL57ASNB0123456789');
    [$second] = asitSeedAccount($this->owner->id, 'ASN bank', 'asn-bank-999111', 'NL22ASNB0555999111');

    accountSlugIbanTailMigration()->up();
    $after = [asitSlug($first), asitSlug($second)];

    accountSlugIbanTailMigration()->up();

    expect([asitSlug($first), asitSlug($second)])->toBe($after);
});

// The round trip the migration is most likely to break: it rewrites a column
// on the account rows every transaction hangs off, so re-importing the same
// statement afterwards must still dedup rather than double the ledger.
it('leaves re-import dedup intact after re-slugging a real imported account', function (): void {
    $this->seedFixtureUserAndAccount();
    $this->actingAs($this->fixtureUser);

    DB::table('accounts')
        ->where('iban', 'NL57ASNB0123456789')
        ->update(['name' => 'ASN Betaalrekening', 'slug' => 'asn-betaalrekening-23456789']);

    /** @var RunsImports $importer */
    $importer = $this->app->make(RunsImports::class);
    $fixture = base_path('tests/fixtures/asn-sample-1.csv');

    $first = $importer->runAndConfirm($fixture, 'asn-csv', $this->fixtureUser, formatHint: BankCsvFormatHint::Asn);
    expect($first->inserted)->toBeGreaterThan(0);

    $accountId = Account::query()->where('iban', 'NL57ASNB0123456789')->value('id');
    $rowsBefore = Transaction::query()->count();

    accountSlugIbanTailMigration()->up();

    expect(asitSlug((int) $accountId))->toBe('asn-betaalrekening');
    expect(Account::query()->where('iban', 'NL57ASNB0123456789')->count())->toBe(1);

    $second = $importer->runAndConfirm($fixture, 'asn-csv', $this->fixtureUser, formatHint: BankCsvFormatHint::Asn);

    expect($second->inserted)->toBe(0);
    expect($second->duplicates)->toBe($first->inserted);
    expect(Transaction::query()->count())->toBe($rowsBefore);
    expect(Transaction::query()->where('account_id', $accountId)->count())->toBe($rowsBefore);
});
