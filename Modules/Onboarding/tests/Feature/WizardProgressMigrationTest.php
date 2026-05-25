<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Models\User;

it('creates the wizard_progress table with the documented schema', function (): void {
    expect(Schema::hasTable('wizard_progress'))->toBeTrue();
    expect(Schema::hasColumns('wizard_progress', [
        'id', 'user_id', 'step_key', 'status', 'data', 'completed_at',
        'created_at', 'updated_at',
    ]))->toBeTrue();
});

it('accepts every allowed status value', function (): void {
    $user = User::create([
        'username' => 'wizard-allowed',
        'password' => 'fixture-password',
        'period_start_day' => 1,
    ]);

    foreach (['pending', 'in_progress', 'done', 'skipped'] as $i => $status) {
        DB::table('wizard_progress')->insert([
            'user_id' => $user->id,
            'step_key' => 'step-'.$i,
            'status' => $status,
            'data' => null,
            'completed_at' => null,
            'created_at' => CarbonImmutable::now()->toDateTimeString(),
            'updated_at' => CarbonImmutable::now()->toDateTimeString(),
        ]);
    }

    expect(DB::table('wizard_progress')->where('user_id', $user->id)->count())->toBe(4);
});

it('rejects an unknown wizard_progress.status value at the DB layer', function (): void {
    $user = User::create([
        'username' => 'wizard-bogus',
        'password' => 'fixture-password',
        'period_start_day' => 1,
    ]);

    expect(fn () => DB::table('wizard_progress')->insert([
        'user_id' => $user->id,
        'step_key' => 'welcome',
        'status' => 'BOGUS',
        'data' => null,
        'completed_at' => null,
        'created_at' => CarbonImmutable::now()->toDateTimeString(),
        'updated_at' => CarbonImmutable::now()->toDateTimeString(),
    ]))->toThrow(QueryException::class, 'Invalid wizard_progress.status value');
});

it('rejects an unknown wizard_progress.status value on UPDATE', function (): void {
    $user = User::create([
        'username' => 'wizard-update',
        'password' => 'fixture-password',
        'period_start_day' => 1,
    ]);

    $id = DB::table('wizard_progress')->insertGetId([
        'user_id' => $user->id,
        'step_key' => 'welcome',
        'status' => 'pending',
        'data' => null,
        'completed_at' => null,
        'created_at' => CarbonImmutable::now()->toDateTimeString(),
        'updated_at' => CarbonImmutable::now()->toDateTimeString(),
    ]);

    expect(fn () => DB::table('wizard_progress')->where('id', $id)->update([
        'status' => 'WHATEVER',
    ]))->toThrow(QueryException::class, 'Invalid wizard_progress.status value');
});

it('enforces uniqueness on (user_id, step_key)', function (): void {
    $user = User::create([
        'username' => 'wizard-unique',
        'password' => 'fixture-password',
        'period_start_day' => 1,
    ]);

    DB::table('wizard_progress')->insert([
        'user_id' => $user->id,
        'step_key' => 'welcome',
        'status' => 'pending',
        'data' => null,
        'completed_at' => null,
        'created_at' => CarbonImmutable::now()->toDateTimeString(),
        'updated_at' => CarbonImmutable::now()->toDateTimeString(),
    ]);

    expect(fn () => DB::table('wizard_progress')->insert([
        'user_id' => $user->id,
        'step_key' => 'welcome',
        'status' => 'done',
        'data' => null,
        'completed_at' => null,
        'created_at' => CarbonImmutable::now()->toDateTimeString(),
        'updated_at' => CarbonImmutable::now()->toDateTimeString(),
    ]))->toThrow(QueryException::class);
});
