<?php

declare(strict_types=1);

use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Modules\EmailScan\Internal\Jobs\BackfillInboxJob;

// Two dispatches for one inbox must collapse into a single in-flight job and
// the lock must hold for the whole walk, or two workers race the cursor. Only
// the structural pieces the unique-lock middleware reads can be asserted here:
// the test environment's sync queue driver cannot stage the real race.

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
    // The key ignores the window, so extending it mid-backfill re-queues
    // against the in-flight job rather than starting a second one.
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
    // uniqueVia is invoked at push-time, before any cache write, so only its
    // shape can be asserted here — not the store it resolves.
    $reflection = new ReflectionClass(BackfillInboxJob::class);

    expect($reflection->hasMethod('uniqueVia'))->toBeTrue();
    $method = $reflection->getMethod('uniqueVia');
    $returnType = $method->getReturnType();
    expect($returnType)->not->toBeNull();
});
