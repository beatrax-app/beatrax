<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\EmailScan\Internal\InboxScanStateMachine;

uses(RefreshDatabase::class);

/*
 * resetRetryAttempts() takes a row lock and updates the scan state, so it has
 * to have a row to lock. An inbox with no inbox_scan_state row means the
 * caller is working from an id that no longer refers to anything — updating
 * zero rows and reporting success would leave the retry counter looking reset
 * when nothing was.
 */
it('refuses to reset retry attempts for an inbox with no scan state', function (): void {
    /** @var InboxScanStateMachine $machine */
    $machine = $this->app->make(InboxScanStateMachine::class);

    expect(fn () => $machine->resetRetryAttempts(4242))
        ->toThrow(RuntimeException::class, 'inbox_scan_state for inbox 4242 folder INBOX not found');
});
