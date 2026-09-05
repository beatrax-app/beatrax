<?php

declare(strict_types=1);

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\DatabaseManager;
use Modules\Core\Models\User;
use Modules\Core\Public\Scheduling\MobileBackgroundSchedule;
use Modules\Migration\Internal\Actions\StartMigrationRun;
use Modules\Migration\Models\MigrationRun;
use Modules\Migration\Tests\Support\MigrationFixturePaths;

// The sweeper was written, owner-scoped and covered by its own tests, and no
// production caller ever existed: a reader who opened the wizard, saw the
// preview and closed the tab left a whole export's staging copy behind for
// good. Only the wizard's discard button reclaimed anything, and an abandoned
// run is by definition one nobody came back to press it on.

function sweepFindScheduledEvent(string $description): ?Event
{
    /** @var Schedule $schedule */
    $schedule = app(Schedule::class);

    foreach ($schedule->events() as $event) {
        /** @var Event $event */
        if ($event->description === $description) {
            return $event;
        }
    }

    return null;
}

function sweepStartRunFor(User $user): MigrationRun
{
    return app(StartMigrationRun::class)->__invoke(
        $user,
        'ynab4',
        MigrationFixturePaths::ynab4Dir('v1'),
        'Beatrax Test Budget.zip',
    );
}

function sweepAgeRun(MigrationRun $run, int $days): void
{
    MigrationRun::query()->where('id', $run->id)->update(['created_at' => now()->subDays($days)]);
}

beforeEach(function (): void {
    $this->user = User::create([
        'username' => 'abandoned-sweep-owner',
        'password' => 'opensesame',
        'period_start_day' => 1,
    ]);
    $this->db = app(DatabaseManager::class);
});

it('reclaims a run abandoned past the threshold and leaves an unfinished one from today alone', function (): void {
    $stale = sweepStartRunFor($this->user);
    sweepAgeRun($stale, 2);

    $today = sweepStartRunFor($this->user);

    expect($this->db->connection()->table('migration_staging_transactions')->where('migration_run_id', $stale->id)->count())
        ->toBeGreaterThan(0, 'the fixture staged no rows, so this case would pass without reclaiming anything');

    $this->artisan('migration:sweep-abandoned')->assertSuccessful();

    expect(MigrationRun::query()->where('id', $stale->id)->exists())->toBeFalse()
        ->and($this->db->connection()->table('migration_staging_transactions')->where('migration_run_id', $stale->id)->count())->toBe(0)
        ->and($this->db->connection()->table('migration_staging_categories')->where('migration_run_id', $stale->id)->count())->toBe(0)
        ->and(MigrationRun::query()->where('id', $today->id)->exists())->toBeTrue();
});

// The sweeper takes one user and the command walks all of them, so the scope
// that held inside the action is the thing a per-user loop can lose. A shared
// household install has two rows in `users`, and one reader's abandoned run is
// not the other reader's to reclaim.
it('sweeps each reader against their own runs, never another household member\'s', function (): void {
    $other = User::create([
        'username' => 'abandoned-sweep-other',
        'password' => 'opensesame',
        'period_start_day' => 1,
    ]);

    $mine = sweepStartRunFor($this->user);
    sweepAgeRun($mine, 2);

    $theirs = sweepStartRunFor($other);
    sweepAgeRun($theirs, 2);

    $this->artisan('migration:sweep-abandoned')->assertSuccessful();

    expect(MigrationRun::query()->where('id', $mine->id)->exists())->toBeFalse()
        ->and(MigrationRun::query()->where('id', $theirs->id)->exists())->toBeFalse();

    expect($this->db->connection()->table('migration_staging_transactions')->where('migration_run_id', $theirs->id)->count())
        ->toBe(0, "the other reader's staging survived a sweep that deleted their run row");

    expect(MigrationRun::query()->where('user_id', $this->user->id)->count())->toBe(0)
        ->and(MigrationRun::query()->where('user_id', $other->id)->count())->toBe(0);
});

// `migration_runs.user_id` is nullable, so a row owned by nobody is reachable
// by an unscoped bulk delete and by nothing else. Surviving a sweep that
// reclaimed everything beside it is what tells a per-owner walk apart from a
// truncate wearing a loop.
it('leaves a stale run no enumerated reader owns exactly where it was', function (): void {
    $mine = sweepStartRunFor($this->user);
    sweepAgeRun($mine, 2);

    $this->db->connection()->table('migration_runs')->insert([
        'user_id' => null,
        'source_product' => 'ynab4',
        'original_filename' => 'owned-by-nobody.zip',
        'status' => 'parsed',
        'created_at' => now()->subDays(2)->toDateTimeString(),
        'updated_at' => now()->subDays(2)->toDateTimeString(),
    ]);

    $this->artisan('migration:sweep-abandoned')->assertSuccessful();

    expect(MigrationRun::query()->where('id', $mine->id)->exists())->toBeFalse()
        ->and($this->db->connection()->table('migration_runs')->whereNull('user_id')->count())
        ->toBe(1, 'the sweep reached a row no enumerated owner claims, so it is not owner-scoped');
});

// Daily, and as a Schedule::command() rather than a closure: the phone's
// background runner re-launches the app and invokes an artisan name, so a
// closure has nothing to invoke and a wall clock has no repeat interval. Both
// shapes are dropped from the device manifest without failing anything.
it('schedules the sweep daily, on an expression the phone runner has an interval for', function (): void {
    $event = sweepFindScheduledEvent('migration.sweep-abandoned');

    expect($event)->not->toBeNull('Expected a registered schedule entry named "migration.sweep-abandoned".');
    expect($event->expression)->toBe('0 0 * * *');
    expect((string) $event->command)->toContain('migration:sweep-abandoned');
    expect($event->mutexName())->not->toBe('');
    expect(in_array($event->expression, MobileBackgroundSchedule::RUNNER_INTERVALS, true))->toBeTrue(
        'The runner takes a repeat period, never a wall clock, so this expression is dropped: '.$event->expression,
    );
});

// The phone can stage a whole export: `NativeZipReader` exists precisely so
// `/migrations/new` works where `ext-zip` does not, the Migration module is
// enabled at the mobile root, and nothing gates the wizard by platform. A
// household whose only device is a phone has nothing else to reclaim its rows.
it('declares the sweep as work the phone has to do for itself', function (): void {
    expect(MobileBackgroundSchedule::requiredOnDevice())->toHaveKey('migration.sweep-abandoned')
        ->and(MobileBackgroundSchedule::desktopOnly())->not->toHaveKey('migration.sweep-abandoned')
        ->and(MobileBackgroundSchedule::impossibleOnDevice())->not->toHaveKey('migration.sweep-abandoned');

    expect(MobileBackgroundSchedule::carriedBy(app(Schedule::class)->events()))
        ->toContain('migration:sweep-abandoned');
});
