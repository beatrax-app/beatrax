<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Recurring\Internal\Http\Livewire\RecurringReviewPage;
use Modules\Recurring\Models\RecurringSeries;
use Modules\Recurring\Public\Actions\ApproveRecurringSeries;
use Modules\Recurring\Public\Services\RecurringSeriesQuery;

function rrpUser(string $username): User
{
    return User::query()->create([
        'username' => $username,
        'password' => 'fixture',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
}

function rrpSeries(User $user, string $state, string $cluster, string $name = 'rrp-probe'): RecurringSeries
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
    $this->user = rrpUser('rrp');
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

it('requires auth — GET /recurring/review redirects to /login when unauthenticated', function (): void {
    $this->get(route('recurring.review'))->assertRedirect('/login');
});

it('GET /recurring/review for an authenticated user renders the page', function (): void {
    $this->actingAs($this->user)
        ->get(route('recurring.review'))
        ->assertOk()
        ->assertSeeText('Review recurring');
});

it('pending tab shows the pending series rows', function (): void {
    $series = rrpSeries($this->user, 'pending', 'rrp::pending', 'spotify');

    $response = $this->actingAs($this->user)->get(route('recurring.review'));
    $response->assertOk()
        ->assertSeeText('spotify')
        ->assertSee('wire:click="approve('.$series->id.')"', false)
        ->assertSee('wire:click="reject('.$series->id.')"', false);
});

it('approve action flips the series state to approved and dispatches a toast', function (): void {
    $series = rrpSeries($this->user, 'pending', 'rrp::approve');

    $component = Livewire::actingAs($this->user)
        ->test(RecurringReviewPage::class)
        ->call('approve', $series->id);

    $fresh = RecurringSeries::query()->findOrFail($series->id);
    expect($fresh->state)->toBe('approved');

    $component->assertDispatched('toast');
});

it('reject action flips the series state to rejected', function (): void {
    $series = rrpSeries($this->user, 'pending', 'rrp::reject');

    Livewire::actingAs($this->user)
        ->test(RecurringReviewPage::class)
        ->call('reject', $series->id);

    $fresh = RecurringSeries::query()->findOrFail($series->id);
    expect($fresh->state)->toBe('rejected');
});

it('snooze action writes snoozed_until and flips the state', function (): void {
    $series = rrpSeries($this->user, 'pending', 'rrp::snooze');

    Livewire::actingAs($this->user)
        ->test(RecurringReviewPage::class)
        ->call('snooze', $series->id, '2026-06-17T12:00:00+00:00');

    /** @var RecurringSeries $fresh */
    $fresh = RecurringSeries::query()->findOrFail($series->id);
    expect($fresh->state)->toBe('snoozed');
    expect($fresh->snoozed_until)->not->toBeNull();
});

it('editName action persists display_name_override', function (): void {
    $series = rrpSeries($this->user, 'pending', 'rrp::edit', 'spotify');

    Livewire::actingAs($this->user)
        ->test(RecurringReviewPage::class)
        ->call('editName', $series->id, 'Spotify (family)');

    /** @var RecurringSeries $fresh */
    $fresh = RecurringSeries::query()->findOrFail($series->id);
    expect($fresh->display_name_override)->toBe('Spotify (family)');
});

it('setTab switches the visible queue between pending and rejected', function (): void {
    $pending = rrpSeries($this->user, 'pending', 'rrp::tab-pending', 'spotify-pending');
    $rejected = rrpSeries($this->user, 'rejected', 'rrp::tab-rejected', 'netflix-rejected');

    Livewire::actingAs($this->user)
        ->test(RecurringReviewPage::class)
        ->assertSet('tab', 'pending')
        ->assertSee('spotify-pending')
        ->assertDontSee('netflix-rejected')
        ->call('setTab', 'rejected')
        ->assertSet('tab', 'rejected')
        ->assertSee('netflix-rejected')
        ->assertDontSee('spotify-pending');
});

it('unReject action promotes a rejected series back to pending', function (): void {
    $series = rrpSeries($this->user, 'rejected', 'rrp::unreject');

    Livewire::actingAs($this->user)
        ->test(RecurringReviewPage::class)
        ->set('tab', 'rejected')
        ->call('unReject', $series->id);

    /** @var RecurringSeries $fresh */
    $fresh = RecurringSeries::query()->findOrFail($series->id);
    expect($fresh->state)->toBe('pending');
});

it('a pending series is NOT visible in approvedForUser until Approve fires (suggest-not-applied)', function (): void {
    $series = rrpSeries($this->user, 'pending', 'rrp::sna', 'sna-spotify');

    /** @var RecurringSeriesQuery $query */
    $query = $this->app->make(RecurringSeriesQuery::class);

    $approved = $query->approvedForUser($this->user);
    expect($approved)->toBeEmpty();

    /** @var ApproveRecurringSeries $approve */
    $approve = $this->app->make(ApproveRecurringSeries::class);
    ($approve)($series->id, $this->user);

    $approvedAfter = $query->approvedForUser($this->user);
    expect($approvedAfter)->toHaveCount(1);
    expect($approvedAfter[0]->seriesId)->toBe($series->id);
});

it('renders an empty-state message when no pending series exist', function (): void {
    $this->actingAs($this->user)
        ->get(route('recurring.review'))
        ->assertOk()
        ->assertSeeText('Nothing to review');
});

it('encodes a display name that contains an apostrophe as a JS-safe string literal in the edit-name x-data block', function (): void {
    // The legacy template interpolated $row->displayName() straight into a
    // single-quoted Alpine string, so an apostrophe in the name terminated the JS
    // string early, broke the SFC, and — with more than one user — would be an
    // XSS sink.
    rrpSeries($this->user, 'pending', 'rrp::xss', "alice's plan");

    $response = $this->actingAs($this->user)->get(route('recurring.review'));
    $response->assertOk();

    $content = (string) $response->getContent();
    // The unsafe shape: a single-quoted JS string carrying a bare apostrophe
    // (a browser-decoded `&#039;` collapses to `'` and breaks JS parsing).
    expect($content)->not->toContain("newName: 'alice's plan'");
    // The safe shape is @js() output, whose escaped apostrophe parses cleanly as
    // JS whichever quote style the browser picks for the attribute.
    expect($content)->toContain('alice\\u0027s plan');
})->group('display-name-js-safe');
