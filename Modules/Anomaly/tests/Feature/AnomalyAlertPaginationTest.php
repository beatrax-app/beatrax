<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Modules\Anomaly\Public\Services\AnomalyAlertQuery;
use Modules\Core\Models\User;
use Modules\Core\Public\Support\DerivedRowId;
use Modules\DriftAlerts\Internal\Http\Livewire\DriftPage;
use Modules\DriftAlerts\Public\Enums\DriftPageType;

uses(RefreshDatabase::class);

// The alert id is derived from the row's own columns so two devices agree on
// it, which means it no longer ascends with insertion: `id DESC` paged by
// `id < cursor` would render in hash order and skip or repeat rows.

function anomPageUser(): User
{
    return User::query()->create([
        'username' => 'anom-page-'.bin2hex(random_bytes(4)),
        'password' => 'fixture',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
}

function anomPageTransaction(DatabaseManager $db, int $userId): int
{
    $suffix = bin2hex(random_bytes(4));

    $accountId = $db->connection()->table('accounts')->insertGetId([
        'user_id' => $userId,
        'name' => 'ASN paging',
        'slug' => 'anom-page-'.$suffix,
        'kind' => 'bank',
        'iban' => 'NL00ASNB'.strtoupper($suffix),
        'default_currency' => 'EUR',
        'created_at' => '2026-06-01 00:00:00',
        'updated_at' => '2026-06-01 00:00:00',
    ]);

    $runId = $db->connection()->table('import_runs')->insertGetId([
        'user_id' => $userId,
        'source_format' => 'asn-csv',
        'raw_file_path' => '/tmp/anom-page-'.$suffix.'.csv',
        'sha256' => hash('sha256', 'anom-page-'.$suffix),
        'uploaded_at' => '2026-06-01 00:00:00',
        'status' => 'previewed',
        'created_at' => '2026-06-01 00:00:00',
        'updated_at' => '2026-06-01 00:00:00',
    ]);

    return $db->connection()->table('transactions')->insertGetId([
        'user_id' => $userId,
        'account_id' => $accountId,
        'import_run_id' => $runId,
        'fingerprint' => hash('sha256', 'anom-page-'.$suffix),
        'posted_at' => '2026-06-15',
        'booked_at' => '2026-06-15 00:00:00',
        'value_date' => '2026-06-15',
        'amount_minor' => -2349,
        'currency' => 'EUR',
        'settled_amount_minor' => -2349,
        'settled_currency' => 'EUR',
        'counterparty_normalized' => 'paging merchant',
        'counterparty_name' => 'Paging Merchant',
        'normalization_version' => 1,
        'description' => 'anom paging fixture',
        'type' => 'expense',
        'source_format' => 'asn-csv',
        'source_row_index' => 1,
        'fingerprint_version' => 3,
        'created_at' => '2026-06-01 00:00:00',
        'updated_at' => '2026-06-01 00:00:00',
    ]);
}

// Carries the id the evaluator would derive, so the ids under test are the
// real non-ascending ones rather than rowids allocated in order.
function anomPageAlert(DatabaseManager $db, int $userId, string $detectedAt): int
{
    $transactionId = anomPageTransaction($db, $userId);
    $alertId = DerivedRowId::for('anomaly_alerts', ['user_id' => $userId, 'transaction_id' => $transactionId]);

    $db->connection()->table('anomaly_alerts')->insert([
        'id' => $alertId,
        'user_id' => $userId,
        'transaction_id' => $transactionId,
        'state' => 'open',
        'direction' => 'expense',
        'reasons' => json_encode(['large']),
        'baseline_amount_minor' => -999,
        'latest_amount_minor' => -2349,
        'currency' => 'EUR',
        'sensitivity_percent_used' => 50,
        'detected_at' => $detectedAt,
        'created_at' => $detectedAt,
        'updated_at' => $detectedAt,
    ]);

    return $alertId;
}

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-06-20 09:00:00');
    $this->user = anomPageUser();
    $this->db = $this->app->make(DatabaseManager::class);
    $this->query = $this->app->make(AnomalyAlertQuery::class);
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

it('lists unusual charges newest first rather than in derived-id order', function (): void {
    $userId = (int) $this->user->id;

    foreach (['2026-06-11 09:00:00', '2026-06-12 09:00:00', '2026-06-13 09:00:00', '2026-06-14 09:00:00'] as $detectedAt) {
        anomPageAlert($this->db, $userId, $detectedAt);
    }

    $rows = $this->query->openForUser($this->user);

    $order = array_map(static fn (object $row): string => $row->detectedAt->toDateTimeString(), $rows);

    expect($order)->toBe([
        '2026-06-14 09:00:00',
        '2026-06-13 09:00:00',
        '2026-06-12 09:00:00',
        '2026-06-11 09:00:00',
    ]);
});

it('pages through every alert exactly once when the ids do not ascend', function (): void {
    $userId = (int) $this->user->id;

    $stamps = [
        '2026-06-11 09:00:00',
        '2026-06-12 09:00:00',
        '2026-06-13 09:00:00',
        '2026-06-14 09:00:00',
        '2026-06-15 09:00:00',
    ];
    foreach ($stamps as $detectedAt) {
        anomPageAlert($this->db, $userId, $detectedAt);
    }

    $seen = [];
    $cursorDetectedAt = null;
    $cursorId = null;

    // Two at a time, following the cursor the page hands back, exactly as the
    // "load more" button does.
    for ($page = 0; $page < 5; $page++) {
        $rows = $this->query->openForUser($this->user, $cursorDetectedAt, $cursorId, 2);
        if ($rows === []) {
            break;
        }

        foreach ($rows as $row) {
            $seen[] = $row->anomalyAlertId;
        }

        $last = $rows[count($rows) - 1];
        $cursorDetectedAt = $last->detectedAt->toDateTimeString();
        $cursorId = $last->anomalyAlertId;
    }

    expect($seen)->toHaveCount(5);
    expect(array_unique($seen))->toHaveCount(5);

    $newestFirst = array_map(
        static fn (object $row): int => $row->anomalyAlertId,
        $this->query->openForUser($this->user),
    );
    expect($seen)->toBe($newestFirst);
});

it('breaks a detected_at tie on the id so a page boundary inside the tie is stable', function (): void {
    $userId = (int) $this->user->id;
    $tied = '2026-06-13 09:00:00';

    for ($i = 0; $i < 3; $i++) {
        anomPageAlert($this->db, $userId, $tied);
    }

    $first = $this->query->openForUser($this->user, null, null, 2);
    expect($first)->toHaveCount(2);

    $last = $first[1];
    $second = $this->query->openForUser($this->user, $last->detectedAt->toDateTimeString(), $last->anomalyAlertId, 2);

    $seen = array_merge(
        array_map(static fn (object $row): int => $row->anomalyAlertId, $first),
        array_map(static fn (object $row): int => $row->anomalyAlertId, $second),
    );

    expect($seen)->toHaveCount(3);
    expect(array_unique($seen))->toHaveCount(3);

    // Descending within the tie, so the second page resumes below the first.
    expect($seen[0])->toBeGreaterThan($seen[1]);
    expect($seen[1])->toBeGreaterThan($seen[2]);
});

// The derived id is a 63-bit integer and everything crossing the Livewire
// boundary goes through JSON, whose numbers are IEEE doubles: past 2^53 an id
// returns from the browser rounded, and the action silently hits a row that
// does not exist.

it('mints anomaly ids well past what a JSON number can hold', function (): void {
    $userId = (int) $this->user->id;
    $alertId = anomPageAlert($this->db, $userId, '2026-06-13 09:00:00');

    // Not a hypothetical: prove this id genuinely does not survive a double,
    // so the string carriage below is load-bearing rather than defensive.
    expect($alertId)->toBeGreaterThan(9007199254740991);
    expect((int) (float) $alertId)->not->toBe($alertId);
});

it('acts on the alert the browser named when the id arrives as a string', function (): void {
    $userId = (int) $this->user->id;
    $alertId = anomPageAlert($this->db, $userId, '2026-06-13 09:00:00');

    Livewire::actingAs($this->user)
        ->test(DriftPage::class, ['type' => DriftPageType::Anomaly->value])
        ->call('acknowledgeAnomaly', (string) $alertId)
        ->assertHasNoErrors();

    $state = $this->db->connection()->table('anomaly_alerts')->where('id', $alertId)->value('state');

    expect($state)->toBe('acknowledged');
});

// Following the cursor made "Load more" a REPLACE: page two shared no row
// with page one, the twenty-six already read were gone, and nothing on the
// screen went back. The rows already shown have to survive the press.
it('extends the unusual-charges list rather than replacing it', function (): void {
    $userId = (int) $this->user->id;

    for ($i = 0; $i < 30; $i++) {
        anomPageAlert($this->db, $userId, CarbonImmutable::parse('2026-06-01 09:00:00')->addDays($i)->toDateTimeString());
    }

    $component = Livewire::actingAs($this->user)
        ->test(DriftPage::class, ['type' => DriftPageType::Anomaly->value]);

    $firstPage = array_map(
        static fn (object $row): int => $row->anomalyAlertId,
        $component->viewData('anomalyRows'),
    );
    expect($firstPage)->toHaveCount(26);
    expect($component->viewData('hasMoreAnomalies'))->toBeTrue();

    $component->call('loadMore');

    $extended = array_map(
        static fn (object $row): int => $row->anomalyAlertId,
        $component->viewData('anomalyRows'),
    );

    expect($extended)->toHaveCount(30)
        ->and(array_slice($extended, 0, 26))->toBe($firstPage)
        ->and(array_unique($extended))->toHaveCount(30);
    expect($component->viewData('hasMoreAnomalies'))->toBeFalse();
});

it('offers no load more when the unusual-charges list is exactly one page', function (): void {
    $userId = (int) $this->user->id;

    for ($i = 0; $i < 26; $i++) {
        anomPageAlert($this->db, $userId, CarbonImmutable::parse('2026-06-01 09:00:00')->addDays($i)->toDateTimeString());
    }

    $component = Livewire::actingAs($this->user)
        ->test(DriftPage::class, ['type' => DriftPageType::Anomaly->value]);

    expect($component->viewData('anomalyRows'))->toHaveCount(26);
    expect($component->viewData('hasMoreAnomalies'))->toBeFalse();
});
