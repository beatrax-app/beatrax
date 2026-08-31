<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Modules\Core\Internal\Enums\BackupAlertKind;
use Modules\Core\Internal\Enums\BackupFailureCause;
use Modules\Core\Models\User;
use Modules\Core\Public\Enums\SystemAlertSeverity;
use Modules\Core\Public\Http\Livewire\SystemAlertsBanner;

// One `backup_corrupt` kind carries four different failures, and the banner
// used to choose its sentence on whether a `.suspect` file existed. Three of
// the four producers had already cleared the database before they raised the
// row — one of them with a verified backup sitting on disk — and every one of
// them told the reader their database had failed its integrity check.

beforeEach(function (): void {
    $this->user = User::query()->create([
        'username' => 'backup-banner-cause',
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
});

/**
 * @param  array<string, mixed>  $metadata
 */
function seeBannerFor(mixed $user, array $metadata): Testable
{
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    $db->connection()->table('system_alerts')->insert([
        'user_id' => $user->id,
        'kind' => BackupAlertKind::Corrupt->value,
        'severity' => SystemAlertSeverity::Critical->value,
        'message' => 'raw-fallthrough-marker',
        'metadata' => json_encode($metadata),
        'created_at' => '2026-05-20 01:00:00',
        'acknowledged_at' => null,
    ]);

    return Livewire::actingAs($user)->test(SystemAlertsBanner::class);
}

it('does not accuse the database when the database is what passed', function (): void {
    seeBannerFor($this->user, [
        'cause' => BackupFailureCause::WriteFailed->value,
        'suspect_path' => null,
        'phase' => 'sidecar_write',
    ])
        ->assertDontSee('aborted before any file was produced')
        ->assertDontSee('source DB failed integrity check')
        ->assertSee('the database passed its checks');
});

it('says a restore failed, and where the undo is, rather than describing a backup', function (): void {
    seeBannerFor($this->user, [
        'cause' => BackupFailureCause::RestoreFailed->value,
        'pre_restore_snapshot' => '/data/backups/pre-restore-2026-05-20-010000.sqlite',
        'phase' => 'post_swap',
    ])
        ->assertDontSee('aborted before any file was produced')
        ->assertSee('restore attempted')
        ->assertSee('pre-restore-2026-05-20-010000.sqlite');
});

// The one producer that genuinely found a corrupt source keeps the sentence
// that names it, and so does a row written before the cause was recorded.
it('still names a corrupt source when that is what happened', function (): void {
    seeBannerFor($this->user, [
        'cause' => BackupFailureCause::SourceUnreadable->value,
        'phase' => 'source_probe',
    ])->assertSee('source DB failed integrity check');
});

it('reads a row written before the cause was recorded the way it always did', function (): void {
    seeBannerFor($this->user, ['phase' => 'source_probe'])
        ->assertSee('source DB failed integrity check')
        ->assertDontSee('raw-fallthrough-marker');
});

it('still points at the kept copy when one was preserved for inspection', function (): void {
    seeBannerFor($this->user, [
        'cause' => BackupFailureCause::CopySuspect->value,
        'suspect_path' => '/data/backups/beatrax-2026-05-20-010000.sqlite.suspect',
    ])->assertSee('beatrax-2026-05-20-010000.sqlite.suspect');
});
