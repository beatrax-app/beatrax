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
use Psr\Log\LoggerInterface;

/**
 * Decrypt-then-`json_decode` Eloquent cast for `transactions.raw_payload`
 * (CRYPT-01 / D-04 / D-05, {@see SensitiveFieldRegistry}).
 *
 * `get()` is decrypt-then-decode (pass-through, `decrypted: false`, when
 * encryption is not currently usable — {@see SensitiveColumnCodec}).
 *
 * WR-03: `set()` now encrypts symmetrically (encrypt-then-json_encode). It
 * previously stored plaintext on the assumption that {@see RecordTransactions}
 * — which pre-encrypts via `SensitiveColumnCodec::encryptAttrs()` before its
 * raw `insertOrIgnore` — was the SOLE writer. But any other `Model::save()`
 * write path (`$model->raw_payload = [...]; $model->save();`) DOES route
 * through this cast and silently persisted plaintext, a CRYPT-01 bypass with
 * no failure signal. Because RecordTransactions writes via a RAW insert that
 * bypasses this cast entirely, encrypting here does NOT double-encrypt that
 * path — it only makes the previously-unguarded `Model::save()` path
 * safe-by-default. `encryptValue()` is a pass-through no-op for non-encryption
 * users, so plaintext-JSON behavior is preserved on that path.
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

        $result = $codec->decryptValue('transactions', 'raw_payload', $value, $userId, $session);

        /** @var mixed $decoded */
        $decoded = json_decode($result['value'], true);

        if (is_array($decoded)) {
            return $decoded;
        }

        // WR-04: a non-array decode of a NON-empty stored value that did NOT
        // decrypt (`decrypted: false`) is a genuine integrity failure —
        // tampered/corrupt/wrong-key ciphertext — not an empty field. The old
        // code returned null here, making corruption indistinguishable from an
        // absent payload with no error or log. Surface it (log) so the failure
        // is observable in a finance app. We still return null (never leak
        // ciphertext to the caller), but the silent-data-loss signal is gone.
        if (! $result['decrypted']) {
            Container::getInstance()->make(LoggerInterface::class)->warning(
                'EncryptedJsonCast: transactions.raw_payload failed to decrypt/decode — possible corruption or wrong-key ciphertext.',
                ['user_id' => $userId, 'model' => $model::class, 'model_key' => $model->getKey()],
            );
        }

        return null;
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

        $json = is_string($value)
            ? $value
            : json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        // WR-03: encrypt before persisting so a Model::save() write path can
        // never silently store plaintext at rest. Pass-through no-op for
        // non-encryption users. RecordTransactions bypasses this cast (raw
        // insert), so this does not double-encrypt that path.
        $userId = is_numeric($attributes['user_id'] ?? null) ? (int) $attributes['user_id'] : 0;
        $codec = Container::getInstance()->make(SensitiveColumnCodec::class);
        $session = Container::getInstance()->make(Session::class);

        return [$key => $codec->encryptValue('transactions', 'raw_payload', $json, $userId, $session)];
    }
}
