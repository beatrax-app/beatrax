<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Anomaly\Internal\AnomalyEvaluator;
use Modules\Anomaly\Tests\Support\AnomalyCorpusSeeder;
use Modules\Core\Models\User;
use Modules\Ledger\Public\Enums\TransactionType;

uses(RefreshDatabase::class);

function internalMoveAccount(DatabaseManager $db, User $user, string $kind): int
{
    return $db->connection()->table('accounts')->insertGetId([
        'user_id' => $user->id,
        'name' => 'internal move '.$kind,
        'slug' => 'internal-move-'.bin2hex(random_bytes(4)),
        'kind' => $kind,
        'iban' => 'NL00ASNB'.substr(bin2hex(random_bytes(8)), 0, 10),
        'default_currency' => 'EUR',
        'created_at' => '2026-01-01 00:00:00',
        'updated_at' => '2026-01-01 00:00:00',
    ]);
}

function internalMoveRun(DatabaseManager $db, User $user): int
{
    return $db->connection()->table('import_runs')->insertGetId([
        'user_id' => $user->id,
        'source_format' => 'asn-csv',
        'raw_file_path' => '/tmp/internal-move.csv',
        'sha256' => hash('sha256', 'internal-move'.bin2hex(random_bytes(8))),
        'uploaded_at' => '2026-01-01 00:00:00',
        'status' => 'previewed',
        'created_at' => '2026-01-01 00:00:00',
        'updated_at' => '2026-01-01 00:00:00',
    ]);
}

function internalMoveCounterparty(DatabaseManager $db, User $user, string $slug, string $type): int
{
    /** @var int|null $existing */
    $existing = $db->connection()->table('counterparties')
        ->where('user_id', $user->id)->where('slug', $slug)->value('id');

    return $existing ?? $db->connection()->table('counterparties')->insertGetId([
        'user_id' => $user->id,
        'type' => $type,
        'slug' => $slug,
        'display_name' => ucfirst(str_replace('-', ' ', $slug)),
        'created_at' => '2026-01-01 00:00:00',
        'updated_at' => '2026-01-01 00:00:00',
    ]);
}

function internalMoveTransaction(DatabaseManager $db, User $user, int $accountId, int $runId, ?int $counterpartyId, TransactionType $type, int $minor, string $postedAt): int
{
    static $index = 0;
    $index++;

    return $db->connection()->table('transactions')->insertGetId([
        'user_id' => $user->id,
        'account_id' => $accountId,
        'import_run_id' => $runId,
        'fingerprint' => hash('sha256', 'internal-move-fp-'.$index.bin2hex(random_bytes(8))),
        'posted_at' => $postedAt,
        'booked_at' => CarbonImmutable::parse($postedAt)->addSeconds($index % 86400)->toDateTimeString(),
        'value_date' => $postedAt,
        'amount_minor' => $minor,
        'currency' => 'EUR',
        'settled_amount_minor' => $minor,
        'settled_currency' => 'EUR',
        'counterparty_id' => $counterpartyId,
        'counterparty_normalized' => 'internal-move-'.($counterpartyId ?? 0),
        'counterparty_name' => 'INTERNAL MOVE '.($counterpartyId ?? 0),
        'normalization_version' => 1,
        'description' => 'internal move row '.$index,
        'type' => $type->value,
        'source_format' => 'asn-csv',
        'source_row_index' => $index,
        'fingerprint_version' => 3,
        'created_at' => '2026-01-01 00:00:00',
        'updated_at' => '2026-01-01 00:00:00',
    ]);
}

/**
 * @return list<string>
 */
function internalMoveReasons(DatabaseManager $db, int $transactionId): array
{
    /** @var string|null $reasons */
    $reasons = $db->connection()->table('anomaly_alerts')->where('transaction_id', $transactionId)->value('reasons');
    if (! is_string($reasons)) {
        return [];
    }

    /** @var list<string> $decoded */
    $decoded = json_decode($reasons, true) ?: [];

    return $decoded;
}

function internalMoveMigration(): Migration
{
    /** @var Migration $migration */
    $migration = require base_path('Modules/Anomaly/Database/Migrations/2026_08_30_000001_drop_anomaly_alerts_raised_on_internal_moves.php');

    return $migration;
}

beforeEach(function (): void {
    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);
    $this->db = $db;
    $this->user = AnomalyCorpusSeeder::makeUser();
    CarbonImmutable::setTestNow('2026-06-16 12:00:00');
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

it('opens no alert for a transfer large enough to trip the large-vs-typical detector', function (): void {
    $bank = internalMoveAccount($this->db, $this->user, 'bank');
    $run = internalMoveRun($this->db, $this->user);
    $savings = internalMoveCounterparty($this->db, $this->user, 'own-savings', 'self_account');

    foreach (['2026-01-10', '2026-02-10', '2026-03-10', '2026-04-10', '2026-05-10', '2026-06-10'] as $day) {
        internalMoveTransaction($this->db, $this->user, $bank, $run, $savings, TransactionType::TransferOut, -5000, $day);
    }

    $moved = internalMoveTransaction($this->db, $this->user, $bank, $run, $savings, TransactionType::TransferOut, -250000, '2026-06-15');

    /** @var AnomalyEvaluator $evaluator */
    $evaluator = $this->app->make(AnomalyEvaluator::class);
    $evaluator->evaluate($moved, $this->user);

    expect(internalMoveReasons($this->db, $moved))->toBe([]);
});

it('does not call the far side of an internal transfer a first-time merchant', function (): void {
    $bank = internalMoveAccount($this->db, $this->user, 'bank');
    $run = internalMoveRun($this->db, $this->user);

    foreach ([['albert-heijn', -3400, '2026-02-02'], ['netflix', -1299, '2026-02-15'], ['spotify', -999, '2026-03-01'], ['shell', -2890, '2026-03-04']] as [$slug, $minor, $day]) {
        $merchant = internalMoveCounterparty($this->db, $this->user, $slug, 'merchant');
        internalMoveTransaction($this->db, $this->user, $bank, $run, $merchant, TransactionType::Expense, $minor, $day);
    }

    $newAccount = internalMoveCounterparty($this->db, $this->user, 'my-brand-new-savings', 'self_account');
    $moved = internalMoveTransaction($this->db, $this->user, $bank, $run, $newAccount, TransactionType::TransferOut, -180000, '2026-06-15');

    /** @var AnomalyEvaluator $evaluator */
    $evaluator = $this->app->make(AnomalyEvaluator::class);
    $evaluator->evaluate($moved, $this->user);

    expect(internalMoveReasons($this->db, $moved))->toBe([]);
});

// The device scenario: a card statement of 23 charges and the bank payment
// that settles it. The settlement is the same money as the charges, counted a
// second time; the charges themselves are the detection that must survive.
it('leaves a card settlement unflagged while the charges on that statement are still judged', function (): void {
    $bank = internalMoveAccount($this->db, $this->user, 'bank');
    $card = internalMoveAccount($this->db, $this->user, 'ics_card');
    $run = internalMoveRun($this->db, $this->user);

    $statement = [
        ['coffee-corner', -420, '2026-02-04'],
        ['coffee-corner', -455, '2026-03-04'],
        ['coffee-corner', -480, '2026-04-04'],
        ['coffee-corner', -435, '2026-05-04'],
        ['coffee-corner', -465, '2026-05-20'],
        ['coffee-corner', -450, '2026-06-02'],
        ['coffee-corner', -9800, '2026-06-05'],
        ['albert-heijn', -3400, '2026-02-06'],
        ['albert-heijn', -2890, '2026-03-06'],
        ['albert-heijn', -3120, '2026-04-06'],
        ['albert-heijn', -2750, '2026-05-06'],
        ['albert-heijn', -3300, '2026-06-06'],
        ['shell-station', -6500, '2026-02-09'],
        ['shell-station', -7100, '2026-03-09'],
        ['shell-station', -5900, '2026-04-09'],
        ['shell-station', -6800, '2026-05-09'],
        ['shell-station', -6200, '2026-06-09'],
        ['netflix', -1299, '2026-03-15'],
        ['netflix', -1299, '2026-04-15'],
        ['netflix', -1299, '2026-05-15'],
        ['bol-com', -4500, '2026-02-21'],
        ['bol-com', -5200, '2026-03-21'],
        ['bol-com', -3900, '2026-04-21'],
    ];

    $charges = [];
    foreach ($statement as [$slug, $minor, $day]) {
        $merchant = internalMoveCounterparty($this->db, $this->user, $slug, 'merchant');
        $charges[] = internalMoveTransaction($this->db, $this->user, $card, $run, $merchant, TransactionType::Expense, $minor, $day);
    }

    $issuer = internalMoveCounterparty($this->db, $this->user, 'ics-cards-nederland', 'bank');
    $ownBank = internalMoveCounterparty($this->db, $this->user, 'own-bank-account', 'self_account');

    $legs = [];
    foreach (['2026-01-18', '2026-02-18', '2026-03-18', '2026-04-18', '2026-05-18'] as $day) {
        $legs[] = internalMoveTransaction($this->db, $this->user, $bank, $run, $issuer, TransactionType::TransferOut, -22500, $day);
        $legs[] = internalMoveTransaction($this->db, $this->user, $card, $run, $ownBank, TransactionType::TransferIn, 22500, $day);
    }

    $settlement = internalMoveTransaction($this->db, $this->user, $bank, $run, $issuer, TransactionType::TransferOut, -84732, '2026-06-14');
    $settlementLeg = internalMoveTransaction($this->db, $this->user, $card, $run, $ownBank, TransactionType::TransferIn, 84732, '2026-06-14');

    /** @var AnomalyEvaluator $evaluator */
    $evaluator = $this->app->make(AnomalyEvaluator::class);
    foreach ([...$charges, ...$legs, $settlement, $settlementLeg] as $transactionId) {
        $evaluator->evaluate($transactionId, $this->user);
    }

    $onInternalMoves = $this->db->connection()->table('anomaly_alerts')
        ->join('transactions', 'transactions.id', '=', 'anomaly_alerts.transaction_id')
        ->whereIn('transactions.type', TransactionType::transferValues())
        ->count();

    expect($onInternalMoves)->toBe(0)
        ->and(internalMoveReasons($this->db, $settlement))->toBe([])
        ->and(internalMoveReasons($this->db, $settlementLeg))->toBe([])
        ->and(internalMoveReasons($this->db, $charges[6]))->toContain('large')
        ->and(internalMoveReasons($this->db, $charges[11]))->toBe([]);
});

it('drops the alerts a build before this one raised against an internal move', function (): void {
    $bank = internalMoveAccount($this->db, $this->user, 'bank');
    $run = internalMoveRun($this->db, $this->user);
    $issuer = internalMoveCounterparty($this->db, $this->user, 'ics-cards-nederland', 'bank');

    $spent = internalMoveTransaction($this->db, $this->user, $bank, $run, $issuer, TransactionType::Expense, -56840, '2026-06-11');
    $moved = internalMoveTransaction($this->db, $this->user, $bank, $run, $issuer, TransactionType::TransferOut, -84732, '2026-06-14');

    $keptAlert = internalMoveSeedAlert($this->db, $this->user, $spent, 'expense');
    $staleAlert = internalMoveSeedAlert($this->db, $this->user, $moved, 'expense');

    $this->db->connection()->table('anomaly_alert_transitions')->insert([
        'user_id' => $this->user->id,
        'anomaly_alert_id' => $staleAlert,
        'from_state' => 'open',
        'to_state' => 'acknowledged',
        'transition_reason' => 'user_acknowledged',
        'actor' => 'user',
        'transitioned_at' => '2026-06-15 09:00:00',
        'created_at' => '2026-06-15 09:00:00',
        'updated_at' => '2026-06-15 09:00:00',
    ]);
    $this->db->connection()->table('anomaly_suppression_rules')->insert([
        'user_id' => $this->user->id,
        'counterparty_id' => $issuer,
        'detector' => 'large',
        'direction' => 'expense',
        'amount_band_low_minor' => -97442,
        'amount_band_high_minor' => -72022,
        'currency' => 'EUR',
        'source_anomaly_alert_id' => $staleAlert,
        'created_at' => '2026-06-15 09:00:00',
        'updated_at' => '2026-06-15 09:00:00',
    ]);

    internalMoveMigration()->up();
    internalMoveMigration()->up();

    $alerts = $this->db->connection()->table('anomaly_alerts')->pluck('id')->all();
    $rule = $this->db->connection()->table('anomaly_suppression_rules')->first();

    expect($alerts)->toBe([$keptAlert])
        ->and($this->db->connection()->table('anomaly_alert_transitions')->count())->toBe(0)
        ->and($rule)->not->toBeNull()
        ->and($rule->source_anomaly_alert_id)->toBeNull();
});

function internalMoveSeedAlert(DatabaseManager $db, User $user, int $transactionId, string $direction): int
{
    $id = $transactionId * 1000 + 7;
    $db->connection()->table('anomaly_alerts')->insert([
        'id' => $id,
        'user_id' => $user->id,
        'transaction_id' => $transactionId,
        'state' => 'open',
        'direction' => $direction,
        'reasons' => json_encode(['large', 'first_time']),
        'baseline_amount_minor' => -22500,
        'latest_amount_minor' => -84732,
        'currency' => 'EUR',
        'sensitivity_percent_used' => 50,
        'detected_at' => '2026-06-14 12:00:00',
        'created_at' => '2026-06-14 12:00:00',
        'updated_at' => '2026-06-14 12:00:00',
    ]);

    return $id;
}
