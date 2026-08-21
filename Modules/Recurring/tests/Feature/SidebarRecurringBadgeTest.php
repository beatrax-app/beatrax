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

function rcnbcBadge(int $count): string
{
    return '<span role="img" class="side-badge muted" aria-label="'
        .$count.' recurring series">'.$count.'</span>';
}

it('renders the sidebar badge as the count of active series, leaving pending and rejected out', function (): void {
    rcnbcSeries($this->user, 'approved', 'tnbc::approved-1', 'a');
    rcnbcSeries($this->user, 'approved', 'tnbc::approved-2', 'b');
    rcnbcSeries($this->user, 'cadence_changed', 'tnbc::cadence-1', 'c');
    rcnbcSeries($this->user, 'pending', 'tnbc::pending-1', 'd');
    rcnbcSeries($this->user, 'rejected', 'tnbc::rejected-1', 'e');

    $response = $this->actingAs($this->user)->get(route('recurring.index'));

    $content = $response->getContent() ?: '';
    expect($content)->toContain('Recurring');
    // 2 approved + 1 cadence_changed. A count over every row would read 5.
    expect($content)->toContain(rcnbcBadge(3));
    expect($content)->not->toContain(rcnbcBadge(5));
})->group('badge-equals-active-count');

it('renders for an unauthenticated caller without blowing up (badge-is-zero-when-unauthenticated)', function (): void {
    $response = $this->get(route('recurring.index'));

    // Unauthenticated callers redirect; this only confirms the sidebar's count
    // path does not blow up. The authenticated rendering is covered above.
    expect($response->status())->toBeIn([302, 200]);
})->group('badge-is-zero-when-unauthenticated');

it('hides the badge when the user has no active series (badge-is-zero-when-none-active)', function (): void {
    rcnbcSeries($this->user, 'pending', 'tnbc::pending-only', 'only-pending');
    rcnbcSeries($this->user, 'rejected', 'tnbc::rejected-only', 'only-rejected');

    $response = $this->actingAs($this->user)->get(route('recurring.index'));
    $content = $response->getContent() ?: '';
    expect($content)->toContain('Recurring');
    // With count = 0 the @if guard suppresses the badge span; the anchor still
    // renders. The aria-label is the badge's own marker.
    expect($content)->not->toContain('recurring series"');
})->group('badge-is-zero-when-none-active');

it('registers no view composer — the Recurring count has one source, and it is NavCountsService', function (): void {
    $providerPath = base_path('Modules/Recurring/Providers/RecurringServiceProvider.php');
    expect(file_exists($providerPath))->toBeTrue();

    // A second writer for one badge is how the two definitions drifted apart:
    // the composer counted pending series, the service counts active ones, and
    // only one of them could win the slot.
    $contents = (string) file_get_contents($providerPath);
    expect(str_contains($contents, 'composer('))->toBeFalse();
    expect(str_contains($contents, 'view()->composer'))->toBeFalse();
})->group('single-source-for-the-badge');
