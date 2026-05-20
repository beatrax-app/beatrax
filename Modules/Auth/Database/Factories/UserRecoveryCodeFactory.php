<?php

declare(strict_types=1);

namespace Modules\Auth\Database\Factories;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\Auth\Models\UserRecoveryCode;
use Modules\Core\Models\User;

/**
 * Test-fixture factory for UserRecoveryCode rows.
 *
 * Each generated row carries a freshly hashed code and, unless a
 * `user_id` is supplied, a newly created owning user. The default state
 * leaves `used_at` null, modelling an unconsumed code.
 *
 * @extends Factory<UserRecoveryCode>
 */
final class UserRecoveryCodeFactory extends Factory
{
    /** @var class-string<UserRecoveryCode> */
    protected $model = UserRecoveryCode::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'user_id' => fn (): int => User::query()->create([
                'username' => 'recovery-'.Str::lower(Str::random(12)),
                'password' => 'fixture-password',
                'period_start_day' => 1,
            ])->id,
            'code_hash' => password_hash(Str::random(16), PASSWORD_BCRYPT),
            'used_at' => null,
        ];
    }

    /**
     * State for a code that has already been consumed.
     */
    public function used(): self
    {
        return $this->state(static fn (): array => ['used_at' => CarbonImmutable::now()]);
    }
}
