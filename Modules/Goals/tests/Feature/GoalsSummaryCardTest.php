<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Core\Public\Support\Lang;
use Modules\Goals\Internal\Http\Livewire\GoalsPage;
use Modules\Goals\Models\Goal;
use Modules\Goals\Public\Dto\GoalProgressRow;
use Modules\Goals\Public\Http\Livewire\GoalsSummaryCard;
use Modules\Goals\Public\Services\GoalContributionWriter;
use Modules\Goals\Public\Services\GoalProgressQuery;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Models\ImportRun;
use Modules\Ledger\Models\Transaction;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->user = User::create([
        'username' => 'wessel',
        'password' => 'opensesame',
        'period_start_day' => 1,
    ]);
    $this->actingAs($this->user);

    $this->account = Account::create([
        'user_id' => $this->user->id,
        'name' => 'ASN',
        'slug' => 'asn',
        'kind' => 'bank',
        'iban' => 'NL57ASNB0123456789',
        'default_currency' => 'EUR',
    ]);

    $this->run = ImportRun::create([
        'user_id' => $this->user->id,
        'source_format' => 'camt053',
        'raw_file_path' => '/tmp/summary.xml',
        'sha256' => str_repeat('d', 64),
        'uploaded_at' => CarbonImmutable::now(),
        'status' => 'previewed',
    ]);
});

function summaryCardCredit(User $user, int $accountId, int $amountMinor): Transaction
{
    return Transaction::create([
        'user_id' => $user->id,
        'account_id' => $accountId,
        'type' => 'transfer_in',
        'posted_at' => CarbonImmutable::now()->subDays(10)->toDateString(),
        'booked_at' => CarbonImmutable::now()->subDays(10)->toDateString().' 12:00:00',
        'value_date' => CarbonImmutable::now()->subDays(10)->toDateString(),
        'amount_minor' => $amountMinor,
        'currency' => 'EUR',
        'settled_amount_minor' => $amountMinor,
        'settled_currency' => 'EUR',
        'counterparty_name' => 'Savings',
        'counterparty_normalized' => 'savings',
        'normalization_version' => 1,
        'source_format' => 'camt053',
        'import_run_id' => ImportRun::query()->where('user_id', $user->id)->value('id'),
        'source_row_index' => 1,
        'fingerprint' => str_repeat('e', 64),
        'fingerprint_version' => 1,
    ]);
}

it('renders the summary card and sorts goals without a projection last', function (): void {
    // Two goals exercise the null-last comparator: only the funded one can carry
    // a projection, so the untouched goal's null has to sort behind it. The
    // assertion is the ORDER; two assertSee calls passed whichever way it sorted.
    $funded = Goal::factory()->create([
        'user_id' => $this->user->id,
        'name' => 'Funded goal',
        'target_minor' => 100000,
        'start_date' => CarbonImmutable::now()->subDays(30)->toDateString(),
        'target_date' => CarbonImmutable::now()->addYearNoOverflow()->toDateString(),
        'status' => 'active',
    ]);
    // Created first, so it precedes the funded goal in the query's id order and
    // only the comparator can move it behind.
    Goal::factory()->create([
        'user_id' => $this->user->id,
        'name' => 'Untouched goal',
        'target_minor' => 50000,
        'start_date' => CarbonImmutable::now()->subDays(30)->toDateString(),
        'status' => 'active',
    ]);

    $tx = summaryCardCredit($this->user, $this->account->id, 20000);
    app(GoalContributionWriter::class)->attribute($this->user, $funded->id, $tx->id);

    $html = (string) Livewire::test(GoalsSummaryCard::class)->assertOk()->html();

    expect($html)->toContain('Funded goal')
        ->and($html)->toContain('Untouched goal')
        ->and(strpos($html, 'Funded goal'))->toBeLessThan((int) strpos($html, 'Untouched goal'));
});

// One fact, two surfaces. The tile printed a beyond-horizon estimate as a bare
// hard date while /goals qualified the same date with "(projection)", and it
// printed it in a different format again -- "24 Feb '27" against "24 Feb 2027".
it('qualifies a beyond-horizon estimate and dates it the way /goals does', function (): void {
    $goal = Goal::factory()->create([
        'user_id' => $this->user->id,
        'name' => 'Verre reis',
        'target_minor' => 1000000,
        'start_date' => CarbonImmutable::now()->subDays(60)->toDateString(),
        'target_date' => CarbonImmutable::now()->addYearNoOverflow()->toDateString(),
        'status' => 'active',
    ]);

    $tx = summaryCardCredit($this->user, $this->account->id, 10000);
    app(GoalContributionWriter::class)->attribute($this->user, $goal->id, $tx->id);

    $row = app(GoalProgressQuery::class)->forUser($this->user)[0];

    expect($row->projectedFinishDate)->not->toBeNull()
        ->and($row->projectionBeyondHorizon)->toBeTrue();

    $expectedDate = CarbonImmutable::parse((string) $row->projectedFinishDate)
        ->isoFormat(GoalProgressRow::DATE_FORMAT);

    Livewire::test(GoalsSummaryCard::class)
        ->assertOk()
        ->assertSee($expectedDate)
        ->assertSee(Lang::get('goals::messages.projection.projection_note'));

    // The same row, rendered by /goals, carries the same date and the same
    // qualifier -- so the two surfaces cannot drift on either again.
    Livewire::test(GoalsPage::class)
        ->assertOk()
        ->assertSee($expectedDate)
        ->assertSee(Lang::get('goals::messages.projection.projection_note'));
});

it('draws the tile bar through the row rule the goals list uses', function (): void {
    $goal = Goal::factory()->create([
        'user_id' => $this->user->id,
        'name' => 'Kleine start',
        'target_minor' => 1000000,
        'start_date' => CarbonImmutable::now()->subDays(30)->toDateString(),
        'target_date' => CarbonImmutable::now()->addYearNoOverflow()->toDateString(),
        'status' => 'active',
    ]);

    // 0.4% — a real share the percentage floors to zero, so the tile drew no
    // bar at all while computing the sliver rule under its own name.
    $tx = summaryCardCredit($this->user, $this->account->id, 4000);
    app(GoalContributionWriter::class)->attribute($this->user, $goal->id, $tx->id);

    $row = app(GoalProgressQuery::class)->forUser($this->user)[0];

    expect($row->percentComplete())->toBe(0)
        ->and($row->barWidth())->toBe(2);

    expect((string) Livewire::test(GoalsSummaryCard::class)->html())
        ->toContain('aria-valuenow="2"');
});
