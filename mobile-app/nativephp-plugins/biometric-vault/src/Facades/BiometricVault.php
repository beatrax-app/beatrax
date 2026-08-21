<?php

declare(strict_types=1);

namespace Beatrax\BiometricVault\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static bool set(string $key, ?string $value)
 * @method static array get(string $key, string $reason = 'Unlock Beatrax')
 * @method static bool delete(string $key)
 * @method static ?string pollRecovered()
 *
 * @see \Beatrax\BiometricVault\BiometricVault
 */
class BiometricVault extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \Beatrax\BiometricVault\BiometricVault::class;
    }
}
