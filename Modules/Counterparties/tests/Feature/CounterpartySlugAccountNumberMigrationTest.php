<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Modules\Core\Models\User;
use Modules\Counterparties\Internal\Resolver\CounterpartySlugResolver;

function counterpartySlugMigration(): object
{
    return require base_path(
        'Modules/Counterparties/Database/Migrations/2026_09_05_000001_replace_counterparty_slugs_that_spell_an_account_number.php'
    );
}

function csamUser(string $username): User
{
    return User::query()->create([
        'username' => $username,
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
    ]);
}

function csamCounterparty(int $userId, string $slug, string $displayName, string $type = 'unknown'): int
{
    return DB::table('counterparties')->insertGetId([
        'user_id' => $userId,
        'type' => $type,
        'slug' => $slug,
        'display_name' => $displayName,
        'iban' => null,
        'merchant_name' => null,
        'metadata' => null,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

function csamSlug(int $id): string
{
    $slug = DB::table('counterparties')->where('id', $id)->value('slug');

    return is_string($slug) ? $slug : '';
}

beforeEach(function (): void {
    $this->owner = csamUser('csam-owner');
});

it('renames a slug that spells an IBAN', function (): void {
    $id = csamCounterparty($this->owner->id, 'nl91abna0417164300', 'NL91ABNA0417164300');

    counterpartySlugMigration()->up();

    expect(csamSlug($id))->toBe('unnamed');
    expect(CounterpartySlugResolver::spellsAnAccountIdentifier(csamSlug($id)))->toBeFalse();
});

it('renames a slug that spells a bare account number', function (): void {
    $id = csamCounterparty($this->owner->id, '0417164300', '0417164300');

    counterpartySlugMigration()->up();

    expect(csamSlug($id))->toBe('unnamed');
});

it('separates two renamed rows with the suffix the runtime walk uses', function (): void {
    $first = csamCounterparty($this->owner->id, 'nl91abna0417164300', 'NL91ABNA0417164300');
    $second = csamCounterparty($this->owner->id, 'be68539007547034', 'BE68539007547034');

    counterpartySlugMigration()->up();

    expect(csamSlug($first))->toBe('unnamed');
    expect(csamSlug($second))->toBe('unnamed-2');
    expect(DB::table('counterparties')->where('user_id', $this->owner->id)->distinct()->count('slug'))->toBe(2);
});

it('walks past a slug an untouched row already holds', function (): void {
    $squatter = csamCounterparty($this->owner->id, 'unnamed', 'Something Else', 'merchant');
    $leaky = csamCounterparty($this->owner->id, 'nl91abna0417164300', 'NL91ABNA0417164300');

    counterpartySlugMigration()->up();

    expect(csamSlug($squatter))->toBe('unnamed');
    expect(csamSlug($leaky))->toBe('unnamed-2');
});

it('leaves every slug alone that is a name rather than a number', function (): void {
    $ids = [
        'bol-com' => csamCounterparty($this->owner->id, 'bol-com', 'Bol.com', 'merchant'),
        'maria-van-buren' => csamCounterparty($this->owner->id, 'maria-van-buren', 'Maria van Buren', 'personal'),
        'unknown' => csamCounterparty($this->owner->id, 'unknown', 'Unknown'),
        'coolblue-b-v' => csamCounterparty($this->owner->id, 'coolblue-b-v', 'Coolblue B.V.', 'merchant'),
    ];

    counterpartySlugMigration()->up();

    foreach ($ids as $slug => $id) {
        expect(csamSlug($id))->toBe($slug);
    }
});

it('scopes the walk per user, so two users each keep the base slug', function (): void {
    $other = csamUser('csam-other');
    $mine = csamCounterparty($this->owner->id, 'nl91abna0417164300', 'NL91ABNA0417164300');
    $theirs = csamCounterparty($other->id, 'nl91abna0417164300', 'NL91ABNA0417164300');

    counterpartySlugMigration()->up();

    expect(csamSlug($mine))->toBe('unnamed');
    expect(csamSlug($theirs))->toBe('unnamed');
});

it('is idempotent — a second pass changes nothing', function (): void {
    $first = csamCounterparty($this->owner->id, 'nl91abna0417164300', 'NL91ABNA0417164300');
    $second = csamCounterparty($this->owner->id, 'be68539007547034', 'BE68539007547034');

    counterpartySlugMigration()->up();
    $after = [csamSlug($first), csamSlug($second)];

    counterpartySlugMigration()->up();

    expect([csamSlug($first), csamSlug($second)])->toBe($after);
});

// The round trip the rename is most likely to break: the slug is the
// firstOrCreate key, so a row the migration renamed must still be the row the
// next import finds rather than a second one beside it.
it('leaves the renamed row findable by the resolver walk that owns the slug', function (): void {
    $id = csamCounterparty($this->owner->id, 'nl91abna0417164300', 'NL91ABNA0417164300');

    counterpartySlugMigration()->up();

    /** @var CounterpartySlugResolver $slugs */
    $slugs = app(CounterpartySlugResolver::class);

    expect($slugs->resolveUnique($this->owner->id, 'NL91ABNA0417164300'))->toBe(csamSlug($id));
});
