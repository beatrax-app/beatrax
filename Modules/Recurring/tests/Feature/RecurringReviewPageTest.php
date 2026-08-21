<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Core\Public\Enums\SnoozeWindow;
use Modules\Core\Public\Support\Lang;
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

// Measured on an iPhone 12 mini: the row was 560px wide inside a 375px
// viewport, Snooze showed 23px of its 80 and Edit name none of it, and the
// horizontal scroller meant to reach them was never found by a swipe. The row
// reflows now, so neither mechanism is left to fail.
it('keeps the review row within phone width instead of pinning it to a scroller', function (): void {
    rrpSeries($this->user, 'pending', 'rrp::phone-width', 'nordwind media bv');

    $html = (string) $this->actingAs($this->user)->get(route('recurring.review'))->getContent();

    $row = mb_strstr(mb_strstr($html, '<ul class="space-y-3"'), '</ul>', true);
    expect($row)->toBeString();

    expect($row)->not->toContain('min-width')
        ->and($row)->not->toContain('overflow-x')
        // Four actions cannot sit on one 343px line, so the cluster has to wrap
        // rather than refuse to compress.
        ->and($row)->toContain('flex flex-wrap items-center gap-2');
});

// One @foreach over SnoozeWindow replaced three hand-written buttons; this
// holds it to what they emitted — bare-integer series id, this module's own
// label keys, and no menuitem role.
it('draws a snooze button for every window, wired the way the hand-written three were', function (): void {
    $series = rrpSeries($this->user, 'pending', 'rrp::render-snooze');

    $content = (string) $this->actingAs($this->user)->get(route('recurring.review'))->getContent();

    foreach (SnoozeWindow::cases() as $window) {
        expect($content)
            ->toContain('wire:click="snooze('.$series->id.", '")
            ->toContain(Lang::get($window->labelKey('recurring::review')));
    }

    expect(substr_count($content, 'class="block w-full px-2 py-1 text-left hover:bg-slate-50 dark:hover:bg-slate-900"'))
        ->toBe(count(SnoozeWindow::cases()));
    expect($content)->not->toContain('role="menuitem"');
});

// One $rowActionClass and one $rowPopoverClass replaced three and two copies.
// These hold the render to the exact class sets the copies emitted, so a later
// edit to either string has to be a deliberate restyle of all of its sites.
it('paints every neutral row action from one class string', function (): void {
    rrpSeries($this->user, 'pending', 'rrp::chrome-pending');

    $content = (string) $this->actingAs($this->user)->get(route('recurring.review'))->getContent();

    $chip = 'inline-flex items-center gap-1 rounded-md bg-slate-100 px-2.5 py-1 text-xs font-medium text-slate-700 hover:bg-slate-200 dark:bg-slate-800 dark:text-slate-300 dark:hover:bg-slate-700';

    // Snooze and Edit name on the pending row; Un-reject is the rejected tab's.
    expect(substr_count($content, 'class="'.$chip.'"'))->toBe(2);

    $rejected = rrpSeries($this->user, 'rejected', 'rrp::chrome-rejected');
    $rejectedHtml = Livewire::actingAs($this->user)
        ->test(RecurringReviewPage::class)
        ->set('tab', 'rejected')
        ->html();

    expect($rejectedHtml)
        ->toContain('wire:click="unReject('.$rejected->id.')"')
        ->and(substr_count($rejectedHtml, 'class="'.$chip.'"'))->toBe(1);
});

it('anchors both row popovers to the row with one shape and differs only by width', function (): void {
    rrpSeries($this->user, 'pending', 'rrp::chrome-popover');

    $content = (string) $this->actingAs($this->user)->get(route('recurring.review'))->getContent();

    $shape = 'absolute inset-x-0 z-10 mt-1 rounded-md border border-slate-200 bg-white p-2 shadow-lg sm:left-auto sm:right-0 dark:bg-slate-950 dark:border-slate-700';

    // The wrapper is static below sm so inset-x-0 resolves against the row, and
    // sm:relative at sm+ is what sm:left-auto/sm:right-0 re-anchors against.
    expect(substr_count($content, 'class="sm:relative"'))->toBe(2)
        ->and(substr_count($content, $shape))->toBe(2)
        ->and($content)->toContain('class="'.$shape.' text-xs sm:w-48"')
        ->and($content)->toContain('class="'.$shape.' sm:w-64"');

    // Recurring's panels carry no menu semantics; the anomaly partial's do.
    // Reconciling them is a decision, not something this extraction may drift.
    expect($content)->not->toContain('role="menu"');
});
