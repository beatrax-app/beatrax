<?php

declare(strict_types=1);

namespace Modules\Pots\Public\Enums;

// The allocation ledger's own vocabulary: what a `pot_movements` row is. The
// two transfer cases are spelled exactly like `TransactionType::TransferOut` /
// `TransferIn` and mean something else entirely — money moving between two pots
// carved out of ONE account balance, never between two accounts.
enum PotMovementKind: string
{
    case Fund = 'fund';

    case Withdraw = 'withdraw';

    case TransferOut = 'transfer_out';

    case TransferIn = 'transfer_in';

    // Archiving a pot with a balance releases it back to the account, and the
    // screen names that from the kind rather than from a memo: `memo` is a
    // synced free-text column, so a sentence written there would have reached a
    // peer on an older build frozen in whatever language wrote it.
    case ReleasedOnArchive = 'released_on_archive';

    public function isIncoming(): bool
    {
        return $this === self::Fund || $this === self::TransferIn;
    }
}
