<?php

declare(strict_types=1);

namespace Modules\Search\Internal\Services;

use Illuminate\Contracts\Session\Session;
use Modules\Sync\Public\Services\SensitiveColumnCodec;

// The one rule both index writers read a sealed source column through. The
// console rebuild refused a column it could not open and the merge-path writer
// wrote the empty string it got back, so a peer syncing into a desktop whose
// window was shut emptied 99 of 148 bodies — one index, two answers.
/**
 * @link ../../../../.docs/features/search/architecture.md#a-column-this-process-cannot-read
 */
final readonly class SearchSourceText
{
    public function __construct(private SensitiveColumnCodec $codec) {}

    // Null when the codec BLANKED the value rather than handing it back: that
    // shape, and only that shape, is ciphertext no epoch in this keyring opens.
    // A plaintext row comes back untouched and indexes normally.
    public function read(string $table, string $column, mixed $stored, int $userId, Session $session): ?string
    {
        if (! is_string($stored) || $stored === '') {
            return '';
        }

        $opened = $this->codec->decryptValue($table, $column, $stored, $userId, $session);

        return ! $opened['decrypted'] && $opened['value'] === '' ? null : $opened['value'];
    }
}
