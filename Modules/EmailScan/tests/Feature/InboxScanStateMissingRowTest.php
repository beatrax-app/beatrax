<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\EmailScan\Internal\InboxScanStateMachine;
use Modules\EmailScan\Public\Exceptions\ScanStateNotFoundException;

uses(RefreshDatabase::class);

// Updating zero rows and reporting success would leave the retry counter
// looking reset when nothing was.
it('refuses to reset retry attempts for an inbox with no scan state', function (): void {
    /** @var InboxScanStateMachine $machine */
    $machine = $this->app->make(InboxScanStateMachine::class);

    expect(fn () => $machine->resetRetryAttempts(4242))
        ->toThrow(ScanStateNotFoundException::class, 'inbox_scan_state for inbox 4242 folder INBOX not found');
});
