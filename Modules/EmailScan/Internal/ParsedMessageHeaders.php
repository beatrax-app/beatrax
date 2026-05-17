<?php

declare(strict_types=1);

namespace Modules\EmailScan\Internal;

use DateTimeImmutable;

/**
 * Immutable carrier for the four RFC 822 header values the fetcher
 * pulls off a raw .eml at write time: normalised lowercase sender
 * address, optional display name, optional decoded subject, and the
 * message's stamped date.
 *
 * The struct is the boundary between the MimeHeaderParser's zbateson
 * surface and the inbox_messages row insert in BackfillInboxJob — by
 * keeping the four columns we actually persist together in one
 * readonly object, downstream code never has to re-open the .eml to
 * filter by sender or date.
 */
final readonly class ParsedMessageHeaders
{
    public function __construct(
        public string $senderEmail,
        public ?string $senderName,
        public ?string $subject,
        public DateTimeImmutable $internalDate,
    ) {}
}
