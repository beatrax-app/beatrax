<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Modules\Core\Public\Services\UserDataPathService;
use Modules\Notifications\Public\Events\NotificationPreferenceMutated;
use Modules\Sync\Internal\Http\Middleware\DrainsDeferredOpCaptures;
use Modules\Sync\Internal\Listeners\SyncCaptureListener;
use Modules\Sync\Internal\OpLog\DeferredOpCaptures;
use Modules\Sync\Public\Events\EntityMutated;
use Modules\Sync\Public\Events\EnvelopeAssignmentMutated;
use Modules\Sync\Public\Events\EnvelopeMoveMutated;
use Modules\Sync\Public\Events\EnvelopeSettingMutated;
use Modules\Sync\Public\Events\GoalContributionMutated;
use Modules\Sync\Public\Events\GoalMutated;
use Modules\Sync\Public\Events\NotificationMutated;
use Modules\Sync\Public\Events\SavedReportMutated;
use Modules\Sync\Public\Events\TransactionMutated;
use Modules\Sync\Public\Events\TransactionSplitMutated;

// The guard for the shape, not for one bug. Ten of the eleven handlers here
// swallowed an unavailable writer and dropped the mutation for good; a new
// twelfth would have joined them in silence. The map below is checked against
// the live class, so a handler nobody added here fails this file.
const DEFERRING_CAPTURE_USER = 4242;

/**
 * @return array<string, array{table: string, dispatch: Closure(SyncCaptureListener): void}>
 */
function everyCapturePath(): array
{
    $userId = DEFERRING_CAPTURE_USER;

    return [
        'handle' => [
            'table' => 'transactions',
            'dispatch' => fn (SyncCaptureListener $l) => $l->handle(
                new TransactionMutated(11, $userId, 'create', ['amount_minor' => -250]),
            ),
        ],
        'handleSplit' => [
            'table' => 'transaction_splits',
            'dispatch' => fn (SyncCaptureListener $l) => $l->handleSplit(
                new TransactionSplitMutated(12, 11, $userId, 'create', ['amount_minor' => -250]),
            ),
        ],
        'handleEnvelopeAssignment' => [
            'table' => 'envelope_assignments',
            'dispatch' => fn (SyncCaptureListener $l) => $l->handleEnvelopeAssignment(
                new EnvelopeAssignmentMutated(13, $userId, 'create', ['envelope_id' => 1]),
            ),
        ],
        'handleEnvelopeMove' => [
            'table' => 'envelope_moves',
            'dispatch' => fn (SyncCaptureListener $l) => $l->handleEnvelopeMove(
                new EnvelopeMoveMutated(14, $userId, 'create', ['amount_minor' => 100]),
            ),
        ],
        'handleEnvelopeSetting' => [
            'table' => 'envelope_settings',
            'dispatch' => fn (SyncCaptureListener $l) => $l->handleEnvelopeSetting(
                new EnvelopeSettingMutated(15, $userId, 'create', ['rollover' => 1]),
            ),
        ],
        'handleGoalContribution' => [
            'table' => 'goal_contributions',
            'dispatch' => fn (SyncCaptureListener $l) => $l->handleGoalContribution(
                new GoalContributionMutated(16, $userId, 'create', ['goal_id' => 1]),
            ),
        ],
        'handleGoal' => [
            'table' => 'goals',
            'dispatch' => fn (SyncCaptureListener $l) => $l->handleGoal(
                new GoalMutated(17, $userId, 'create', ['name' => 'Roof']),
            ),
        ],
        'handleEntity' => [
            'table' => 'recurring_series',
            'dispatch' => fn (SyncCaptureListener $l) => $l->handleEntity(
                new EntityMutated('recurring_series', 18, $userId, 'edit', ['billing_day' => 7]),
            ),
        ],
        'handleSavedReport' => [
            'table' => 'saved_reports',
            'dispatch' => fn (SyncCaptureListener $l) => $l->handleSavedReport(
                new SavedReportMutated(19, $userId, 'create', ['name' => 'Q3']),
            ),
        ],
        'handleNotificationMutated' => [
            'table' => 'notifications',
            'dispatch' => fn (SyncCaptureListener $l) => $l->handleNotificationMutated(
                new NotificationMutated('sha-1', $userId, 'create', ['title' => 'x']),
            ),
        ],
        'handleNotificationPreferenceMutated' => [
            'table' => 'notification_preferences',
            'dispatch' => fn (SyncCaptureListener $l) => $l->handleNotificationPreferenceMutated(
                new NotificationPreferenceMutated(20, $userId, 'create', ['channel' => 'in_app']),
            ),
        ],
    ];
}

// A key-file this device cannot open is the deferring state, and it is the one
// a test can build without minting a real identity: exists() answers true from
// the filesystem alone, and every attempt to unseal it fails.
function anIdentityThisDeviceCannotOpen(int $userId): string
{
    $path = UserDataPathService::appPath("sync/identity/{$userId}.enc");

    if (! is_dir(dirname($path))) {
        mkdir(dirname($path), 0700, true);
    }

    file_put_contents($path, 'sealed under a key no session here holds');

    return $path;
}

afterEach(function (): void {
    $path = UserDataPathService::appPath('sync/identity/'.DEFERRING_CAPTURE_USER.'.enc');

    if (file_exists($path)) {
        unlink($path);
    }
});

it('names every capture path the listener actually has', function (): void {
    $handlers = [];

    foreach ((new ReflectionClass(SyncCaptureListener::class))->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
        if (! $method->isConstructor()) {
            $handlers[] = $method->getName();
        }
    }

    sort($handlers);
    $named = array_keys(everyCapturePath());
    sort($named);

    expect($named)->toBe($handlers);
});

it('defers a mutation it cannot sign instead of dropping it', function (string $handler, string $table): void {
    anIdentityThisDeviceCannotOpen(DEFERRING_CAPTURE_USER);

    /** @var SyncCaptureListener $listener */
    $listener = app(SyncCaptureListener::class);

    everyCapturePath()[$handler]['dispatch']($listener);

    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    expect(
        $db->connection()->table('deferred_op_captures')
            ->where('user_id', DEFERRING_CAPTURE_USER)
            ->where('table_name', $table)
            ->exists()
    )->toBeTrue();
})->with(array_map(
    static fn (string $handler): array => [$handler, everyCapturePath()[$handler]['table']],
    array_keys(everyCapturePath()),
));

// The deliberate exception, and the reason the queue is not simply always
// written: an install that never switched sync on owes no peer anything, and
// enabling it captures the whole database in one walk.
it('records nothing at all on a device that never enabled sync', function (): void {
    /** @var SyncCaptureListener $listener */
    $listener = app(SyncCaptureListener::class);

    $listener->handleEntity(new EntityMutated('recurring_series', 18, DEFERRING_CAPTURE_USER, 'edit', [
        'billing_day' => 7,
    ]));

    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    expect($db->connection()->table('deferred_op_captures')->count())->toBe(0);
});

// The columns the merge registry keeps off the wire are filtered before the
// sink is reached, so a device-local column must not even be owed: a peer that
// received `password` would take over the login.
it('does not defer a column the registry keeps off the wire', function (): void {
    anIdentityThisDeviceCannotOpen(DEFERRING_CAPTURE_USER);

    /** @var SyncCaptureListener $listener */
    $listener = app(SyncCaptureListener::class);

    $listener->handleEntity(new EntityMutated('users', DEFERRING_CAPTURE_USER, DEFERRING_CAPTURE_USER, 'edit', [
        'password' => 'hunter2',
        'default_currency_view' => 'eur_only',
    ]));

    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    $deferred = $db->connection()->table('deferred_op_captures')
        ->where('table_name', 'users')
        ->pluck('field')
        ->all();

    expect($deferred)->toBe(['default_currency_view']);
});

it('coalesces a thousand writes to one column into one owed coordinate', function (): void {
    anIdentityThisDeviceCannotOpen(DEFERRING_CAPTURE_USER);

    /** @var SyncCaptureListener $listener */
    $listener = app(SyncCaptureListener::class);

    foreach (range(1, 1000) as $day) {
        $listener->handleEntity(new EntityMutated('recurring_series', 18, DEFERRING_CAPTURE_USER, 'edit', [
            'billing_day' => $day,
        ]));
    }

    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    expect($db->connection()->table('deferred_op_captures')->count())->toBe(1);
});

// A g_counter column stores the total merged across every device, so the delta
// is the one quantity a re-read cannot recover. Deferred increments accumulate
// rather than collapsing, or a locked week of categorising counts once.
it('sums the deltas of a deferred g_counter increment', function (): void {
    anIdentityThisDeviceCannotOpen(DEFERRING_CAPTURE_USER);

    /** @var SyncCaptureListener $listener */
    $listener = app(SyncCaptureListener::class);

    foreach (range(1, 5) as $ignored) {
        $listener->handleEntity(new EntityMutated(
            table: 'merchant_memories',
            pk: 21,
            userId: DEFERRING_CAPTURE_USER,
            mutationType: 'edit',
            dirtyFields: ['occurrence_count' => 1],
            incrementFields: ['occurrence_count'],
        ));
    }

    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    $owed = $db->connection()->table('deferred_op_captures')
        ->where('table_name', 'merchant_memories')
        ->where('field', 'occurrence_count')
        ->first();

    expect($owed)->not->toBeNull()
        ->and((int) $owed->delta)->toBe(5);
});

it('stops owing coordinates once the queue is full and owes a whole-database backfill instead', function (): void {
    anIdentityThisDeviceCannotOpen(DEFERRING_CAPTURE_USER);

    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    $rows = [];

    foreach (range(1, DeferredOpCaptures::MAX_PENDING_ENTRIES) as $n) {
        $rows[] = [
            'user_id' => DEFERRING_CAPTURE_USER,
            'table_name' => 'transactions',
            'pk' => (string) $n,
            'field' => 'amount_minor',
            'op_kind' => 'set',
            'delta' => null,
            'captured_at' => '2026-09-04T00:00:00Z',
        ];
    }

    foreach (array_chunk($rows, 1000) as $chunk) {
        $db->connection()->table('deferred_op_captures')->insert($chunk);
    }

    /** @var SyncCaptureListener $listener */
    $listener = app(SyncCaptureListener::class);

    $listener->handleEntity(new EntityMutated('recurring_series', 18, DEFERRING_CAPTURE_USER, 'edit', [
        'billing_day' => 7,
    ]));

    expect($db->connection()->table('deferred_op_captures')->count())
        ->toBe(DeferredOpCaptures::MAX_PENDING_ENTRIES)
        ->and(
            $db->connection()->table('sync_backfill_state')
                ->where('user_id', DEFERRING_CAPTURE_USER)
                ->whereNull('completed_at')
                ->exists()
        )->toBeTrue();
});

// A queue with no driver is the same lost mutation one layer along. The phone
// runs no daemon and no scheduler that can sign, and on the desktop `sync:serve`
// and the queue worker are consoles too — so a request is the only thing on
// either root that ever reaches this.
it('is driven from the web group of whichever root is running', function (): void {
    $web = app('router')->getMiddlewareGroups()['web'] ?? [];

    expect($web)->toContain(DrainsDeferredOpCaptures::class)
        ->and(method_exists(DrainsDeferredOpCaptures::class, 'terminate'))->toBeTrue();
});

it('is registered by BOTH roots, not only the one running this suite', function (): void {
    $roots = [
        dirname(__DIR__, 4).'/bootstrap/app.php',
        dirname(__DIR__, 4).'/mobile-app/bootstrap/app.php',
    ];

    foreach ($roots as $root) {
        expect(file_get_contents($root))->toContain('DrainsDeferredOpCaptures::class');
    }
});
