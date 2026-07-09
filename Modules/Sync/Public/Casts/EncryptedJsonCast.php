<?php

declare(strict_types=1);

namespace Modules\Sync\Public\Casts;

use Illuminate\Container\Container;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Contracts\Session\Session;
use Illuminate\Database\Eloquent\Model;
use Modules\Ledger\Public\Actions\RecordTransactions;
use Modules\Sync\Internal\Crypto\SensitiveFieldRegistry;
use Modules\Sync\Public\Services\SensitiveColumnCodec;

/**
 * Decrypt-then-`json_decode` Eloquent cast for `transactions.raw_payload`
 * (CRYPT-01 / D-04 / D-05, {@see SensitiveFieldRegistry}).
 *
 * `get()` is decrypt-then-decode (pass-through, `decrypted: false`, when
 * encryption is not currently usable — {@see SensitiveColumnCodec}).
 * `set()` deliberately never encrypts: {@see RecordTransactions}
 * is the sole production writer and already calls
 * `SensitiveColumnCodec::encryptAttrs()` before its raw `insertOrIgnore`
 * INSERT, which bypasses this cast entirely. A `set()` that encrypted
 * would double-encrypt any future `Model::save()` write path.
 *
 * Casts are instantiated by Eloquent with no constructor arguments, so
 * the codec/session are resolved lazily via `Container::getInstance()`
 * rather than the `app()` global helper (project DI-only rule).
 *
 * @implements CastsAttributes<array<int|string, mixed>|null, array<int|string, mixed>|string|null>
 */
final class EncryptedJsonCast implements CastsAttributes
{
    /**
     * @param  array<string, mixed>  $attributes
     * @return array<int|string, mixed>|null
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): ?array
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        $userId = is_numeric($attributes['user_id'] ?? null) ? (int) $attributes['user_id'] : 0;

        $codec = Container::getInstance()->make(SensitiveColumnCodec::class);
        $session = Container::getInstance()->make(Session::class);

        $plain = $codec->decryptValue('transactions', 'raw_payload', $value, $userId, $session)['value'];

        /** @var mixed $decoded */
        $decoded = json_decode($plain, true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, string|null>
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): array
    {
        if ($value === null) {
            return [$key => null];
        }

        if (is_string($value)) {
            return [$key => $value];
        }

        return [$key => json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)];
    }
}
