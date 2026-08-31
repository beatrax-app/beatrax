<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Core\Models\User;
use Modules\Goals\Public\Services\GoalWriter;

uses(RefreshDatabase::class);

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

// A goal saved at 23:59:59 was validated against one calendar day and written
// with the next one, because save() read today() twice.
it('stores the same day the target date was validated against, across midnight', function (): void {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-05-15 23:59:59'));

    $user = User::create([
        'username' => 'midnight-goal',
        'password' => 'opensesame',
        'period_start_day' => 1,
    ]);
    $this->actingAs($user);

    // Every read after the first lands on the next day, which is what a save
    // straddling midnight sees.
    $reads = 0;
    CarbonImmutable::setTestNow(static function () use (&$reads): CarbonImmutable {
        $reads++;

        return $reads === 1
            ? CarbonImmutable::parse('2026-05-15 23:59:59')
            : CarbonImmutable::parse('2026-05-16 00:00:01');
    });

    $goal = app(GoalWriter::class)->save($user, 'Holiday', '500,00', '2026-05-15');

    CarbonImmutable::setTestNow();

    expect(DB::table('goals')->where('id', $goal->id)->value('start_date'))->toStartWith('2026-05-15');
});
