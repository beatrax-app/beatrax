<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Recurring\Internal\Http\Livewire\RecurringReviewPage;
use Modules\Recurring\Internal\Support\DerivedSeriesId;
use Modules\Recurring\Models\RecurringSeries;
use Modules\Recurring\Public\Enums\RecurringSeriesState;

uses(RefreshDatabase::class);

// A detected series carries a DerivedSeriesId so both devices name it the same
// row, which puts it past 2^53. The review card handed that id to the browser
// as a number literal and got a rounded one back, so Approve and Reject did
// nothing at all — measured on an iPhone, and true of every client.

function seriesTripUser(): User
{
    return User::query()->create([
        'username' => 'series-trip-'.bin2hex(random_bytes(4)),
        'password' => 'fixture',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
}

/** @return array{0: User, 1: int} the owner and its one pending series */
function seriesTripPending(): array
{
    $user = seriesTripUser();
    $id = DerivedSeriesId::for((int) $user->id, 'expense', 'series-trip-key', 'EUR');

    // `id` is not fillable and create() drops it silently, which would leave
    // the row on an autoincrement key and prove nothing.
    $series = new RecurringSeries;
    $series->forceFill([
        'id' => $id,
        'user_id' => $user->id,
        'direction' => 'expense',
        'detected_name' => 'Series trip probe',
        'state' => RecurringSeriesState::Pending->value,
        'cadence' => 'monthly',
        'latest_amount_minor' => -1099,
        'latest_currency' => 'EUR',
        'variance_tolerance_percent' => 25,
        'cluster_key' => 'series-trip-cluster',
    ])->save();

    return [$user, $id];
}

function seriesTripState(int $id): ?string
{
    $series = RecurringSeries::query()->find($id);

    return $series?->state;
}

beforeEach(fn () => CarbonImmutable::setTestNow('2026-05-17 12:00:00'));
afterEach(fn () => CarbonImmutable::setTestNow());

it('mints a series id the browser cannot hold as a number', function (): void {
    [, $id] = seriesTripPending();

    expect($id)->toBeGreaterThan(9007199254740991)
        ->and((int) (float) $id)->not->toBe($id);
});

it('approves the series when the id arrives as the string the wire now sends', function (): void {
    [$user, $id] = seriesTripPending();
    $this->actingAs($user);

    Livewire::test(RecurringReviewPage::class)
        ->call('approve', (string) $id)
        ->assertStatus(200);

    expect(seriesTripState($id))->toBe(RecurringSeriesState::Approved->value);
});

// The failure the fix exists for, run rather than described.
it('leaves the series pending when the id arrives rounded, as a number literal did', function (): void {
    [$user, $id] = seriesTripPending();
    $this->actingAs($user);

    Livewire::test(RecurringReviewPage::class)
        ->call('approve', (int) (float) $id)
        ->assertStatus(200);

    expect(seriesTripState($id))->toBe(RecurringSeriesState::Pending->value);
});

it('writes the id into the page quoted, so the browser never parses it as a number', function (): void {
    [$user, $id] = seriesTripPending();
    $this->actingAs($user);

    Livewire::test(RecurringReviewPage::class)
        ->assertSee("approve('".$id."')", false)
        ->assertDontSee('approve('.$id.')', false);
});
