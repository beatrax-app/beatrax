<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Modules\Chains\Internal\Jobs\ResolveChainLinksJob;
use Modules\Chains\Internal\Resolvers\IcsSettlementResolver;
use Modules\Chains\Internal\Resolvers\PaypalFundingResolver;
use Modules\Chains\Internal\Resolvers\RetypeByAliasResolver;
use Modules\Chains\Models\ChainResolutionRun;
use Modules\Chains\Public\Contracts\UpsertsCardStatements;
use Modules\Core\Models\User;
use Modules\Core\Public\Contracts\Clock;
use Modules\Core\Public\Enums\JobRunStatus;
use Modules\Transfers\Public\Contracts\PairsTransferLegs;

// ConfirmImport reserves a `pending` chain_resolution_runs row and the wizard
// polls it. The job inserted a SECOND row instead of claiming that one, so the
// reserved row stayed `pending` forever — one dead row per import, and the
// documented "periodic cleanup job" that was to reap them does not exist.

function runTheChainResolutionJob(User $user): void
{
    (new ResolveChainLinksJob($user->id))->handle(
        app(DatabaseManager::class),
        app(Clock::class),
        app(RetypeByAliasResolver::class),
        app(PairsTransferLegs::class),
        app(UpsertsCardStatements::class),
        app(IcsSettlementResolver::class),
        app(PaypalFundingResolver::class),
    );
}

beforeEach(function (): void {
    $this->user = User::query()->create([
        'username' => 'run-claim',
        'password' => 'fixture-password',
        'period_start_day' => 1,
    ]);
});

it('finishes the run row the import reserved instead of leaving it pending beside a new one', function (): void {
    /** @var ChainResolutionRun $reserved */
    $reserved = ChainResolutionRun::query()->create([
        'user_id' => $this->user->id,
        'status' => JobRunStatus::Pending->value,
    ]);

    runTheChainResolutionJob($this->user);

    $rows = ChainResolutionRun::query()->where('user_id', $this->user->id)->get();

    expect($rows)->toHaveCount(1);
    expect($rows[0]->id)->toBe($reserved->id);
    expect($rows[0]->status)->toBe(JobRunStatus::Complete->value);
    expect($rows[0]->started_at)->not->toBeNull();
    expect($rows[0]->completed_at)->not->toBeNull();
});

it('opens its own run row when nothing reserved one', function (): void {
    runTheChainResolutionJob($this->user);

    $rows = ChainResolutionRun::query()->where('user_id', $this->user->id)->get();

    expect($rows)->toHaveCount(1);
    expect($rows[0]->status)->toBe(JobRunStatus::Complete->value);
});

it('closes every reservation a blocked dispatch left behind', function (): void {
    ChainResolutionRun::query()->create([
        'user_id' => $this->user->id,
        'status' => JobRunStatus::Pending->value,
    ]);
    ChainResolutionRun::query()->create([
        'user_id' => $this->user->id,
        'status' => JobRunStatus::Pending->value,
    ]);

    runTheChainResolutionJob($this->user);

    expect(
        ChainResolutionRun::query()
            ->where('user_id', $this->user->id)
            ->where('status', JobRunStatus::Pending->value)
            ->count()
    )->toBe(0);
    expect(ChainResolutionRun::query()->where('user_id', $this->user->id)->count())->toBe(2);
});

it('leaves another user s reservation alone', function (): void {
    $other = User::query()->create([
        'username' => 'run-claim-other',
        'password' => 'fixture-password',
        'period_start_day' => 1,
    ]);
    ChainResolutionRun::query()->create([
        'user_id' => $other->id,
        'status' => JobRunStatus::Pending->value,
    ]);

    runTheChainResolutionJob($this->user);

    expect(
        ChainResolutionRun::query()->where('user_id', $other->id)->firstOrFail()->status
    )->toBe(JobRunStatus::Pending->value);
});
