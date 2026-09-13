<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Modules\Core\Public\Services\UserDataPathService;
use Modules\Sync\Internal\Listeners\SyncCaptureListener;
use Modules\Sync\Internal\OpLog\OpCaptureSinkFactory;
use Modules\Sync\Public\Events\EntityMutated;
use Modules\Sync\Tests\Support\RecordingLogger;

// Sync standing is read per reader and a household shares one install, so the
// member who paired a device is owed every write while the member who never
// did is dropped — same device, same scheduled pass, same table. The dropped
// half is correct; a line naming neither the reader nor the standing is not.
const PAIRED_HOUSEHOLD_READER = 8801;

const UNPAIRED_HOUSEHOLD_READER = 8802;

// The deferring state a test can build without minting a real identity:
// exists() answers true off the filesystem alone and every unseal fails.
function anIdentityThisHouseholdCannotOpen(int $userId): void
{
    $path = UserDataPathService::appPath(sprintf('sync/identity/%d.enc', $userId));

    if (! is_dir(dirname($path))) {
        mkdir(dirname($path), 0700, true);
    }

    file_put_contents($path, 'sealed under a key no session here holds');
}

afterEach(function (): void {
    foreach ([PAIRED_HOUSEHOLD_READER, UNPAIRED_HOUSEHOLD_READER] as $userId) {
        $path = UserDataPathService::appPath('sync/identity/'.$userId.'.enc');

        if (file_exists($path)) {
            unlink($path);
        }
    }
});

// The live shape, reproduced: one scheduled pass writes for both members of a
// household and only one of them owes anything afterwards. Reading the op log
// alone, the other member's rows are indistinguishable from a lost write.
it('owes the paired reader and drops the unpaired one in the same pass', function (): void {
    anIdentityThisHouseholdCannotOpen(PAIRED_HOUSEHOLD_READER);

    /** @var SyncCaptureListener $listener */
    $listener = app(SyncCaptureListener::class);

    foreach ([PAIRED_HOUSEHOLD_READER, UNPAIRED_HOUSEHOLD_READER] as $userId) {
        $listener->handleEntity(new EntityMutated('recurring_series', 18, $userId, 'create', [
            'billing_day' => 7,
        ]));
    }

    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    $owed = $db->connection()->table('deferred_op_captures')->pluck('user_id')->map(
        static fn (mixed $id): int => (int) $id,
    )->all();

    expect($owed)->toBe([PAIRED_HOUSEHOLD_READER]);
});

it('names the reader whose write it dropped', function (): void {
    $log = new RecordingLogger;

    (new OpCaptureSinkFactory(app(), $log))
        ->forUser(UNPAIRED_HOUSEHOLD_READER)
        ->writeCreateRow('recurring_series', '6122352231045571192', ['billing_day' => 7]);

    $dropped = array_values(array_filter(
        $log->lines,
        static fn (array $line): bool => str_contains($line['message'], 'SyncOffOpSink'),
    ));

    expect($dropped)->toHaveCount(1)
        ->and($dropped[0]['context']['user_id'] ?? null)->toBe(
            UNPAIRED_HOUSEHOLD_READER,
            'a dropped capture that does not say whose it was is only readable by joining every pk back to its row',
        )
        ->and($dropped[0]['message'])->toContain('for this reader');
});
