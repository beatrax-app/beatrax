<?php

declare(strict_types=1);

namespace Modules\Import\Internal\Enums;

use Modules\Core\Public\Support\Lang;
use Modules\Receipts\Public\Dto\CapturedReceipt;
use Modules\Receipts\Public\Enums\MatchOutcomeKind;

// What became of one captured message, in the words the reader needs. The
// stored status collapses two answers into `unmatched` -- a sender no matcher
// reads, and a sender one of them reads but could not read a payment out of --
// and only the matcher that answered tells them apart.
/**
 * @link ../../../../.docs/features/import/architecture.md#an-email-drop-is-not-an-empty-statement
 */
enum ReceiptCaptureState: string
{
    case Read = 'read';

    case NotAPayment = 'not_a_payment';

    case Unreadable = 'unreadable';

    case UnknownSender = 'unknown_sender';

    public static function of(CapturedReceipt $capture): self
    {
        return match ($capture->outcome) {
            MatchOutcomeKind::Parsed => self::Read,
            MatchOutcomeKind::Skipped => self::NotAPayment,
            MatchOutcomeKind::Unmatched => $capture->matcherKey === null
                ? self::UnknownSender
                : self::Unreadable,
        };
    }

    public function label(): string
    {
        return Lang::get('import::preview.receipts.state.'.$this->value);
    }
}
