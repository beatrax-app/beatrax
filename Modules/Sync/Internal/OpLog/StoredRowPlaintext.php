<?php

declare(strict_types=1);

namespace Modules\Sync\Internal\OpLog;

use Modules\Core\Public\Services\SessionFactory;
use Modules\Sync\Internal\Crypto\SensitiveFieldRegistry;
use Modules\Sync\Internal\Exceptions\UnreadableColumnException;
use Modules\Sync\Public\Services\SensitiveColumnCodec;

// Sensitive columns are ciphertext AT REST, and OpLogWriter encrypts what it is
// handed under DIFFERENT associated data. Passing a stored column straight
// through wrapped a second layer round the first, and the peer that unwrapped
// the outer one projected the inner base64 as a name.
/**
 * @link ../../../../.docs/features/sync/sensitive-columns-at-rest.md
 */
final readonly class StoredRowPlaintext
{
    public function __construct(
        private SensitiveFieldRegistry $sensitiveFields,
        private SensitiveColumnCodec $codec,
        private SessionFactory $session,
    ) {}

    /**
     * @param  array<string, mixed>  $fields
     * @return array<string, mixed>
     *
     * @throws UnreadableColumnException when a sealed column opens for no epoch this device holds.
     */
    public function fields(string $table, array $fields, int $userId): array
    {
        $session = ($this->session)();

        /** @var string $field */
        foreach ($fields as $field => $value) {
            if (! is_string($value) || ! $this->sensitiveFields->isSensitive($table, $field)) {
                continue;
            }

            $read = $this->codec->decryptValue($table, $field, $value, $userId, $session);

            // `decrypted: false` with the value handed back untouched is the
            // ordinary pre-encryption row; the codec blanking it instead means
            // it held ciphertext no epoch in the keyring opens.
            if (! $read['decrypted'] && $read['value'] !== $value) {
                throw UnreadableColumnException::duringBackfill($table, $field, $userId);
            }

            $fields[$field] = $read['value'];
        }

        return $fields;
    }
}
