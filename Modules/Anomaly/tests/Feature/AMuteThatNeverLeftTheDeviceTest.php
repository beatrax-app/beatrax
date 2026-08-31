<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Facades\Event;
use Modules\Anomaly\Internal\AnomalyEvaluator;
use Modules\Anomaly\Models\AnomalyAlert;
use Modules\Anomaly\Public\Actions\DismissAnomalyAlertAsExpected;
use Modules\Anomaly\Public\Actions\RemoveAnomalySuppressionRule;
use Modules\Core\Models\User;
use Modules\Sync\Internal\Config\MergeRulesRegistry;
use Modules\Sync\Public\Events\EntityMutated;

function muteUser(): User
{
    return User::query()->create([
        'username' => 'mute-'.bin2hex(random_bytes(4)),
        'password' => 'fixture',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
        'anomaly_sensitivity_percent' => 50,
        'anomaly_min_amount_minor' => 1000,
    ]);
}

/**
 * @return array{account: int, run: int, counterparty: int}
 */
function muteScaffold(DatabaseManager $db, int $userId): array
{
    $suffix = bin2hex(random_bytes(4));

    return [
        'account' => $db->connection()->table('accounts')->insertGetId([
            'user_id' => $userId, 'name' => 'ASN', 'slug' => 'mute-asn-'.$suffix, 'kind' => 'bank',
            'iban' => 'NL00ASNB'.strtoupper($suffix), 'default_currency' => 'EUR',
            'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00',
        ]),
        'run' => $db->connection()->table('import_runs')->insertGetId([
            'user_id' => $userId, 'source_format' => 'asn-csv', 'raw_file_path' => '/tmp/mute-'.$suffix.'.csv',
            'sha256' => hash('sha256', 'mute-'.$suffix), 'uploaded_at' => '2026-01-01 00:00:00', 'status' => 'previewed',
            'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00',
        ]),
        'counterparty' => $db->connection()->table('counterparties')->insertGetId([
            'user_id' => $userId, 'type' => 'merchant', 'slug' => 'mute-acme-'.$suffix, 'display_name' => 'Acme',
            'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00',
        ]),
    ];
}

function muteTxn(DatabaseManager $db, int $userId, array $s, int $settledMinor, string $postedAt): int
{
    static $i = 0;
    $i++;

    return $db->connection()->table('transactions')->insertGetId([
        'user_id' => $userId, 'account_id' => $s['account'], 'import_run_id' => $s['run'],
        'fingerprint' => hash('sha256', 'mute-'.$i.bin2hex(random_bytes(8))),
        'posted_at' => $postedAt, 'booked_at' => $postedAt.' 00:00:00', 'value_date' => $postedAt,
        'amount_minor' => $settledMinor, 'currency' => 'EUR',
        'settled_amount_minor' => $settledMinor, 'settled_currency' => 'EUR',
        'counterparty_id' => $s['counterparty'], 'counterparty_normalized' => 'acme', 'counterparty_name' => 'ACME',
        'normalization_version' => 1, 'description' => 'acme charge', 'type' => 'expense',
        'source_format' => 'asn-csv', 'source_row_index' => $i, 'fingerprint_version' => 3,
        'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00',
    ]);
}

function muteAlert(User $user): AnomalyAlert
{
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $scaffold = muteScaffold($db, (int) $user->id);

    foreach (['2026-01-10', '2026-02-10', '2026-03-10', '2026-04-10', '2026-05-10'] as $date) {
        muteTxn($db, (int) $user->id, $scaffold, -999, $date);
    }
    $largeTxn = muteTxn($db, (int) $user->id, $scaffold, -2349, '2026-06-15');

    /** @var AnomalyEvaluator $evaluator */
    $evaluator = app(AnomalyEvaluator::class);
    $evaluator->evaluate($largeTxn, $user);

    /** @var AnomalyAlert $alert */
    $alert = AnomalyAlert::query()->where('transaction_id', $largeTxn)->firstOrFail();

    return $alert;
}

/** @return list<EntityMutated> */
function muteRuleOps(): array
{
    return Event::dispatched(EntityMutated::class, static fn (EntityMutated $e): bool => $e->table === 'anomaly_suppression_rules')
        ->map(static fn (array $args): EntityMutated => $args[0])
        ->values()
        ->all();
}

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-06-20 09:00:00');
    $this->user = muteUser();
});

afterEach(fn () => CarbonImmutable::setTestNow());

it('registers merge rules for anomaly_suppression_rules, or every op for one is quarantined', function (): void {
    expect(app(MergeRulesRegistry::class)->isRegistered('anomaly_suppression_rules'))->toBeTrue();
});

it('puts the mute on the op log, so the merchant is muted on the paired device too', function (): void {
    $alert = muteAlert($this->user);
    Event::fake([EntityMutated::class]);

    /** @var DismissAnomalyAlertAsExpected $action */
    $action = app(DismissAnomalyAlertAsExpected::class);
    expect(($action)($alert->id, $this->user))->toBeTrue();

    $ops = muteRuleOps();
    expect($ops)->not->toBeEmpty()
        ->and($ops[0]->mutationType)->toBe('create')
        ->and($ops[0]->userId)->toBe((int) $this->user->id)
        ->and(array_keys($ops[0]->dirtyFields))->toContain('detector', 'direction', 'currency');
});

it('puts the un-mute on the op log too, so an undo does not stay local', function (): void {
    $alert = muteAlert($this->user);
    /** @var DismissAnomalyAlertAsExpected $dismiss */
    $dismiss = app(DismissAnomalyAlertAsExpected::class);
    ($dismiss)($alert->id, $this->user);

    Event::fake([EntityMutated::class]);

    /** @var RemoveAnomalySuppressionRule $undo */
    $undo = app(RemoveAnomalySuppressionRule::class);
    $undo->undoSuppression($alert->id, $this->user);

    $ops = muteRuleOps();
    expect($ops)->not->toBeEmpty()
        ->and($ops[0]->mutationType)->toBe('delete');
});

it('puts a rule deleted from the settings screen on the op log', function (): void {
    $alert = muteAlert($this->user);
    /** @var DismissAnomalyAlertAsExpected $dismiss */
    $dismiss = app(DismissAnomalyAlertAsExpected::class);
    ($dismiss)($alert->id, $this->user);

    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $ruleId = (int) $db->connection()->table('anomaly_suppression_rules')
        ->where('user_id', $this->user->id)->value('id');

    Event::fake([EntityMutated::class]);

    /** @var RemoveAnomalySuppressionRule $remove */
    $remove = app(RemoveAnomalySuppressionRule::class);
    $remove->removeRule($ruleId, $this->user);

    $ops = muteRuleOps();
    expect($ops)->not->toBeEmpty()
        ->and($ops[0]->mutationType)->toBe('delete')
        ->and($ops[0]->pk)->toBe($ruleId);
});
