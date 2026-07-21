<?php

declare(strict_types=1);

namespace Modules\EmailScan\Internal;

use DateTimeImmutable;

// Immutable carrier for the four RFC 822 header values the fetcher
// pulls off a raw .eml (sender, name, subject, date) — the boundary
// between MimeHeaderParser and the inbox_messages row insert, so
// downstream code never re-opens the .eml to filter.
final readonly class ParsedMessageHeaders
{
    public function __construct(
        public string $senderEmail,
        public ?string $senderName,
        public ?string $subject,
        public DateTimeImmutable $internalDate,
    ) {}
}
