<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\User;
use Modules\Goals\Models\Goal;

uses(RefreshDatabase::class);

it('creates and retrieves a goal via the factory', function (): void {
    $user = User::create([
        'username' => 'wessel',
        'password' => 'opensesame',
        'period_start_day' => 1,
    ]);

    $goal = Goal::factory()->create([
        'user_id' => $user->id,
        'name' => 'Emergency fund',
        'target_minor' => 500000,
        'target_currency' => 'EUR',
        'status' => 'active',
    ]);

    expect($goal->name)->toBe('Emergency fund');
    expect($goal->target_minor)->toBe(500000);
    expect($goal->target_currency)->toBe('EUR');
    expect($goal->status)->toBe('active');
    expect($goal->start_date)->toBeInstanceOf(CarbonImmutable::class);
    expect($goal->target_date)->toBeInstanceOf(CarbonImmutable::class);
});

it('hides another users goals via the BelongsToUser global scope', function (): void {
    $alice = User::create([
        'username' => 'alice',
        'password' => 'opensesame',
        'period_start_day' => 1,
    ]);
    $bob = User::create([
        'username' => 'bob',
        'password' => 'opensesame',
        'period_start_day' => 1,
    ]);

    Goal::factory()->create(['user_id' => $alice->id, 'name' => 'Alices goal']);
    Goal::factory()->create(['user_id' => $bob->id, 'name' => 'Bobs goal']);

    $this->actingAs($alice);

    $visible = Goal::query()->get();
    expect($visible)->toHaveCount(1);
    expect($visible->first()->name)->toBe('Alices goal');
});

it('stores nullable user_id when no user is set', function (): void {
    $goal = Goal::factory()->create([
        'user_id' => null,
        'name' => 'Ownerless goal',
        'target_minor' => 100000,
    ]);

    $this->assertDatabaseHas('goals', [
        'id' => $goal->id,
        'user_id' => null,
        'name' => 'Ownerless goal',
        'target_minor' => 100000,
    ]);
});
