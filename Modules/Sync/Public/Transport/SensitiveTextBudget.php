<?php

declare(strict_types=1);

namespace Modules\Sync\Public\Transport;

// How long a free-text value a sealed column may hold before the op carrying it
// stops fitting on the wire. Owned here rather than by whichever screen edits
// the column, because the ceiling belongs to the transport and the screens
// enforcing it are in other modules.
/**
 * @link ../../../../.docs/features/sync/peer-session-lifecycle.md#one-entry-that-can-never-be-framed
 */
final class SensitiveTextBudget
{
    // A sealed column travels as base64(nonce ‖ XChaCha20 ‖ tag) over the
    // JSON-escaped plaintext, so one astral character costs 12 bytes there and
    // ~16 on the wire. 3000 of them is ~48 KB, which leaves the rest of the
    // entry room inside TransportFramer::MAX_PAYLOAD_BYTES in any script.
    public const int MAX_PLAINTEXT_CHARACTERS = 3000;
}
