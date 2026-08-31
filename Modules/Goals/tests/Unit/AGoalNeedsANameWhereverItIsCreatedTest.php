<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Core\Models\User;
use Modules\Goals\Public\Exceptions\InvalidGoalNameException;
use Modules\Goals\Public\Services\GoalWriter;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->user = User::create([
        'username' => 'named-goal-fixture',
        'password' => 'opensesame',
        'period_start_day' => 1,
    ]);
    $this->actingAs($this->user);
    $this->writer = app(GoalWriter::class);
});

// The form has always refused a blank name; a migration reaches the writer
// straight past the form and created a goal the form then refused to re-save.
it('refuses to create a goal with a blank name', function (): void {
    try {
        $this->writer->save($this->user, '   ', '500,00', '2026-12-31');
        $this->fail('expected an InvalidGoalNameException');
    } catch (InvalidGoalNameException) {
        expect(DB::table('goals')->where('user_id', $this->user->id)->count())->toBe(0);
    }
});

it('refuses to rename a goal to a blank name', function (): void {
    $goal = $this->writer->save($this->user, 'Holiday', '500,00', '2026-12-31');

    try {
        $this->writer->update($this->user, $goal->id, '', '500,00', '2026-12-31');
        $this->fail('expected an InvalidGoalNameException');
    } catch (InvalidGoalNameException) {
        expect(DB::table('goals')->where('id', $goal->id)->value('name'))->toBe('Holiday');
    }
});
