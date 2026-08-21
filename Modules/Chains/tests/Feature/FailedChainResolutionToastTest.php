<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\User;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Models\ImportRun;
use Modules\Ledger\Models\Transaction;

uses(RefreshDatabase::class);

function fcrtUser(string $username, bool $isDeveloper = true): User
{
    return User::query()->create([
        'username' => $username,
        'password' => 'fixture',
        'period_start_day' => 1,
        'is_developer' => $isDeveloper,
    ]);
}

beforeEach(function (): void {
    $this->user = fcrtUser('failed-chain');
    $this->otherUser = fcrtUser('failed-chain-other');

    $run = ImportRun::query()->create([
        'user_id' => $this->user->id,
        'source_format' => 'asn-csv',
        'raw_file_path' => '/tmp/fcrt.csv',
        'sha256' => str_repeat('e', 64),
        'uploaded_at' => CarbonImmutable::now(),
        'status' => 'previewed',
    ]);
    $asn = Account::query()->create([
        'user_id' => $this->user->id,
        'name' => 'fcrt asn',
        'slug' => 'fcrt-asn-'.bin2hex(random_bytes(3)),
        'kind' => 'bank',
        'iban' => 'NL57FCRT1234',
        'default_currency' => 'EUR',
    ]);
    Transaction::query()->create([
        'user_id' => $this->user->id,
        'account_id' => $asn->id,
        'type' => 'expense',
        'posted_at' => '2026-05-10',
        'booked_at' => '2026-05-10 12:00:00',
        'value_date' => '2026-05-10',
        'amount_minor' => -1000,
        'currency' => 'EUR',
        'settled_amount_minor' => -1000,
        'settled_currency' => 'EUR',
        'counterparty_name' => 'Seed',
        'counterparty_normalized' => 'seed',
        'normalization_version' => 3,
        'source_format' => 'asn-csv',
        'import_run_id' => $run->id,
        'source_row_index' => 1,
        'fingerprint' => str_pad('fcrt-seed', 64, 'd', STR_PAD_LEFT),
        'fingerprint_version' => 3,
    ]);
});

it('failed-job toast renders when chain_resolution_runs.status=failed for the user (16-06 D-37 — retargeted to /dev/queue/failed for developers)', function (): void {
    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);
    $now = CarbonImmutable::now()->toDateTimeString();
    $db->connection()->table('chain_resolution_runs')->insert([
        'user_id' => $this->user->id,
        'status' => 'failed',
        'last_error' => 'TestException: something went wrong',
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    $this->actingAs($this->user)
        ->get('/')
        ->assertOk()
        ->assertSeeText('Chain resolution failed')
        ->assertSeeText('Open Queue Inspector');
});

it('failed-job toast hidden when chain_resolution_runs has no failed rows for the user (cross-user — issue #8)', function (): void {
    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);
    $now = CarbonImmutable::now()->toDateTimeString();
    $db->connection()->table('chain_resolution_runs')->insert([
        'user_id' => $this->otherUser->id,
        'status' => 'failed',
        'last_error' => 'OtherUserError',
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    $this->actingAs($this->user)
        ->get('/')
        ->assertOk()
        ->assertDontSeeText('Chain resolution failed');
});

it('failed-job toast hidden when chain_resolution_runs has no rows at all', function (): void {
    $this->actingAs($this->user)
        ->get('/')
        ->assertOk()
        ->assertDontSeeText('Chain resolution failed');
});
