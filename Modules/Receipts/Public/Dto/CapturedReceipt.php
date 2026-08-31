<?php

declare(strict_types=1);

namespace Modules\Receipts\Public\Dto;

use Modules\Receipts\Public\Enums\MatchOutcomeKind;
use Spatie\LaravelData\Data;

// What the recorder filed for one message, as the row it wrote holds it. A
// caller that only reads the match outcome learns nothing about the message
// behind it, and a screen that has to name the receipt the reader just handed
// over cannot go and find it: file_imports carries no import run.
/**
 * @link ../../../../.docs/features/receipts/architecture.md#when-a-message-is-matched
 */
final class CapturedReceipt extends Data
{
    /**
     * @param  string  $internalDate  The message's own Date header, as stored on the row: 'Y-m-d H:i:s'.
     * @param  string|null  $matcherKey  The matcher that answered, or null when no matcher claimed the sender.
     */
    public function __construct(
        public readonly string $senderEmail,
        public readonly ?string $subject,
        public readonly string $internalDate,
        public readonly MatchOutcomeKind $outcome,
        public readonly ?string $matcherKey = null,
    ) {}

    // Whether the reader would recognise this as the message they handed over.
    // An audit row is written for bytes that are not a message at all, so the
    // row's existence is not evidence that anything was read out of the file.
    public function identified(): bool
    {
        return $this->senderEmail !== '' || trim($this->subject ?? '') !== '';
    }
}
