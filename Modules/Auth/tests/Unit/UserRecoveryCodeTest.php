<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Auth\Models\UserRecoveryCode;
use Modules\Core\Models\User;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->user = User::query()->create([
        'username' => 'recovery-fixture',
        'password' => 'fixture-password',
        'period_start_day' => 1,
    ]);
});

it('persists a recovery code with a null used_at by default', function (): void {
    $code = new UserRecoveryCode([
        'user_id' => $this->user->id,
        'code_hash' => 'hashed-code-value',
    ]);
    $code->save();

    $reloaded = UserRecoveryCode::query()->findOrFail($code->id);

    expect($reloaded->user_id)->toBe($this->user->id);
    expect($reloaded->code_hash)->toBe('hashed-code-value');
    expect($reloaded->used_at)->toBeNull();
});

it('returns every recovery code issued to a user with used_at null', function (): void {
    foreach (range(1, 10) as $index) {
        UserRecoveryCode::query()->create([
            'user_id' => $this->user->id,
            'code_hash' => 'hashed-code-'.$index,
        ]);
    }

    $codes = UserRecoveryCode::query()->where('user_id', $this->user->id)->get();

    expect($codes)->toHaveCount(10);
    expect($codes->every(static fn (UserRecoveryCode $code): bool => $code->used_at === null))->toBeTrue();
});

it('does not auto-manage an updated_at timestamp', function (): void {
    $code = UserRecoveryCode::query()->create([
        'user_id' => $this->user->id,
        'code_hash' => 'hashed-code-value',
    ]);

    expect($code->timestamps)->toBeFalse();
});
