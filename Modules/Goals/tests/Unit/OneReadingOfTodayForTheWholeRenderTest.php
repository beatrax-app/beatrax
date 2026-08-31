<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\User;
use Modules\Goals\Models\Goal;
use Modules\Goals\Public\Enums\GoalStatus;
use Modules\Goals\Public\Services\GoalProjectionService;

// The service read CarbonImmutable::today() in four places and the query in a
// fifth, so a render that straddled midnight measured one goal's observation
// window against yesterday and dated the next one from today. The day is read
// once by the caller and threaded, and this test is what proves it is threaded
// rather than merely accepted: the day passed in decides, not the clock.

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->user = User::query()->create([
        'username' => 'oneday-'.bin2hex(random_bytes(4)),
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
        'base_currency' => 'EUR',
    ]);
    $this->actingAs($this->user);

    $this->goal = Goal::query()->create([
        'user_id' => $this->user->id,
        'name' => 'Middernacht',
        'start_date' => '2026-06-09',
        'target_minor' => 100000,
        'target_currency' => 'EUR',
        'target_date' => '2027-01-01',
        'status' => GoalStatus::Active->value,
    ]);

    $this->attributed = [
        ['amountMinor' => 10000, 'currency' => 'EUR', 'postedAt' => '2026-06-10'],
    ];
});

afterEach(fn () => CarbonImmutable::setTestNow(null));

it('measures the observation window against the day it was handed, not the clock', function (): void {
    // The clock has already turned over: seven days of history by the ambient
    // reading, six by the one the caller took at the top of the render.
    CarbonImmutable::setTestNow('2026-06-16 00:00:30');

    $projection = app(GoalProjectionService::class)->project(
        $this->goal,
        10000,
        $this->user,
        null,
        $this->attributed,
        [],
        CarbonImmutable::parse('2026-06-15'),
    );

    expect($projection['date'])->toBeNull()
        ->and($projection['stalled'])->toBeFalse();
});

it('dates the finish from the day it was handed', function (): void {
    CarbonImmutable::setTestNow('2026-06-16 00:00:30');

    $projection = app(GoalProjectionService::class)->project(
        $this->goal,
        10000,
        $this->user,
        null,
        $this->attributed,
        [],
        CarbonImmutable::parse('2026-06-17'),
    );

    $fromAmbient = app(GoalProjectionService::class)->project(
        $this->goal,
        10000,
        $this->user,
        null,
        $this->attributed,
        [],
        CarbonImmutable::parse('2026-06-16'),
    );

    expect($projection['date'])->not->toBeNull()
        ->and($fromAmbient['date'])->not->toBeNull()
        ->and($projection['date'])->toBeGreaterThan((string) $fromAmbient['date']);
});
