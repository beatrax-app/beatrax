<?php

declare(strict_types=1);

namespace Modules\Sync\Public\Casts;

use Illuminate\Container\Container;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Contracts\Session\Session;
use Illuminate\Database\Eloquent\Model;
use Modules\Ledger\Public\Actions\RecordTransactions;
use Modules\Sync\Public\Services\SensitiveColumnCodec;
use Psr\Log\LoggerInterface;

/**
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

        // A non-array decode of a NON-empty stored value that did NOT
        // decrypt is a genuine integrity failure — tampered/corrupt/wrong-key
        // ciphertext, not an empty field — so surface it via a log rather
        // than silently returning null with no signal. Still never leak ciphertext to the caller.
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

        // Encrypt before persisting so a Model::save() write path can never
        // silently store plaintext at rest. Pass-through no-op for
        // non-encryption users; RecordTransactions bypasses this cast (raw
        // insert), so this does not double-encrypt that path.
        $userId = is_numeric($attributes['user_id'] ?? null) ? (int) $attributes['user_id'] : 0;
        $codec = Container::getInstance()->make(SensitiveColumnCodec::class);
        $session = Container::getInstance()->make(Session::class);

        return [$key => $codec->encryptValue('transactions', 'raw_payload', $json, $userId, $session)];
    }
}
