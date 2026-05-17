<?php

declare(strict_types=1);

use Modules\EmailScan\Internal\Jobs\BackfillInboxJob;

/*
 * BackfillInboxJob defensive window clamp.
 *
 * The Livewire submit handler clamps months to [1, 12] before
 * dispatch, and the slider clamps to the same range client-side.
 * A crafted POST that bypasses both layers could still construct
 * the job with an out-of-range windowMonths, so the job's
 * handle() body re-clamps defensively.
 *
 * Constructor invariants:
 *  - the windowMonths property is readonly so the dispatcher's
 *    serialised payload always equals the value the job received
 *    at construction time;
 *  - 999 and 0 pass through the constructor unchanged (the
 *    clamp is a runtime guard inside handle, not a constructor
 *    assertion — throwing in the constructor would invalidate
 *    the inbox-id-keyed unique lock if the throw raced with the
 *    queue push).
 *
 * The runtime clamp is exercised end-to-end by the integration
 * suite (BackfillChunkedJobTest passes 3 months and observes
 * the expected fixture set). This unit test verifies the
 * constructor + property invariants the clamp relies on.
 */

it('stores the windowMonths argument unchanged on the readonly property', function (): void {
    $job = new BackfillInboxJob(inboxId: 42, windowMonths: 999);
    expect($job->windowMonths)->toBe(999);

    $job0 = new BackfillInboxJob(inboxId: 42, windowMonths: 0);
    expect($job0->windowMonths)->toBe(0);

    $job3 = new BackfillInboxJob(inboxId: 42, windowMonths: 3);
    expect($job3->windowMonths)->toBe(3);
});

it('exposes readonly inboxId and windowMonths properties', function (): void {
    $reflection = new ReflectionClass(BackfillInboxJob::class);

    $inboxIdProp = $reflection->getProperty('inboxId');
    expect($inboxIdProp->isReadOnly())->toBeTrue();

    $windowProp = $reflection->getProperty('windowMonths');
    expect($windowProp->isReadOnly())->toBeTrue();
});

it('keeps the windowMonths property public so the Livewire dispatcher can serialise it', function (): void {
    $reflection = new ReflectionClass(BackfillInboxJob::class);

    $windowProp = $reflection->getProperty('windowMonths');
    expect($windowProp->isPublic())->toBeTrue();

    $inboxIdProp = $reflection->getProperty('inboxId');
    expect($inboxIdProp->isPublic())->toBeTrue();
});

it('uniqueId is invariant under different windowMonths values for the same inbox', function (): void {
    // Two dispatches for the same inbox with different window
    // values must collapse onto the same unique key — extending
    // the window mid-backfill re-queues against the same in-
    // flight job, never a second one.
    $jobShort = new BackfillInboxJob(inboxId: 99, windowMonths: 1);
    $jobLong = new BackfillInboxJob(inboxId: 99, windowMonths: 12);

    expect($jobShort->uniqueId())->toBe($jobLong->uniqueId());
});
