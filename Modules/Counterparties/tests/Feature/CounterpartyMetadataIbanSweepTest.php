<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Modules\Core\Models\User;

function counterpartyMetadataIbanMigration(): object
{
    return require base_path(
        'Modules/Counterparties/Database/Migrations/2026_09_05_000002_drop_the_plaintext_institution_iban_from_counterparty_metadata.php'
    );
}

function cmisRow(int $userId, string $slug, ?array $metadata): int
{
    return DB::table('counterparties')->insertGetId([
        'user_id' => $userId,
        'type' => 'bank',
        'slug' => $slug,
        'display_name' => 'PayPal (Europe) S.a r.l.',
        'iban' => 'LU89751000135104200E',
        'merchant_name' => null,
        'metadata' => $metadata === null ? null : json_encode($metadata, JSON_THROW_ON_ERROR),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

function cmisMetadata(int $id): ?string
{
    $value = DB::table('counterparties')->where('id', $id)->value('metadata');

    return is_string($value) ? $value : null;
}

beforeEach(function (): void {
    $this->owner = User::query()->create([
        'username' => 'cmis-owner',
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
    ]);
});

it('clears the cleartext IBAN the bridge arm stamped beside the sealed one', function (): void {
    $id = cmisRow($this->owner->id, 'paypal-europe', [
        'bridge_account_kind' => 'paypal',
        'institution_iban' => 'LU89751000135104200E',
    ]);

    counterpartyMetadataIbanMigration()->up();

    expect(cmisMetadata($id))->not->toContain('LU89751000135104200E');
    expect(cmisMetadata($id))->not->toContain('institution_iban');
    expect(cmisMetadata($id))->toContain('paypal');
});

// The reader's own decisions live in the same column, so the sweep has to be
// a key removal rather than a column reset: `ignored` is a triage answer and
// `default_name` says whose words the display name is.
it('keeps every other key on the row', function (): void {
    $id = cmisRow($this->owner->id, 'paypal-kept', [
        'bridge_account_kind' => 'paypal',
        'institution_iban' => 'LU89751000135104200E',
        'ignored' => true,
        'default_name' => 'unknown',
    ]);

    counterpartyMetadataIbanMigration()->up();

    $decoded = json_decode(cmisMetadata($id) ?? '', true);

    expect($decoded)->toBe([
        'bridge_account_kind' => 'paypal',
        'ignored' => true,
        'default_name' => 'unknown',
    ]);
});

it('leaves a row that never carried the key untouched, and runs twice without changing it', function (): void {
    $clean = cmisRow($this->owner->id, 'paypal-clean', ['bridge_account_kind' => 'paypal']);
    $empty = cmisRow($this->owner->id, 'paypal-empty', null);

    counterpartyMetadataIbanMigration()->up();
    $after = [cmisMetadata($clean), cmisMetadata($empty)];

    counterpartyMetadataIbanMigration()->up();

    expect([cmisMetadata($clean), cmisMetadata($empty)])->toBe($after);
    expect(cmisMetadata($empty))->toBeNull();
});
