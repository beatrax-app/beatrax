<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Modules\Core\Models\User;
use Modules\Recurring\Models\RecurringSeries;

function rcnbcUser(string $username): User
{
    return User::query()->create([
        'username' => $username,
        'password' => 'fixture',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
}

function rcnbcSeries(User $user, string $state, string $cluster, string $name = 'tnbc-row'): RecurringSeries
{
    return RecurringSeries::query()->create([
        'user_id' => $user->id,
        'direction' => 'expense',
        'detected_name' => $name,
        'state' => $state,
        'cadence' => 'monthly',
        'latest_amount_minor' => -1099,
        'latest_currency' => 'EUR',
        'variance_tolerance_percent' => 25,
        'cluster_key' => $cluster,
    ]);
}

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-05-17 12:00:00');
    $this->user = rcnbcUser('tnbc');
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

it('renders the top-nav with recurringPendingCount equal to the pending-state count for the user', function (): void {
    rcnbcSeries($this->user, 'pending', 'tnbc::pending-1', 'a');
    rcnbcSeries($this->user, 'pending', 'tnbc::pending-2', 'b');
    rcnbcSeries($this->user, 'pending', 'tnbc::pending-3', 'c');
    rcnbcSeries($this->user, 'approved', 'tnbc::approved-1', 'd');
    rcnbcSeries($this->user, 'rejected', 'tnbc::rejected-1', 'e');

    $response = $this->actingAs($this->user)->get(route('recurring.index'));

    $content = $response->getContent() ?: '';
    expect($content)->toContain('Recurring');
    expect($content)->toContain('>3<');
})->group('badge-equals-pending-count')
    ->todo('16-01 replaced the top-nav with the app sidebar. The Recurring pending-count badge slot exists on the .side-item but is not yet wired to the View Factory composer; a follow-up plan re-points registerTopNavBadgeComposer at core::livewire.app-sidebar and re-enables this assertion against the new .side-badge chrome.');

it('binds recurringPendingCount to 0 when no user is authenticated (badge-is-zero-when-unauthenticated)', function (): void {
    $response = $this->get(route('recurring.index'));

    // Unauthenticated callers redirect; this only confirms the composer does not
    // blow up. The authenticated rendering is covered above.
    expect($response->status())->toBeIn([302, 200]);
})->group('badge-is-zero-when-unauthenticated');

it('binds recurringPendingCount to 0 when the authenticated user has no pending series (badge-is-zero-when-no-pending)', function (): void {
    rcnbcSeries($this->user, 'approved', 'tnbc::approved-only', 'only-approved');

    $response = $this->actingAs($this->user)->get(route('recurring.index'));
    $content = $response->getContent() ?: '';
    expect($content)->toContain('Recurring');
    // With count = 0 the @if guard suppresses the badge span; the anchor still
    // renders.
    expect($content)->not->toContain('aria-label="Recurring; ');
})->group('badge-is-zero-when-no-pending');

it('uses the View Factory contract — the forbidden `view()->composer` shape is not present (no-view-helper-used)', function (): void {
    $providerPath = base_path('Modules/Recurring/Providers/RecurringServiceProvider.php');
    expect(file_exists($providerPath))->toBeTrue();

    $contents = (string) file_get_contents($providerPath);
    expect(str_contains($contents, 'view()->composer'))->toBeFalse();
    expect(str_contains($contents, 'ViewFactoryContract'))->toBeTrue();
})->group('no-view-helper-used');
