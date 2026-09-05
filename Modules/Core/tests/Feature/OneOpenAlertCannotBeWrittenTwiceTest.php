<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Modules\Core\Models\SystemAlert;
use Modules\Core\Models\User;
use Modules\Core\Public\Actions\AcknowledgeSystemAlert;
use Modules\Core\Public\Enums\SystemAlertSeverity;
use Modules\Core\Public\Services\SystemAlertWriter;
use Modules\Sync\Public\Events\EntityMutated;

uses(RefreshDatabase::class);

// A desktop boot starts several PHP processes — the web server, the supervised
// queue worker, the sync and relay listeners — and each one loads the redaction
// set. Two of them read "no open alert of this kind" in the same second and
// each wrote one, so the reader was told twice that OAuth redaction was
// offline. Every call below is the SECOND process: its check has already
// passed, which is the only state a read-then-write can be caught in.

function oneOpenAlertUser(string $username): User
{
    return User::query()->create([
        'username' => $username,
        'password' => 'fixture-password',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
}

function oneOpenAlertRowCount(string $kind): int
{
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    return $db->connection()->table('system_alerts')->where('kind', $kind)->count();
}

it('refuses the second machine-local alert of a kind whose guard has already passed', function (): void {
    /** @var SystemAlertWriter $writer */
    $writer = app(SystemAlertWriter::class);

    $first = $writer->raiseOnceSystemWide(
        kind: 'oauth_scrub_set_failed',
        severity: SystemAlertSeverity::Critical->value,
        message: 'OAuth secret redaction is offline.',
        metadata: ['exception' => 'The MAC is invalid.'],
    );

    $second = $writer->raiseOnceSystemWide(
        kind: 'oauth_scrub_set_failed',
        severity: SystemAlertSeverity::Critical->value,
        message: 'OAuth secret redaction is offline.',
        metadata: ['exception' => 'The MAC is invalid.'],
    );

    expect($first)->not->toBeNull();
    expect($second)->toBeNull();
    expect(oneOpenAlertRowCount('oauth_scrub_set_failed'))->toBe(1);
});

// The write runs inside a Monolog processor. A constraint that threw would
// crash every request that emits a log line, which is a worse outcome than the
// duplicate it was added to stop.
it('answers the refused write with null rather than an exception', function (): void {
    /** @var SystemAlertWriter $writer */
    $writer = app(SystemAlertWriter::class);

    $writer->raiseOnceSystemWide(
        kind: 'worker.crashed',
        severity: SystemAlertSeverity::Critical->value,
        message: 'Background processing stopped.',
    );

    $again = static fn (): ?SystemAlert => $writer->raiseOnceSystemWide(
        kind: 'worker.crashed',
        severity: SystemAlertSeverity::Critical->value,
        message: 'Background processing stopped.',
    );

    expect($again)->not->toThrow(Throwable::class);
    expect($again())->toBeNull();
    expect(oneOpenAlertRowCount('worker.crashed'))->toBe(1);
});

// wal_mode_missing and backup_overdue are allowed to say it again an hour
// later. Their key carries the hour so the refusal is exactly as wide as the
// recency check each of them already runs, and no wider.
it('holds a windowed alert to one row inside its bucket and releases it in the next', function (): void {
    /** @var SystemAlertWriter $writer */
    $writer = app(SystemAlertWriter::class);

    $raise = static fn (int $hour): ?SystemAlert => $writer->raiseOnceSystemWide(
        kind: 'wal_mode_missing',
        severity: SystemAlertSeverity::Warning->value,
        message: 'SQLite is not in WAL mode.',
        window: $hour,
    );

    expect($raise(486_000))->not->toBeNull();
    expect($raise(486_000))->toBeNull();
    expect($raise(486_001))->not->toBeNull();

    expect(oneOpenAlertRowCount('wal_mode_missing'))->toBe(2);
});

// The opposite mistake: raiseForUser is how a repeat is written on purpose, and
// three failed recovery attempts are three rows the reader is meant to see.
it('leaves an alert that is meant to repeat unkeyed and repeatable', function (): void {
    $user = oneOpenAlertUser('one-open-alert-repeats');

    /** @var SystemAlertWriter $writer */
    $writer = app(SystemAlertWriter::class);

    for ($attempt = 1; $attempt <= 3; $attempt++) {
        $writer->raiseForUser(
            $user->id,
            'auth.recovery_code_failed',
            SystemAlertSeverity::Critical->value,
            'Failed recovery code attempt.',
        );
    }

    expect(oneOpenAlertRowCount('auth.recovery_code_failed'))->toBe(3);

    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $keyed = $db->connection()->table('system_alerts')->whereNotNull('dedup_key')->count();

    expect($keyed)->toBe(0);
});

it('lets an owned alert be raised again once the reader has acknowledged the last one', function (): void {
    $user = oneOpenAlertUser('one-open-alert-reraise');

    /** @var SystemAlertWriter $writer */
    $writer = app(SystemAlertWriter::class);

    $first = $writer->raiseOnceForUser(
        $user->id,
        'auth.lock.recovery_wrap_stale',
        SystemAlertSeverity::Warning->value,
        'Recovery wrap is stale.',
    );

    expect($first)->not->toBeNull();
    expect($writer->raiseOnceForUser(
        $user->id,
        'auth.lock.recovery_wrap_stale',
        SystemAlertSeverity::Warning->value,
        'Recovery wrap is stale.',
    ))->toBeNull();

    /** @var AcknowledgeSystemAlert $acknowledge */
    $acknowledge = app(AcknowledgeSystemAlert::class);
    $acknowledge($first->id, $user);

    expect($writer->raiseOnceForUser(
        $user->id,
        'auth.lock.recovery_wrap_stale',
        SystemAlertSeverity::Warning->value,
        'Recovery wrap is stale.',
    ))->not->toBeNull();

    expect(oneOpenAlertRowCount('auth.lock.recovery_wrap_stale'))->toBe(2);
});

// A peer's dismissal arrives from the applier as a raw UPDATE and reaches no
// PHP of ours, so the key is released by a trigger on the column rather than by
// the acknowledge action. Without it the local row stays keyed for good and
// this device silently never raises that kind again.
it('releases the key when the column is stamped by something that is not the action', function (): void {
    $user = oneOpenAlertUser('one-open-alert-applier');

    /** @var SystemAlertWriter $writer */
    $writer = app(SystemAlertWriter::class);

    $first = $writer->raiseOnceForUser(
        $user->id,
        'auth.lock.corrupted_key',
        SystemAlertSeverity::Critical->value,
        'Lock key material is corrupt.',
    );

    expect($first)->not->toBeNull();

    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $connection = $db->connection();

    expect($connection->table('system_alerts')->where('id', $first->id)->value('dedup_key'))
        ->toBe('u'.$user->id.':auth.lock.corrupted_key');

    $connection->table('system_alerts')
        ->where('id', $first->id)
        ->update(['acknowledged_at' => '2026-09-05 09:00:00']);

    expect($connection->table('system_alerts')->where('id', $first->id)->value('dedup_key'))->toBeNull();

    expect($writer->raiseOnceForUser(
        $user->id,
        'auth.lock.corrupted_key',
        SystemAlertSeverity::Critical->value,
        'Lock key material is corrupt.',
    ))->not->toBeNull();

    expect(oneOpenAlertRowCount('auth.lock.corrupted_key'))->toBe(2);
});

// The key is this device's claim on an open row. Sent, the peer would store it
// and the next device to raise the same kind for the same user would collide
// with a key it never minted.
it('keeps the dedup key off the wire when an owned alert travels', function (): void {
    $user = oneOpenAlertUser('one-open-alert-wire');

    Event::fake([EntityMutated::class]);

    /** @var SystemAlertWriter $writer */
    $writer = app(SystemAlertWriter::class);

    $writer->raiseOnceForUser(
        $user->id,
        'auth.lock.key_material_stranded',
        SystemAlertSeverity::Critical->value,
        'Key material is stranded.',
    );

    Event::assertDispatched(EntityMutated::class, static function (EntityMutated $event): bool {
        return $event->table === 'system_alerts'
            && ! array_key_exists('dedup_key', $event->dirtyFields)
            && ! array_key_exists('id', $event->dirtyFields)
            && array_key_exists('kind', $event->dirtyFields);
    });
});

// Two taps on one button are two requests, and both pass the "has this reader
// already dismissed it?" read. The unique index behind it then turned the
// second into a 500 on a control whose whole job is to clear a warning.
it('survives the same reader dismissing one system-wide alert twice', function (): void {
    $user = oneOpenAlertUser('one-open-alert-double-tap');

    /** @var SystemAlertWriter $writer */
    $writer = app(SystemAlertWriter::class);

    $alert = $writer->raiseOnceSystemWide(
        kind: 'synchronous_misconfigured',
        severity: SystemAlertSeverity::Warning->value,
        message: 'SQLite synchronous level is 2.',
    );

    expect($alert)->not->toBeNull();

    /** @var AcknowledgeSystemAlert $acknowledge */
    $acknowledge = app(AcknowledgeSystemAlert::class);

    $acknowledge($alert->id, $user);
    $acknowledge($alert->id, $user);

    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $acks = $db->connection()->table('system_alert_acknowledgements')
        ->where('system_alert_id', $alert->id)
        ->count();

    expect($acks)->toBe(1);
});
