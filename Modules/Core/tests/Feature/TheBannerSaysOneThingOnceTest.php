<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Core\Public\Http\Livewire\SystemAlertsBanner;

// The constraint stops a duplicate being WRITTEN. It cannot remove the two
// byte-identical critical rows an installed copy already holds, and a reader
// who is told the same alarming thing twice stops believing the next one. The
// banner is mounted on every page, so the pair was on every screen.

beforeEach(function (): void {
    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);
    $this->db = $db;

    $this->reader = User::query()->create([
        'username' => 'says-once',
        'password' => 'fixture',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
});

/**
 * @param  array<string, mixed>  $overrides
 */
function bannerOnceInsert(DatabaseManager $db, array $overrides = []): int
{
    $defaults = [
        'user_id' => null,
        'dedup_key' => null,
        'kind' => 'oauth_scrub_set_failed',
        'severity' => 'critical',
        'message' => 'OAuth secret redaction is offline.',
        'metadata' => json_encode([
            'copy' => ['key' => 'core::alerts.messages.oauth_scrub_set_failed', 'replace' => [], 'count' => null],
            'provider' => 'gmail',
            'exception' => 'The MAC is invalid.',
        ]),
        'created_at' => '2026-09-05 02:52:13',
        'acknowledged_at' => null,
    ];

    return $db->connection()->table('system_alerts')->insertGetId(array_merge($defaults, $overrides));
}

it('paints one row for the two identical alerts one second of a boot race produced', function (): void {
    $first = bannerOnceInsert($this->db);
    $second = bannerOnceInsert($this->db);

    expect($second)->not->toBe($first);

    Livewire::actingAs($this->reader)->test(SystemAlertsBanner::class)
        ->assertSeeHtml('data-testid="resolve-alert-'.$first.'"')
        ->assertDontSeeHtml('data-testid="resolve-alert-'.$second.'"');
});

// The narrow half of the same rule: two rows of one kind that do not read
// alike are two things the reader has to be told, and collapsing on kind alone
// would have hidden the second failure behind the first.
it('keeps both rows of one kind when they do not say the same thing', function (): void {
    $gmail = bannerOnceInsert($this->db);
    $microsoft = bannerOnceInsert($this->db, [
        'metadata' => json_encode([
            'copy' => ['key' => 'core::alerts.messages.oauth_scrub_set_failed', 'replace' => [], 'count' => null],
            'provider' => 'microsoft',
            'exception' => 'The MAC is invalid.',
        ]),
        'created_at' => '2026-09-05 03:14:02',
    ]);

    Livewire::actingAs($this->reader)->test(SystemAlertsBanner::class)
        ->assertSeeHtml('data-testid="resolve-alert-'.$gmail.'"')
        ->assertSeeHtml('data-testid="resolve-alert-'.$microsoft.'"');
});
