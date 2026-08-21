<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Database\DatabaseManager;
use Illuminate\Queue\Events\JobFailed;
use Modules\Chains\Internal\Jobs\ResolveChainLinksJob;
use Modules\Chains\Internal\Resolvers\IcsSettlementResolver;
use Modules\Chains\Internal\Resolvers\PaypalFundingResolver;
use Modules\Chains\Internal\Resolvers\RetypeByAliasResolver;
use Modules\Chains\Models\ChainResolutionRun;
use Modules\Chains\Public\Contracts\UpsertsCardStatements;
use Modules\Core\Models\User;
use Modules\Core\Public\Contracts\Clock;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Models\ImportRun;
use Modules\Transfers\Public\Contracts\PairsTransferLegs;

// The payload's serialised `command` must carry the userId in the
// `userId;i:N` shape the listener's regex looks for.
function makeLifecycleFakeJob(int $userId, string $jobId): Job
{
    return new class($userId, $jobId) implements Job
    {
        public function __construct(private int $userId, private string $jobId) {}

        public function resolveName(): string
        {
            return 'Modules\\Chains\\Internal\\Jobs\\ResolveChainLinksJob';
        }

        public function resolveQueuedJobClass(): string
        {
            return 'Modules\\Chains\\Internal\\Jobs\\ResolveChainLinksJob';
        }

        public function payload(): array
        {
            return [
                'data' => [
                    'command' => sprintf(
                        'O:53:"Modules\\Chains\\Internal\\Jobs\\ResolveChainLinksJob":1:{s:6:"userId";i:%d;}',
                        $this->userId,
                    ),
                ],
            ];
        }

        public function fire(): void {}

        public function release($delay = 0): void
        {
            unset($delay);
        }

        public function isReleased(): bool
        {
            return false;
        }

        public function isDeleted(): bool
        {
            return false;
        }

        public function isDeletedOrReleased(): bool
        {
            return false;
        }

        public function attempts(): int
        {
            return 3;
        }

        public function hasFailed(): bool
        {
            return true;
        }

        public function markAsFailed(): void {}

        public function fail($e = null): void
        {
            unset($e);
        }

        public function maxTries(): ?int
        {
            return 3;
        }

        public function maxExceptions(): ?int
        {
            return null;
        }

        public function retryUntil(): ?int
        {
            return null;
        }

        public function timeout(): ?int
        {
            return null;
        }

        public function uuid(): ?string
        {
            return null;
        }

        public function getName(): string
        {
            return 'Modules\\Chains\\Internal\\Jobs\\ResolveChainLinksJob';
        }

        public function getJobId(): ?string
        {
            return $this->jobId;
        }

        public function getRawBody(): string
        {
            return '';
        }

        public function getQueue(): string
        {
            return 'default';
        }

        public function getConnectionName(): string
        {
            return 'sync';
        }

        public function delete(): void {}
    };
}

beforeEach(function (): void {
    $this->user = User::query()->create([
        'username' => 'lifecycle',
        'password' => 'fixture-password',
        'period_start_day' => 1,
    ]);
    $this->otherUser = User::query()->create([
        'username' => 'lifecycle-other',
        'password' => 'fixture-password',
        'period_start_day' => 1,
    ]);
    $this->icsAccount = Account::query()->create([
        'user_id' => $this->user->id,
        'name' => 'ICS lifecycle',
        'slug' => 'ics-lifecycle',
        'kind' => 'ics_card',
        'iban' => 'ICS-CARD',
        'default_currency' => 'EUR',
    ]);
    $this->run = ImportRun::query()->create([
        'user_id' => $this->user->id,
        'source_format' => 'ics-pdf',
        'raw_file_path' => '/tmp/lifecycle.pdf',
        'sha256' => str_repeat('l', 64),
        'uploaded_at' => CarbonImmutable::now(),
        'status' => 'previewed',
    ]);
});

it('transitions chain_resolution_runs from running to complete on successful handle()', function (): void {
    $job = new ResolveChainLinksJob($this->user->id);
    $job->handle(
        $this->app->make(DatabaseManager::class),
        $this->app->make(Clock::class),
        $this->app->make(RetypeByAliasResolver::class),
        $this->app->make(PairsTransferLegs::class),
        $this->app->make(UpsertsCardStatements::class),
        $this->app->make(IcsSettlementResolver::class),
        $this->app->make(PaypalFundingResolver::class),
    );

    /** @var ChainResolutionRun $row */
    $row = ChainResolutionRun::query()
        ->where('user_id', $this->user->id)
        ->latest('id')
        ->firstOrFail();
    expect($row->status)->toBe('complete');
    expect($row->started_at)->not->toBeNull();
    expect($row->completed_at)->not->toBeNull();
    expect((int) $row->linked_count)->toBe(0);
});

it('transitions chain_resolution_runs to failed when the JobFailed event fires', function (): void {
    $db = $this->app->make(DatabaseManager::class);
    /** @var Clock $clock */
    $clock = $this->app->make(Clock::class);
    $now = $clock->now()->toDateTimeString();
    $db->connection()->table('chain_resolution_runs')->insert([
        'user_id' => $this->user->id,
        'status' => 'running',
        'started_at' => $now,
        'linked_count' => 0,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    $fakeJob = makeLifecycleFakeJob($this->user->id, 'test-uuid');

    $exception = new RuntimeException('Synthetic resolver failure for lifecycle test');
    $event = new JobFailed('sync', $fakeJob, $exception);

    /** @var Dispatcher $events */
    $events = $this->app->make(Dispatcher::class);
    $events->dispatch($event);

    /** @var ChainResolutionRun $row */
    $row = ChainResolutionRun::query()
        ->where('user_id', $this->user->id)
        ->latest('id')
        ->firstOrFail();
    expect($row->status)->toBe('failed');
    expect($row->last_error)->not->toBeNull();
    expect((string) $row->last_error)->toContain('RuntimeException');
    expect((string) $row->last_error)->toContain('Synthetic resolver failure');
    expect($row->completed_at)->not->toBeNull();
});

it('cross-user isolation: a failed-event for user A does not touch user B running rows', function (): void {
    $db = $this->app->make(DatabaseManager::class);
    $clock = $this->app->make(Clock::class);
    $now = $clock->now()->toDateTimeString();

    $db->connection()->table('chain_resolution_runs')->insert([
        'user_id' => $this->user->id,
        'status' => 'running',
        'started_at' => $now,
        'linked_count' => 0,
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    $db->connection()->table('chain_resolution_runs')->insert([
        'user_id' => $this->otherUser->id,
        'status' => 'running',
        'started_at' => $now,
        'linked_count' => 0,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    $fakeJob = makeLifecycleFakeJob($this->user->id, 'iso-uuid');

    /** @var Dispatcher $events */
    $events = $this->app->make(Dispatcher::class);
    $events->dispatch(new JobFailed('sync', $fakeJob, new RuntimeException('Isolated failure')));

    /** @var ChainResolutionRun $userARow */
    $userARow = ChainResolutionRun::query()
        ->where('user_id', $this->user->id)
        ->latest('id')
        ->firstOrFail();
    expect($userARow->status)->toBe('failed');

    /** @var ChainResolutionRun $userBRow */
    $userBRow = ChainResolutionRun::query()
        ->where('user_id', $this->otherUser->id)
        ->latest('id')
        ->firstOrFail();
    expect($userBRow->status)->toBe('running');
});
