<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Session\Session;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Livewire\Livewire;
use Mockery\MockInterface;
use Modules\Auth\Public\Services\AppLockKeyService;
use Modules\Core\Models\User;
use Modules\Core\Public\Contracts\Clock;
use Modules\Core\Public\Services\EncryptionMigrationService;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Models\ImportRun;
use Modules\Recurring\Internal\Detectors\ExpenseSeriesDetector;
use Modules\Recurring\Internal\Detectors\IncomeSeriesDetector;
use Modules\Recurring\Internal\Http\Livewire\RecurringPage;
use Modules\Recurring\Internal\Jobs\DetectRecurringSeriesJob;
use Modules\Recurring\Internal\StateMachines\RecurringSeriesStateMachine;
use Modules\Recurring\Models\RecurringSeries;
use Modules\Sync\Public\Services\SensitiveColumnCodec;
use Modules\Sync\Tests\Support\EnablesEncryptionForUser;
use Psr\Log\LoggerInterface;

uses(RefreshDatabase::class, EnablesEncryptionForUser::class);

/** @link ../../../../.docs/features/recurring/detection-encryption-posture.md */
function rdeUser(string $username): User
{
    $user = User::query()->create([
        'username' => $username,
        'password' => 'fixture',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
    // Wide enough to reach the fixtures' first 2025-04-25 occurrence.
    $user->recurring_detection_window_months = 36;
    $user->save();

    return $user;
}

function rdeAccount(User $user, string $slug): Account
{
    return Account::query()->create([
        'user_id' => $user->id,
        'name' => 'rde '.$slug,
        'slug' => $slug,
        'kind' => 'bank',
        'iban' => 'NL00RDE0'.str_pad(substr($slug, 0, 8), 10, '0', STR_PAD_RIGHT),
        'default_currency' => 'EUR',
    ]);
}

function rdeImportRun(User $user, string $sha): ImportRun
{
    return ImportRun::query()->create([
        'user_id' => $user->id,
        'source_format' => 'asn-csv',
        'raw_file_path' => '/tmp/rde.csv',
        'sha256' => $sha,
        'uploaded_at' => CarbonImmutable::parse('2026-05-17 00:00:00'),
        'status' => 'previewed',
    ]);
}

function rdeSeedIncomeTx(
    DatabaseManager $db,
    User $user,
    Account $account,
    ImportRun $run,
    string $postedAt,
    string $counterpartyIbanStored,
    int $rowIndex,
    string $fingerprintSeed,
): int {
    return (int) $db->connection()->table('transactions')->insertGetId([
        'user_id' => $user->id,
        'account_id' => $account->id,
        'type' => 'income',
        'posted_at' => $postedAt,
        'booked_at' => $postedAt.' 12:00:00',
        'value_date' => $postedAt,
        'amount_minor' => 350000,
        'currency' => 'EUR',
        'settled_amount_minor' => 350000,
        'settled_currency' => 'EUR',
        'counterparty_name' => 'RDE Employer BV',
        'counterparty_iban' => $counterpartyIbanStored,
        'counterparty_normalized' => 'rde employer bv',
        'normalization_version' => 3,
        'source_format' => 'asn-csv',
        'import_run_id' => $run->id,
        'source_row_index' => $rowIndex,
        'fingerprint' => str_pad($fingerprintSeed, 64, 'r', STR_PAD_LEFT),
        'fingerprint_version' => 3,
        'created_at' => '2026-05-17 12:00:00',
        'updated_at' => '2026-05-17 12:00:00',
    ]);
}

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-05-17 12:00:00');
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

it('decrypts counterparty_iban before clustering so same-IBAN income rows cluster into ONE series when the KEK is present (dispatchSync)', function (): void {
    $user = rdeUser('rde-kek-present');
    $session = $this->enablesEncryptionForUser($user);
    $this->actingAs($user);

    $account = rdeAccount($user, 'rde-kek-present');
    $run = rdeImportRun($user, str_repeat('a', 64));

    /** @var SensitiveColumnCodec $codec */
    $codec = $this->app->make(SensitiveColumnCodec::class);
    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);

    $start = CarbonImmutable::parse('2025-04-25');
    for ($i = 0; $i < 12; $i++) {
        $date = $start->addMonthsNoOverflow($i)->toDateString();
        // Re-encrypting the SAME plaintext IBAN yields a different ciphertext per
        // row (random nonce) — the failure mode this suite exists to close.
        $encryptedIban = $codec->encryptValue('transactions', 'counterparty_iban', 'NL91RDE0123456789', $user->id, $session);
        expect($encryptedIban)->not->toBe('NL91RDE0123456789');
        rdeSeedIncomeTx($db, $user, $account, $run, $date, $encryptedIban, $i + 1, 'rde-kp-'.$i);
    }

    Livewire::test(RecurringPage::class)->call('reDetect');

    /** @var Collection<int, RecurringSeries> $series */
    $series = RecurringSeries::query()
        ->where('user_id', $user->id)
        ->where('direction', 'income')
        ->get();

    expect($series)->toHaveCount(1);
    expect($series->first()?->cadence)->toBe('monthly');
    expect($series->first()?->latest_amount_minor)->toBe(350000);
})->group('RecurringDetectorEncryption');

it('logs a warning and skips the iban-dependent income detection when the KEK is unavailable for an encrypted user (KEK-less daemon origin)', function (): void {
    $user = rdeUser('rde-kek-absent');
    $session = $this->enablesEncryptionForUser($user);

    $account = rdeAccount($user, 'rde-kek-absent');
    $run = rdeImportRun($user, str_repeat('b', 64));

    /** @var SensitiveColumnCodec $codec */
    $codec = $this->app->make(SensitiveColumnCodec::class);
    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);

    $start = CarbonImmutable::parse('2025-04-25');
    for ($i = 0; $i < 12; $i++) {
        $date = $start->addMonthsNoOverflow($i)->toDateString();
        $encryptedIban = $codec->encryptValue('transactions', 'counterparty_iban', 'NL91RDE0987654321', $user->id, $session);
        rdeSeedIncomeTx($db, $user, $account, $run, $date, $encryptedIban, $i + 1, 'rde-ka-'.$i);
    }

    // A locked, KEK-less run: the session's data key is withheld AFTER the
    // ciphertext fixture was written but BEFORE the job runs, which is the shape
    // a queue worker that never unlocked a Session arrives in.
    $this->app->make(AppLockKeyService::class)->withhold($session);

    /** @var LoggerInterface&MockInterface $logger */
    $logger = Mockery::mock(LoggerInterface::class);
    $logger->shouldReceive('warning')
        ->once()
        ->withArgs(function (string $message, array $context) use ($user): bool {
            return str_contains($message, 'income-series')
                && ($context['user_id'] ?? null) === $user->id;
        });

    /** @var ExpenseSeriesDetector $expenseDetector */
    $expenseDetector = $this->app->make(ExpenseSeriesDetector::class);
    /** @var IncomeSeriesDetector $incomeDetector */
    $incomeDetector = $this->app->make(IncomeSeriesDetector::class);

    $job = new DetectRecurringSeriesJob($user->id);
    $job->handle(
        $db,
        $this->app->make(Clock::class),
        [$expenseDetector, $incomeDetector],
        $this->app->make(RecurringSeriesStateMachine::class),
        $session,
        $this->app->make(AppLockKeyService::class),
        $this->app->make(EncryptionMigrationService::class),
        $logger,
    );

    $count = RecurringSeries::query()
        ->where('user_id', $user->id)
        ->where('direction', 'income')
        ->count();
    expect($count)->toBe(0);
})->group('RecurringDetectorEncryption');

it('runs a non-encrypted user\'s scheduled sweep normally regardless of KEK state', function (): void {
    $user = rdeUser('rde-not-encrypted');
    /** @var Session $session */
    $session = $this->app->make(Session::class);

    $account = rdeAccount($user, 'rde-not-encrypted');
    $run = rdeImportRun($user, str_repeat('c', 64));

    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);

    $start = CarbonImmutable::parse('2025-04-25');
    for ($i = 0; $i < 12; $i++) {
        $date = $start->addMonthsNoOverflow($i)->toDateString();
        rdeSeedIncomeTx($db, $user, $account, $run, $date, 'NL91RDE0111222333', $i + 1, 'rde-ne-'.$i);
    }

    /** @var IncomeSeriesDetector $incomeDetector */
    $incomeDetector = $this->app->make(IncomeSeriesDetector::class);

    $job = new DetectRecurringSeriesJob($user->id);
    $job->handle(
        $db,
        $this->app->make(Clock::class),
        [$incomeDetector],
        $this->app->make(RecurringSeriesStateMachine::class),
        $session,
        $this->app->make(AppLockKeyService::class),
        $this->app->make(EncryptionMigrationService::class),
        $this->app->make(LoggerInterface::class),
    );

    $count = RecurringSeries::query()
        ->where('user_id', $user->id)
        ->where('direction', 'income')
        ->count();
    expect($count)->toBe(1);
})->group('RecurringDetectorEncryption');
