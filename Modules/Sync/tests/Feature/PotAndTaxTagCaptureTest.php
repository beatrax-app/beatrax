<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\User;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Models\ImportRun;
use Modules\Ledger\Models\Transaction;
use Modules\Pots\Public\Services\PotWriter;
use Modules\Sync\Internal\OpLog\OpLogWriter;
use Modules\Tax\Public\Actions\TagTransaction;
use Modules\Tax\Public\Actions\UntagTransaction;
use Modules\Tax\Public\Services\TaxCategoryWriter;

uses(RefreshDatabase::class);

/*
 * Two more tables that had merge rules and no capture, so an edit to either
 * never left the device that made it.
 */

function ptcUser(): User
{
    return User::query()->create([
        'username' => 'ptc-'.bin2hex(random_bytes(4)),
        'password' => 'fixture',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
}

function ptcBindWriter(int $userId): void
{
    $keypair = sodium_crypto_sign_keypair();

    app()->instance(OpLogWriter::class, app(OpLogWriter::class, [
        'deviceId' => 'ptc-device',
        'userId' => $userId,
        'secretKey' => sodium_crypto_sign_secretkey($keypair),
        'publicKey' => sodium_crypto_sign_publickey($keypair),
    ]));
}

function ptcOps(DatabaseManager $db, int $userId, string $table)
{
    return $db->connection()->table('op_log_entries')
        ->where('user_id', $userId)
        ->where('table_name', $table)
        ->get();
}

it('captures a pot the moment it is created', function (): void {
    $user = ptcUser();
    $account = Account::create([
        'user_id' => $user->id,
        'name' => 'ASN',
        'slug' => 'ptc-'.bin2hex(random_bytes(3)),
        'kind' => 'bank',
        'iban' => 'NL00PTC'.strtoupper(bin2hex(random_bytes(5))),
        'default_currency' => 'EUR',
    ]);

    ptcBindWriter((int) $user->id);

    app(PotWriter::class)->save($user, 'Annual insurance', null, (int) $account->id, null, null);

    $ops = ptcOps(app(DatabaseManager::class), (int) $user->id, 'pots');

    expect($ops)->not->toBeEmpty()
        ->and($ops->pluck('op_type')->unique()->all())->toBe(['create_row'])
        ->and($ops->pluck('field')->all())->toContain('name', 'account_id');
});

it('captures a pot rename as a set on the changed columns', function (): void {
    $user = ptcUser();
    $account = Account::create([
        'user_id' => $user->id,
        'name' => 'ASN',
        'slug' => 'ptc-'.bin2hex(random_bytes(3)),
        'kind' => 'bank',
        'iban' => 'NL00PTC'.strtoupper(bin2hex(random_bytes(5))),
        'default_currency' => 'EUR',
    ]);
    $pot = app(PotWriter::class)->save($user, 'Annual insurance', null, (int) $account->id, null, null);

    ptcBindWriter((int) $user->id);

    app(PotWriter::class)->update($user, (int) $pot->id, 'Winter tyres', null, null);

    $ops = ptcOps(app(DatabaseManager::class), (int) $user->id, 'pots');

    expect($ops->pluck('op_type')->unique()->all())->toBe(['set'])
        ->and($ops->pluck('field')->all())->toContain('name');
});

it('captures tagging a transaction for tax, and untagging it again', function (): void {
    $user = ptcUser();
    $account = Account::create([
        'user_id' => $user->id,
        'name' => 'ASN',
        'slug' => 'ptc-'.bin2hex(random_bytes(3)),
        'kind' => 'bank',
        'iban' => 'NL00PTC'.strtoupper(bin2hex(random_bytes(5))),
        'default_currency' => 'EUR',
    ]);
    $run = ImportRun::create([
        'user_id' => $user->id,
        'source_format' => 'camt053',
        'raw_file_path' => '/tmp/ptc.xml',
        'sha256' => hash('sha256', 'ptc-'.bin2hex(random_bytes(5))),
        'uploaded_at' => CarbonImmutable::now(),
        'status' => 'previewed',
    ]);
    $tx = Transaction::create([
        'user_id' => $user->id,
        'account_id' => $account->id,
        'type' => 'expense',
        'posted_at' => '2026-08-19',
        'booked_at' => '2026-08-19 12:00:00',
        'value_date' => '2026-08-19',
        'amount_minor' => -2600,
        'currency' => 'EUR',
        'settled_amount_minor' => -2600,
        'settled_currency' => 'EUR',
        'counterparty_name' => 'Bunq',
        'counterparty_normalized' => 'bunq',
        'normalization_version' => 1,
        'category_id' => null,
        'source_format' => 'camt053',
        'import_run_id' => $run->id,
        'source_row_index' => 1,
        'fingerprint' => str_pad('ptc', 64, '0', STR_PAD_LEFT),
        'fingerprint_version' => 1,
    ]);

    ptcBindWriter((int) $user->id);

    app(TagTransaction::class)->execute((int) $user->id, (int) $tx->id, null, null, null);

    $db = app(DatabaseManager::class);
    expect(ptcOps($db, (int) $user->id, 'tax_transaction_tags')->pluck('op_type')->unique()->all())
        ->toBe(['create_row']);

    app(UntagTransaction::class)->execute((int) $user->id, (int) $tx->id);

    expect(ptcOps($db, (int) $user->id, 'tax_transaction_tags')->pluck('op_type')->unique()->all())
        ->toContain('delete_tombstone');
});

it('captures a deduction category being added and renamed', function (): void {
    $user = ptcUser();
    ptcBindWriter((int) $user->id);

    $writer = app(TaxCategoryWriter::class);
    $categoryId = $writer->add((int) $user->id, 'Studiekosten');

    $db = app(DatabaseManager::class);
    $created = ptcOps($db, (int) $user->id, 'tax_deduction_categories');

    expect($created->pluck('op_type')->unique()->all())->toBe(['create_row'])
        ->and($created->pluck('field')->all())->toContain('name');

    $writer->rename((int) $user->id, $categoryId, 'Studiekosten 2026');

    expect(ptcOps($db, (int) $user->id, 'tax_deduction_categories')->pluck('op_type')->unique()->all())
        ->toContain('set');
});

it('captures archiving a deduction category as a status change', function (): void {
    $user = ptcUser();
    $writer = app(TaxCategoryWriter::class);
    $categoryId = $writer->add((int) $user->id, 'Studiekosten');

    ptcBindWriter((int) $user->id);
    $writer->archive((int) $user->id, $categoryId);

    $ops = ptcOps(app(DatabaseManager::class), (int) $user->id, 'tax_deduction_categories');

    expect($ops->pluck('field')->all())->toBe(['status'])
        ->and($ops->pluck('op_type')->all())->toBe(['set']);
});
