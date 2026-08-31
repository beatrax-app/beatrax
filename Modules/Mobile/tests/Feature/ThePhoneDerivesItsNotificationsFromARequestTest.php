<?php

declare(strict_types=1);

use Modules\Notifications\Internal\Http\Middleware\RunDeferredNotificationPasses;

// The phone's own log, on a paired and synced SM-S928B: five
// SensitiveColumnKeyUnavailableException lines from PersistBudgetNudge and then
// `queue.processed` for EmitBudgetNudgesJob. Nothing reached the reader, and the
// runner that fired it will never hold a key — so a request has to.
/**
 * @link ../../../../.docs/features/mobile/background-sync-cannot-hold-the-key.md#the-scheduled-passes-that-cannot-write-either
 */
it('drives the deferred notification passes from the web group, on both roots', function (): void {
    $web = app('router')->getMiddlewareGroups()['web'] ?? [];

    expect($web)->toContain(RunDeferredNotificationPasses::class);
});

// Terminate-time, not in front of the response. The unlock is the first request
// that can run any of this and the one interaction that has to feel instant, so
// running it inline would put the whole pass inside the tap that opens the app.
it('runs after the response rather than ahead of it', function (): void {
    expect(method_exists(RunDeferredNotificationPasses::class, 'terminate'))->toBeTrue();
});
