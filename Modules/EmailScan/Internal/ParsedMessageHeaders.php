<?php

declare(strict_types=1);

namespace Modules\EmailScan\Internal;

use DateTimeImmutable;

final readonly class ParsedMessageHeaders
{
    public function __construct(
        public string $senderEmail,
        public ?string $senderName,
        public ?string $subject,
        public DateTimeImmutable $internalDate,
    ) {}
}
