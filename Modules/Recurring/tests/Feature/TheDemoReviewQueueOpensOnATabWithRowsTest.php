<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Recurring\Internal\Enums\ReviewTab;
use Modules\Recurring\Internal\Http\Livewire\RecurringReviewPage;
use Modules\Recurring\Public\Enums\RecurringSeriesState;
use Modules\Recurring\Public\Services\RecurringSeriesQuery;

uses(RefreshDatabase::class);

function tdrqDemoUser(): User
{
    return User::query()->where('username', 'demo-1')->firstOrFail();
}

it('opens the review page on a tab the demo dataset has rows for', function (): void {
    $this->artisan('demo:seed')->assertSuccessful();

    $user = tdrqDemoUser();
    $this->actingAs($user);

    expect(app(RecurringSeriesQuery::class)->pendingForUser($user))->not->toBeEmpty();

    Livewire::actingAs($user)
        ->test(RecurringReviewPage::class)
        ->assertSet('tab', ReviewTab::DEFAULT)
        ->assertSeeText('Ziggo');
});

it('fills the cadence-changed tab the demo already wrote a transition for', function (): void {
    $this->artisan('demo:seed')->assertSuccessful();

    $user = tdrqDemoUser();
    $this->actingAs($user);

    expect(app(RecurringSeriesQuery::class)->cadenceChangedForUser($user))->not->toBeEmpty();
});

// A transition claiming a state the series is not in is a demo dataset that
// contradicts itself: the Netflix row said cadence_changed and stayed approved.
it('leaves every seeded series in the state its newest transition landed on', function (): void {
    $this->artisan('demo:seed')->assertSuccessful();

    $user = tdrqDemoUser();

    $states = DB::table('recurring_series')
        ->where('user_id', $user->id)
        ->pluck('state', 'id')
        ->all();

    $disagreements = [];
    foreach (array_keys($states) as $seriesId) {
        $newest = DB::table('recurring_series_transitions')
            ->where('user_id', $user->id)
            ->where('recurring_series_id', $seriesId)
            ->orderByDesc('transitioned_at')
            ->orderByDesc('id')
            ->value('to_state');

        if ($newest !== null && $newest !== $states[$seriesId]) {
            $disagreements[] = "series {$seriesId}: transitioned to {$newest}, sits at {$states[$seriesId]}";
        }
    }

    expect($disagreements)->toBe([]);
});

it('gives the pending series the occurrence history the detector would have watched', function (): void {
    $this->artisan('demo:seed')->assertSuccessful();

    $user = tdrqDemoUser();

    $pendingIds = DB::table('recurring_series')
        ->where('user_id', $user->id)
        ->where('state', RecurringSeriesState::Pending->value)
        ->pluck('detected_name', 'id')
        ->all();

    expect($pendingIds)->not->toBeEmpty();

    foreach ($pendingIds as $seriesId => $name) {
        $observed = DB::table('recurring_series_occurrences')
            ->where('user_id', $user->id)
            ->where('recurring_series_id', $seriesId)
            ->count();

        expect($observed)->toBeGreaterThan(0, "{$name} was seeded with no occurrence history");
    }
});
