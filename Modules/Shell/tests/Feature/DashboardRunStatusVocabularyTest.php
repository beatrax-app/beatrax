<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Core\Public\Enums\JobRunStatus;
use Modules\EmailScan\Public\Enums\InboxScanStatus;
use Modules\EmailScan\Public\Enums\MailProvider;
use Modules\Shell\Internal\Http\Livewire\Dashboard;

// The dashboard's reauth count and failed-resolution flag both name statuses
// other modules own and write. Spelled as bare strings they fail silently: the
// query stays valid, the count comes back 0, and a banner that has stopped
// firing is indistinguishable from a healthy install.

beforeEach(function (): void {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-05-15 12:00:00'));

    $this->user = User::query()->create([
        'username' => 'dashboard-vocabulary',
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
    $this->actingAs($this->user);
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

function dashVocabInbox(object $context, InboxScanStatus $status, string $email): void
{
    $now = CarbonImmutable::now()->toDateTimeString();

    $inboxId = (int) DB::table('inboxes')->insertGetId([
        'user_id' => $context->user->id,
        'provider' => MailProvider::Gmail->value,
        'email' => $email,
        'backfill_window_months' => 3,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    DB::table('inbox_scan_state')->insert([
        'user_id' => $context->user->id,
        'inbox_id' => $inboxId,
        'folder' => 'INBOX',
        'status' => $status->value,
        'retry_attempts' => 0,
        'created_at' => $now,
        'updated_at' => $now,
    ]);
}

function dashVocabResolutionRun(object $context, JobRunStatus $status): void
{
    $now = CarbonImmutable::now()->toDateTimeString();

    DB::table('chain_resolution_runs')->insert([
        'user_id' => $context->user->id,
        'job_uuid' => null,
        'started_at' => $now,
        'completed_at' => $now,
        'status' => $status->value,
        'linked_count' => 0,
        'created_at' => $now,
        'updated_at' => $now,
    ]);
}

it('counts only the inboxes stored under the status EmailScan calls needs-reauth', function (): void {
    dashVocabInbox($this, InboxScanStatus::NeedsReauth, 'reauth@example.com');
    dashVocabInbox($this, InboxScanStatus::Idle, 'idle@example.com');
    dashVocabInbox($this, InboxScanStatus::Error, 'error@example.com');

    Livewire::test(Dashboard::class)->assertViewHas('reauthInboxCount', 1);
});

it('raises the failed-resolution flag only for the status the run enum calls failed', function (): void {
    dashVocabResolutionRun($this, JobRunStatus::Complete);

    Livewire::test(Dashboard::class)->assertSet('failedChainResolutionExists', false);

    dashVocabResolutionRun($this, JobRunStatus::Failed);

    Livewire::test(Dashboard::class)->assertSet('failedChainResolutionExists', true);
});

// Both tables carry CHECK triggers naming their own vocabulary. If a case ever
// stops matching, the insert aborts here rather than in a banner gone quietly
// silent.
it('stores every case both run-status triggers accept', function (): void {
    foreach (InboxScanStatus::cases() as $index => $status) {
        dashVocabInbox($this, $status, 'vocab-'.$index.'@example.com');
    }

    foreach (JobRunStatus::cases() as $status) {
        dashVocabResolutionRun($this, $status);
    }

    expect(DB::table('inbox_scan_state')->where('user_id', $this->user->id)->count())
        ->toBe(count(InboxScanStatus::cases()))
        ->and(DB::table('chain_resolution_runs')->where('user_id', $this->user->id)->count())
        ->toBe(count(JobRunStatus::cases()));
});
