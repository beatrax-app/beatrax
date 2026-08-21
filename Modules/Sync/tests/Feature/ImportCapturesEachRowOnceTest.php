<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Modules\Core\Models\User;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Models\ImportRun;
use Modules\Ledger\Public\Actions\RecordTransactions;

// Capture moved down into the record action to cover the cash book, receipts
// and migration, but the import path already captured run and accounts first so
// a peer never meets an orphan. Both ran, and every imported row was signed,
// encrypted and logged twice — 168,000 entries for a 3,000-row import.

function captureOnceUser(): User
{
    return User::query()->create([
        'username' => 'cap1-'.bin2hex(random_bytes(4)),
        'password' => 'fixture',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
}

it('does not capture when the caller says it owns the capture', function (): void {
    $user = captureOnceUser();

    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    $account = Account::query()->create([
        'user_id' => $user->id,
        'name' => 'cap account',
        'slug' => 'cap-'.bin2hex(random_bytes(3)),
        'kind' => 'bank',
        'iban' => 'NL00CAP1'.str_pad((string) $user->id, 10, '0', STR_PAD_LEFT),
        'default_currency' => 'EUR',
    ]);

    ImportRun::query()->create([
        'user_id' => $user->id,
        'source_format' => 'asn-csv',
        'raw_file_path' => '/tmp/cap.csv',
        'sha256' => str_pad((string) $user->id, 64, 'c', STR_PAD_LEFT),
        'uploaded_at' => CarbonImmutable::parse('2026-08-20 00:00:00'),
        'status' => 'previewed',
    ]);

    $before = (int) $db->connection()->table('op_log_entries')->where('user_id', $user->id)->count();

    // The signature the import path uses. Whatever capture costs, opting out
    // must cost nothing.
    (app(RecordTransactions::class))([], $user, false);

    $after = (int) $db->connection()->table('op_log_entries')->where('user_id', $user->id)->count();

    expect($after)->toBe($before);

    unset($account);
});

it('keeps the opt-out off by default, so every other writer stays captured', function (): void {
    $source = (string) file_get_contents(
        base_path('Modules/Ledger/Public/Actions/RecordTransactions.php')
    );

    // The cash book, receipts and the migration pipeline all record through
    // this action and pass no flag; a default of false would silently drop
    // them back out of sync.
    expect($source)->toContain('bool $captureForSync = true');
});

it('leaves exactly one capture call on the import path', function (): void {
    $confirm = (string) file_get_contents(
        base_path('Modules/Import/Public/Actions/ConfirmImport.php')
    );

    expect($confirm)->toContain('($this->recorder)($canonical, $user, false)')
        ->and(substr_count($confirm, '$this->syncCapture->capture('))->toBe(1);
});
