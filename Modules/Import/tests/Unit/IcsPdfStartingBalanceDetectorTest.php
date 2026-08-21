<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\User;
use Modules\Import\Internal\Detectors\IcsPdfStartingBalanceDetector;
use Modules\Import\Public\Dto\StartingBalanceCandidate;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Models\ImportRun;
use Modules\Ledger\Models\StatementSummary;

uses(RefreshDatabase::class);

it('returns the supports() flag only for the ics-pdf source format', function (): void {
    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);
    $detector = new IcsPdfStartingBalanceDetector($db);

    expect($detector->supports('ics-pdf'))->toBeTrue();
    expect($detector->supports('camt053'))->toBeFalse();
    expect($detector->supports('mt940'))->toBeFalse();
    expect($detector->supports('paypal-csv'))->toBeFalse();
})->group('phase-16.1.1');

it('returns one candidate per ICS card with the earliest opening_balance_date winning', function (): void {
    $user = User::query()->create([
        'username' => 'ics-detect',
        'password' => 'fixture-password',
        'period_start_day' => 1,
    ]);

    $card = Account::query()->create([
        'user_id' => $user->id,
        'name' => 'ICS card',
        'slug' => 'ics-card',
        'kind' => 'ics',
        'iban' => 'ICS-4444-1234-5678-9012',
        'default_currency' => 'EUR',
    ]);

    $januaryRun = ImportRun::query()->create([
        'user_id' => $user->id,
        'source_format' => 'ics-pdf',
        'raw_file_path' => '/tmp/jan.pdf',
        'sha256' => str_repeat('7', 64),
        'uploaded_at' => CarbonImmutable::parse('2026-02-15 12:00:00'),
        'status' => 'previewed',
    ]);

    $februaryRun = ImportRun::query()->create([
        'user_id' => $user->id,
        'source_format' => 'ics-pdf',
        'raw_file_path' => '/tmp/feb.pdf',
        'sha256' => str_repeat('8', 64),
        'uploaded_at' => CarbonImmutable::parse('2026-03-15 12:00:00'),
        'status' => 'previewed',
    ]);

    StatementSummary::query()->create([
        'user_id' => $user->id,
        'import_run_id' => $januaryRun->id,
        'account_id' => $card->id,
        'iban_owner' => $card->iban,
        'opening_balance_minor' => -3000,
        'opening_balance_currency' => 'EUR',
        'opening_balance_date' => CarbonImmutable::parse('2026-01-01 00:00:00'),
    ]);

    StatementSummary::query()->create([
        'user_id' => $user->id,
        'import_run_id' => $februaryRun->id,
        'account_id' => $card->id,
        'iban_owner' => $card->iban,
        'opening_balance_minor' => -5000,
        'opening_balance_currency' => 'EUR',
        'opening_balance_date' => CarbonImmutable::parse('2026-02-01 00:00:00'),
    ]);

    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);
    $detector = new IcsPdfStartingBalanceDetector($db);

    $candidates = $detector->detect([$januaryRun->id, $februaryRun->id], $user);

    expect($candidates)->toHaveCount(1);
    $candidate = $candidates[0];
    expect($candidate)->toBeInstanceOf(StartingBalanceCandidate::class);
    expect($candidate->accountId)->toBe($card->id);
    expect($candidate->openingBalanceMinor)->toBe(-3000);
    expect($candidate->openingBalanceDate)->toBe('2026-01-01');
    expect($candidate->sourceFormat)->toBe('ics-pdf');
})->group('phase-16.1.1');

it('skips statement_summaries rows that lack opening_balance_minor or opening_balance_date', function (): void {
    $user = User::query()->create([
        'username' => 'ics-null',
        'password' => 'fixture-password',
        'period_start_day' => 1,
    ]);

    $card = Account::query()->create([
        'user_id' => $user->id,
        'name' => 'ICS null card',
        'slug' => 'ics-null',
        'kind' => 'ics',
        'iban' => 'ICS-9999-9999-9999-9999',
        'default_currency' => 'EUR',
    ]);

    $run = ImportRun::query()->create([
        'user_id' => $user->id,
        'source_format' => 'ics-pdf',
        'raw_file_path' => '/tmp/null.pdf',
        'sha256' => str_repeat('e', 64),
        'uploaded_at' => CarbonImmutable::parse('2026-02-15 12:00:00'),
        'status' => 'previewed',
    ]);

    StatementSummary::query()->create([
        'user_id' => $user->id,
        'import_run_id' => $run->id,
        'account_id' => $card->id,
        'iban_owner' => $card->iban,
        'opening_balance_minor' => null,
        'opening_balance_currency' => null,
        'opening_balance_date' => null,
    ]);

    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);
    $detector = new IcsPdfStartingBalanceDetector($db);

    expect($detector->detect([$run->id], $user))->toBe([]);
})->group('phase-16.1.1');
