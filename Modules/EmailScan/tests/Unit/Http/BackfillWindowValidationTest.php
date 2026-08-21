<?php

declare(strict_types=1);

use Modules\EmailScan\Internal\Jobs\BackfillInboxJob;

// An out-of-range windowMonths is clamped inside handle(), not rejected in the
// constructor: a throw there could race the queue push and leave the
// inbox-id-keyed unique lock stranded. Hence 999 and 0 surviving construction.

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
    // Extending the window mid-backfill has to re-queue against the in-flight
    // job rather than start a second one.
    $jobShort = new BackfillInboxJob(inboxId: 99, windowMonths: 1);
    $jobLong = new BackfillInboxJob(inboxId: 99, windowMonths: 12);

    expect($jobShort->uniqueId())->toBe($jobLong->uniqueId());
});
