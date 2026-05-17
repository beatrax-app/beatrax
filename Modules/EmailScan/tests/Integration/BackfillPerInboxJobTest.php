<?php

declare(strict_types=1);

use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Modules\EmailScan\Internal\Jobs\BackfillInboxJob;

/*
 * BackfillInboxJob single-flight contract.
 *
 * The plan-level invariant is "two concurrent dispatches for the
 * same inbox must collapse into one in-flight job AND the lock holds
 * for the entire walk so two workers never race the cursor"; Laravel's
 * unique-lock middleware does the actual collapsing via the cache
 * store returned from uniqueVia(). This test asserts the structural
 * pieces the middleware reads:
 *
 *  - the job implements ShouldBeUnique (lock held until handle()
 *    returns, NOT released on pickup) + ShouldQueue
 *  - uniqueId() returns the inboxId so two different inboxes get
 *    two different unique keys
 *  - uniqueFor() returns 1800 — the 30-minute ceiling that bounds the
 *    lock if a worker crashes mid-walk
 *  - tries = 3 + backoff = [60, 300, 900] — the retry envelope
 *
 * The end-to-end "two simultaneous dispatches" behaviour is
 * exercised by Horizon at runtime; the test environment uses the
 * sync queue driver so a real lock-versus-second-push race cannot
 * be observed here without bringing Redis online.
 */

it('implements the per-inbox single-flight queue contract', function (): void {
    $reflection = new ReflectionClass(BackfillInboxJob::class);

    expect($reflection->implementsInterface(ShouldBeUnique::class))->toBeTrue();
    expect($reflection->implementsInterface(ShouldQueue::class))->toBeTrue();
});

it('keys the unique lock on inbox_id so different inboxes do not collide', function (): void {
    $jobInbox1 = new BackfillInboxJob(inboxId: 1, windowMonths: 3);
    $jobInbox2 = new BackfillInboxJob(inboxId: 2, windowMonths: 3);
    $jobInbox1Again = new BackfillInboxJob(inboxId: 1, windowMonths: 6);

    expect($jobInbox1->uniqueId())->toBe('1');
    expect($jobInbox2->uniqueId())->toBe('2');
    // The second dispatch with the SAME inbox id but a different
    // window collapses with the first because the lock key ignores
    // window — extending the window mid-backfill re-queues against
    // the same in-flight job, never a second one.
    expect($jobInbox1Again->uniqueId())->toBe('1');
});

it('caps the unique lock at 30 minutes', function (): void {
    $job = new BackfillInboxJob(inboxId: 42, windowMonths: 3);

    expect($job->uniqueFor())->toBe(1800);
});

it('uses the project-wide retry envelope (tries=3, backoff=60/300/900)', function (): void {
    $job = new BackfillInboxJob(inboxId: 42, windowMonths: 3);

    expect($job->tries)->toBe(3);
    expect($job->backoff)->toBe([60, 300, 900]);
});

it('routes the unique lock through a Cache Repository', function (): void {
    // uniqueVia() returns Cache::driver('redis') in production.
    // The Cache facade's array store satisfies the Repository
    // contract; calling it directly in the test would hit Redis,
    // so the assertion is on the method shape instead — Laravel
    // queue infrastructure invokes uniqueVia at push-time before
    // any cache writes happen.
    $reflection = new ReflectionClass(BackfillInboxJob::class);

    expect($reflection->hasMethod('uniqueVia'))->toBeTrue();
    $method = $reflection->getMethod('uniqueVia');
    $returnType = $method->getReturnType();
    expect($returnType)->not->toBeNull();
});
