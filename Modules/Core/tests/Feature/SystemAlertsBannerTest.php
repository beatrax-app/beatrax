<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Livewire\Livewire;
use Modules\Core\Internal\Http\Livewire\SystemAlertsBanner;
use Modules\Core\Models\SystemAlert;
use Modules\Core\Models\User;
use Modules\Core\Public\Actions\AcknowledgeSystemAlert;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/*
 * SystemAlertsBanner is the persistent dashboard banner that surfaces
 * un-acknowledged operational alerts. The component reads through
 * SystemAlertQuery::active($currentUser) and dismisses rows via the
 * AcknowledgeSystemAlert action. Tests cover:
 *  (a) calm state — no active rows → the wrapper renders but no
 *      dismiss button is visible.
 *  (b) one critical (backup_corrupt) alert → renders the locked
 *      "failed integrity check" template + the aria-label.
 *  (c) clicking "acknowledge" → AcknowledgeSystemAlert flips the row
 *      and the next render drops it from the banner.
 *  (d) cross-user isolation — userA's banner shows only userA's rows
 *      plus system-wide (user_id NULL) rows.
 *  (e) cross-user acknowledge attempt → NotFoundHttpException raised by
 *      AcknowledgeSystemAlert (Livewire-side guard).
 *  (f) severity ordering — critical row's DOM offset is strictly before
 *      the warning row.
 */

beforeEach(function (): void {
    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);
    $this->db = $db;

    $this->userA = User::query()->create([
        'username' => 'sab-a',
        'password' => 'fixture',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
    $this->userB = User::query()->create([
        'username' => 'sab-b',
        'password' => 'fixture',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
});

/**
 * Seeds one system_alerts row through the raw query builder so the
 * scenario can control every column (created_at, metadata, severity)
 * without going through Eloquent timestamps. Returns the inserted id.
 *
 * @param  array<string, mixed>  $overrides
 */
function sabInsert(DatabaseManager $db, array $overrides = []): int
{
    $defaults = [
        'user_id' => null,
        'kind' => 'backup_corrupt',
        'severity' => 'critical',
        'message' => 'fixture',
        'metadata' => null,
        'created_at' => '2026-05-20 01:00:00',
        'acknowledged_at' => null,
    ];

    return $db->connection()->table('system_alerts')->insertGetId(array_merge($defaults, $overrides));
}

it('renders the wrapper but no row when no alerts are active', function (): void {
    Livewire::actingAs($this->userA)->test(SystemAlertsBanner::class)
        ->assertDontSee('Mark as resolved')
        ->assertSeeHtml('aria-label="System alerts"');
});

it('renders the critical backup_corrupt template with its aria-label', function (): void {
    $id = sabInsert($this->db, [
        'user_id' => $this->userA->id,
        'kind' => 'backup_corrupt',
        'severity' => 'critical',
        'message' => 'fixture',
        'metadata' => json_encode([
            'timestamp' => '2026-05-20 01:00',
            'suspect_path' => 'storage/app/backups/beatrax-2026-05-20-010000.sqlite.suspect',
        ]),
        'created_at' => '2026-05-20 01:00:00',
    ]);

    Livewire::actingAs($this->userA)->test(SystemAlertsBanner::class)
        ->assertSee('failed integrity check')
        ->assertSeeHtml('data-testid="resolve-alert-'.$id.'"')
        // This test is about the announced name, so it asserts the name
        // itself. It leads with the button's visible text so speech input
        // can act on what the user can read.
        ->assertSeeHtml('aria-label="Mark as resolved — system alert #'.$id.'"')
        ->assertSeeHtml('border-rose-500');
});

it('removes a row after acknowledging it', function (): void {
    $id = sabInsert($this->db, [
        'user_id' => $this->userA->id,
        'kind' => 'backup_corrupt',
        'severity' => 'critical',
        'message' => 'fixture-critical',
    ]);

    $component = Livewire::actingAs($this->userA)->test(SystemAlertsBanner::class);
    $component->assertSeeHtml('data-testid="resolve-alert-'.$id.'"');

    $component->call('acknowledge', $id);
    $component->assertDontSeeHtml('data-testid="resolve-alert-'.$id.'"');

    /** @var SystemAlert $row */
    $row = SystemAlert::query()->findOrFail($id);
    expect($row->acknowledged_at)->not->toBeNull();
});

it('does not surface another user\'s alerts (cross-user isolation), but shows system-wide rows', function (): void {
    $bId = sabInsert($this->db, [
        'user_id' => $this->userB->id,
        'kind' => 'backup_corrupt',
        'severity' => 'critical',
        'message' => 'B-only',
    ]);
    $sysId = sabInsert($this->db, [
        'user_id' => null,
        'kind' => 'wal_mode_missing',
        'severity' => 'warning',
        'message' => 'system-wide',
        'metadata' => json_encode(['current_mode' => 'delete']),
    ]);

    $component = Livewire::actingAs($this->userA)->test(SystemAlertsBanner::class);

    $component->assertDontSeeHtml('data-testid="resolve-alert-'.$bId.'"');
    $component->assertSeeHtml('data-testid="resolve-alert-'.$sysId.'"');
});

it('refuses cross-user acknowledge attempts via the action (NotFoundHttpException bubbles)', function (): void {
    // The cross-user guard lives at the action layer; a tampered
    // Livewire payload (acting as userA but passing userB's alert id)
    // resolves to the action which raises NotFoundHttpException. The
    // test invokes the action directly — Livewire catches Symfony HTTP
    // exceptions during synthetic ->call() invocations, so the
    // architectural guarantee belongs on the action surface.
    $bId = sabInsert($this->db, [
        'user_id' => $this->userB->id,
        'kind' => 'backup_corrupt',
        'severity' => 'critical',
        'message' => 'B-private',
    ]);

    /** @var AcknowledgeSystemAlert $action */
    $action = $this->app->make(AcknowledgeSystemAlert::class);

    expect(fn () => $action($bId, $this->userA))
        ->toThrow(NotFoundHttpException::class, 'System alert not found.');

    $row = $this->db->connection()->table('system_alerts')->where('id', $bId)->first();
    expect($row?->acknowledged_at)->toBeNull();
});

it('renders the critical row strictly before the warning row in DOM order', function (): void {
    sabInsert($this->db, [
        'user_id' => $this->userA->id,
        'kind' => 'wal_mode_missing',
        'severity' => 'warning',
        'message' => 'WARNING ROW',
        'metadata' => json_encode(['current_mode' => 'delete']),
        'created_at' => '2026-05-20 01:00:00',
    ]);
    sabInsert($this->db, [
        'user_id' => $this->userA->id,
        'kind' => 'backup_corrupt',
        'severity' => 'critical',
        'message' => 'CRITICAL ROW',
        'metadata' => json_encode([
            'timestamp' => '2026-05-20 02:00',
            'suspect_path' => 'storage/app/backups/x.sqlite.suspect',
        ]),
        'created_at' => '2026-05-20 02:00:00',
    ]);

    $component = Livewire::actingAs($this->userA)->test(SystemAlertsBanner::class);
    $html = (string) $component->html();

    $critPos = strpos($html, 'failed integrity check');
    $warnPos = strpos($html, 'not in WAL mode');

    expect($critPos)->not->toBeFalse();
    expect($warnPos)->not->toBeFalse();
    expect($critPos)->toBeLessThan((int) $warnPos);
});

/*
 * The action buttons never shrink, so a row that is a flex row at every
 * width left the message about 130px on a phone and broke one sentence
 * over six lines — on every page, since the banner sits above all of them.
 * Each severity row therefore stacks below `sm`.
 */
it('stacks the message above the actions on a phone, in every severity', function (): void {
    foreach ([['backup_corrupt', 'critical'], ['wal_mode_missing', 'warning'], ['update_available', 'info']] as [$kind, $severity]) {
        sabInsert($this->db, [
            'user_id' => $this->userA->id,
            'kind' => $kind,
            'severity' => $severity,
            'message' => 'fixture',
            'metadata' => json_encode(['current_mode' => 'delete', 'version' => '1.2.3']),
        ]);
    }

    $html = (string) Livewire::actingAs($this->userA)->test(SystemAlertsBanner::class)->html();

    expect(substr_count($html, 'flex flex-col items-start gap-3 sm:flex-row sm:justify-between sm:gap-4'))->toBe(3);
});
