<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Event;
use Modules\Import\Internal\Exceptions\ImportAlreadyConfirmedException;
use Modules\Import\Public\Actions\DiscardImport;
use Modules\Ledger\Models\ImportRun;
use Modules\Ledger\Public\Enums\ImportRunStatus;
use Modules\Sync\Public\Events\EntityMutated;

// `import_runs.status` is a merged column and the pairing backfill carries every
// run whatever its status, so a peer holds the previewed row. The discard was
// written straight onto the model in hand — a shape the column guard cannot read
// — and told nobody, leaving the other device offering to resume a preview the
// reader had already thrown away.

/** @return list<EntityMutated> the import_runs edits this action announced */
function discardAnnouncements(): array
{
    $seen = [];

    foreach (Event::dispatched(EntityMutated::class) as $dispatch) {
        $event = $dispatch[0];

        if ($event instanceof EntityMutated && $event->table === 'import_runs') {
            $seen[] = $event;
        }
    }

    return $seen;
}

beforeEach(function (): void {
    $this->seedFixtureUserAndAccount();
});

it('announces the discard, so the peer stops offering to resume the preview', function (): void {
    Event::fake([EntityMutated::class]);

    /** @var ImportRun $run */
    $run = ImportRun::query()->create([
        'user_id' => $this->fixtureUser->id,
        'source_format' => 'asn-csv',
        'raw_file_path' => '/tmp/discard-announce.csv',
        'sha256' => str_repeat('d', 64),
        'uploaded_at' => CarbonImmutable::now(),
        'status' => ImportRunStatus::Previewed->value,
    ]);

    $this->app->make(DiscardImport::class)($run->id, $this->fixtureUser);

    $announced = discardAnnouncements();

    expect(ImportRun::query()->find($run->id)?->status)->toBe(ImportRunStatus::Discarded->value)
        ->and($announced)->toHaveCount(1)
        ->and($announced[0]->pk)->toBe($run->id)
        ->and($announced[0]->userId)->toBe($this->fixtureUser->id)
        ->and($announced[0]->mutationType)->toBe('edit')
        ->and($announced[0]->dirtyFields)->toBe(['status' => ImportRunStatus::Discarded->value]);
});

it('tells the peer nothing when the run was already confirmed', function (): void {
    Event::fake([EntityMutated::class]);

    /** @var ImportRun $run */
    $run = ImportRun::query()->create([
        'user_id' => $this->fixtureUser->id,
        'source_format' => 'asn-csv',
        'raw_file_path' => '/tmp/discard-confirmed.csv',
        'sha256' => str_repeat('e', 64),
        'uploaded_at' => CarbonImmutable::now(),
        'confirmed_at' => CarbonImmutable::now(),
        'status' => ImportRunStatus::Confirmed->value,
    ]);

    // The action refuses before it writes, so there is no change to announce —
    // an op here would tell the peer a run had been discarded that had not.
    expect(fn () => $this->app->make(DiscardImport::class)($run->id, $this->fixtureUser))->toThrow(ImportAlreadyConfirmedException::class);

    expect(discardAnnouncements())->toBe([])
        ->and(ImportRun::query()->find($run->id)?->status)->toBe(ImportRunStatus::Confirmed->value);
});
