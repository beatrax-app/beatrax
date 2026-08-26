<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Core\Public\Enums\SnoozeWindow;
use Modules\Core\Public\Support\Lang;
use Modules\DriftAlerts\Internal\Http\Livewire\DriftPage;
use Modules\DriftAlerts\Models\DriftAlert;
use Modules\DriftAlerts\Public\Events\DriftAlertDismissedCancelled;

uses(RefreshDatabase::class);

function dpUser(string $username): User
{
    return User::query()->create([
        'username' => $username,
        'password' => 'fixture',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
}

function dpTransaction(DatabaseManager $db, int $userId): int
{
    $suffix = bin2hex(random_bytes(4));
    $accountId = $db->connection()->table('accounts')->insertGetId([
        'user_id' => $userId,
        'name' => 'ASN test',
        'slug' => 'dp-asn-'.$suffix,
        'kind' => 'bank',
        'iban' => 'NL00ASNB'.strtoupper($suffix),
        'default_currency' => 'EUR',
        'created_at' => '2026-05-19 00:00:00',
        'updated_at' => '2026-05-19 00:00:00',
    ]);

    $runId = $db->connection()->table('import_runs')->insertGetId([
        'user_id' => $userId,
        'source_format' => 'asn-csv',
        'raw_file_path' => '/tmp/dp-'.$suffix.'.csv',
        'sha256' => hash('sha256', 'dp-run-'.$suffix),
        'uploaded_at' => '2026-05-19 00:00:00',
        'status' => 'previewed',
        'created_at' => '2026-05-19 00:00:00',
        'updated_at' => '2026-05-19 00:00:00',
    ]);

    return $db->connection()->table('transactions')->insertGetId([
        'user_id' => $userId,
        'account_id' => $accountId,
        'import_run_id' => $runId,
        'fingerprint' => hash('sha256', 'dp-'.bin2hex(random_bytes(8))),
        'posted_at' => '2026-05-15',
        'booked_at' => '2026-05-15 00:00:00',
        'value_date' => '2026-05-15',
        'amount_minor' => -1149,
        'currency' => 'EUR',
        'settled_amount_minor' => -1149,
        'settled_currency' => 'EUR',
        'counterparty_normalized' => 'spotify',
        'counterparty_name' => 'SPOTIFY',
        'normalization_version' => 1,
        'description' => 'dp fixture',
        'type' => 'expense',
        'source_format' => 'asn-csv',
        'source_row_index' => 1,
        'fingerprint_version' => 3,
        'created_at' => '2026-05-19 00:00:00',
        'updated_at' => '2026-05-19 00:00:00',
    ]);
}

/**
 * @param  array<string, mixed>  $seriesOverrides
 * @param  array<string, mixed>  $alertOverrides
 */
function dpAlert(
    User $user,
    string $detectedName = 'Spotify',
    array $seriesOverrides = [],
    array $alertOverrides = [],
): DriftAlert {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    $seriesId = $db->connection()->table('recurring_series')->insertGetId(array_merge([
        'user_id' => $user->id,
        'direction' => 'expense',
        'detected_name' => $detectedName,
        'state' => 'approved',
        'cadence' => 'monthly',
        'latest_amount_minor' => -1149,
        'latest_currency' => 'EUR',
        'variance_tolerance_percent' => 25,
        'cluster_key' => 'dp::'.bin2hex(random_bytes(4)),
        'created_at' => '2026-05-19 00:00:00',
        'updated_at' => '2026-05-19 00:00:00',
    ], $seriesOverrides));

    $occurrenceId = $db->connection()->table('recurring_series_occurrences')->insertGetId([
        'user_id' => $user->id,
        'recurring_series_id' => $seriesId,
        'transaction_id' => dpTransaction($db, $user->id),
        'observed_at' => '2026-05-15',
        'observed_amount_minor' => -1149,
        'observed_currency' => 'EUR',
        'created_at' => '2026-05-19 00:00:00',
        'updated_at' => '2026-05-19 00:00:00',
    ]);

    return DriftAlert::factory()->create(array_merge([
        'user_id' => $user->id,
        'recurring_series_id' => $seriesId,
        'state' => 'open',
        'direction' => 'expense',
        'baseline_amount_minor' => -999,
        'latest_amount_minor' => -1149,
        'currency' => 'EUR',
        'delta_minor' => -150,
        'annualized_impact_minor' => -1800,
        'threshold_percent_used' => 5,
        'threshold_source' => 'global',
        'latest_occurrence_id' => $occurrenceId,
        'detected_at' => CarbonImmutable::parse('2026-05-19 12:00:00'),
    ], $alertOverrides));
}

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-05-20 09:00:00');
    $this->user = dpUser('dp');
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

it('redirects to /login when unauthenticated', function (): void {
    $this->get('/drift')->assertRedirect('/login');
});

it('renders the page with the empty-state hero on the Open tab when no alerts exist', function (): void {
    $this->actingAs($this->user)
        ->get('/drift')
        ->assertOk()
        ->assertSeeText('Drift Alerts')
        ->assertSeeText('No open drift alerts')
        ->assertDontSeeText('Acknowledge');
});

it('renders open alerts on the Open tab and sorts the newest detected_at first', function (): void {
    $first = dpAlert($this->user, 'Netflix', alertOverrides: [
        'detected_at' => CarbonImmutable::parse('2026-05-10 10:00:00'),
    ]);
    $second = dpAlert($this->user, 'Spotify', alertOverrides: [
        'detected_at' => CarbonImmutable::parse('2026-05-18 10:00:00'),
    ]);

    $response = $this->actingAs($this->user)->get('/drift');
    $response->assertOk()
        ->assertSeeText('Netflix')
        ->assertSeeText('Spotify');

    $content = $response->getContent() ?: '';
    $spotifyPos = strpos($content, 'Spotify');
    $netflixPos = strpos($content, 'Netflix');
    expect($spotifyPos)->toBeInt()->toBeLessThan((int) $netflixPos);
});

it('persists the active tab through the URL via #[Url] state', function (): void {
    dpAlert($this->user, 'AlreadyDone', alertOverrides: [
        'state' => 'acknowledged',
        'actioned_at' => CarbonImmutable::now(),
    ]);

    $response = $this->actingAs($this->user)->get('/drift?tab=history');
    $response->assertOk()
        ->assertSeeText('AlreadyDone');
});

it('persists the active type through the URL via #[Url] state', function (): void {
    $response = $this->actingAs($this->user)->get('/drift?type=anomaly');
    $response->assertOk()
        ->assertSeeText(Lang::get('drift-alerts::alerts.intro_anomaly'));
});

it('reads a crafted type or tab as the default rather than failing the request', function (): void {
    // The two are #[Url] properties, so anyone can put anything in the query
    // string. A value outside the enum has to land on the default view, not
    // on a 500.
    $this->actingAs($this->user)->get('/drift?type=nonsense')->assertOk();
    $this->actingAs($this->user)->get('/drift?tab=nonsense')->assertOk();
});

it('invokes Acknowledge and dispatches a toast', function (): void {
    $alert = dpAlert($this->user);

    $component = Livewire::actingAs($this->user)
        ->test(DriftPage::class)
        ->call('acknowledge', $alert->id);

    $fresh = DriftAlert::query()->findOrFail($alert->id);
    expect($fresh->state)->toBe('acknowledged');

    $component->assertDispatched('toast');
});

it('invokes Snooze with the 1-week popover target and writes snoozed_until', function (): void {
    $alert = dpAlert($this->user);

    $until = CarbonImmutable::parse('2026-05-20 09:00:00')->addWeek();
    $untilIso = $until->toIso8601String();

    Livewire::actingAs($this->user)
        ->test(DriftPage::class)
        ->call('snooze', $alert->id, $untilIso);

    $fresh = DriftAlert::query()->findOrFail($alert->id);
    expect($fresh->state)->toBe('snoozed');
    expect($fresh->snoozed_until?->toDateTimeString())->toBe($until->toDateTimeString());
});

it('invokes Dismiss-as-cancelled and emits DriftAlertDismissedCancelled', function (): void {
    Event::fake([DriftAlertDismissedCancelled::class]);

    $alert = dpAlert($this->user);

    Livewire::actingAs($this->user)
        ->test(DriftPage::class)
        ->call('dismissAsCancelled', $alert->id);

    $fresh = DriftAlert::query()->findOrFail($alert->id);
    expect($fresh->state)->toBe('dismissed_cancelled');

    Event::assertDispatched(DriftAlertDismissedCancelled::class);
});

it('renders the cadence-flipped meta line when the underlying series is in state cadence_changed', function (): void {
    dpAlert($this->user, 'CadenceFlipped', seriesOverrides: [
        'state' => 'cadence_changed',
    ]);

    $response = $this->actingAs($this->user)->get('/drift');
    $response->assertOk()
        ->assertSeeText('Cadence flipped');
});

// A shrink-0 chip column beside the text measured 268px past the right edge of
// the clipping card at 390px, so the last two chips were unreachable on a phone.
it('lets a phone reach every action chip on an open alert', function (): void {
    dpAlert($this->user, 'Netflix');

    $content = (string) $this->actingAs($this->user)->get('/drift')->getContent();

    // Both halves are needed: wrapping alone still leaves the chip column
    // competing with the text for a 390px line.
    expect($content)->toContain('flex flex-col items-start gap-3 sm:flex-row sm:justify-between sm:gap-4')
        ->toContain('flex flex-wrap items-center gap-2 sm:shrink-0')
        ->not->toContain('flex shrink-0 items-center gap-2');
});

// The three snooze buttons are drawn by one @foreach over SnoozeWindow, so
// this holds the loop to the markup the three hand-written buttons had:
// bare-integer alert id, the module's own label keys, and no menuitem role.
it('draws a snooze button for every window, wired the way the hand-written three were', function (): void {
    $alert = dpAlert($this->user, 'Netflix');

    $content = (string) $this->actingAs($this->user)->get('/drift')->getContent();

    foreach (SnoozeWindow::cases() as $window) {
        expect($content)
            ->toContain('wire:click="snooze('.$alert->id.", '")
            ->toContain(Lang::get($window->labelKey('drift-alerts::alerts.row')));
    }

    expect(substr_count($content, 'class="block w-full px-2 py-1 text-left hover:bg-slate-50 dark:hover:bg-slate-900"'))
        ->toBe(count(SnoozeWindow::cases()));
    expect($content)->not->toContain('snooze_1w');
});

// Two nav items land on this one page — "Drift Alerts" and "Unusual charges" —
// and it carried a single name for both, so a reader who tapped the second
// arrived at a screen headed with the first one's name. Measured on an iPhone:
// /drift and /drift?type=anomaly were both titled "Afwijkingswaarschuwingen"
// and both headed "Driftmeldingen", with only the intro paragraph telling them
// apart.
it('names whichever of its two screens the reader actually opened', function (): void {
    $drift = $this->actingAs($this->user)->get('/drift');
    $anomaly = $this->actingAs($this->user)->get('/drift?type=anomaly');

    $nameOf = static function (string $html): string {
        expect(preg_match('/<h1[^>]*>(.*?)<\/h1>/s', $html, $m))->toBe(1);

        return trim(strip_tags($m[1]));
    };
    $titleOf = static function (string $html): string {
        expect(preg_match('/<title[^>]*>(.*?)<\/title>/s', $html, $m))->toBe(1);

        return trim(html_entity_decode($m[1]));
    };

    $driftHtml = $drift->getContent();
    $anomalyHtml = $anomaly->getContent();
    expect($driftHtml)->toBeString()->and($anomalyHtml)->toBeString();

    expect($nameOf($anomalyHtml))->toBe('Unusual charges')
        ->and($nameOf($driftHtml))->toBe('Drift Alerts')
        ->and($nameOf($driftHtml))->not->toBe($nameOf($anomalyHtml))
        ->and($titleOf($driftHtml))->not->toBe($titleOf($anomalyHtml));

    // The name the reader tapped to get here, asked of BOTH screens. Checking
    // only the anomaly side is what let /drift keep a heading -- "Alerts" --
    // that neither the nav item nor its own title used, through a round that
    // was looking for exactly this.
    expect($nameOf($anomalyHtml))->toBe(trans('core::sidebar.nav.unusual_charges'))
        ->and($nameOf($driftHtml))->toBe(trans('core::sidebar.nav.drift_alerts'));
});
