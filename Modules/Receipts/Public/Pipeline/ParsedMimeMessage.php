<?php

declare(strict_types=1);

namespace Modules\Receipts\Public\Pipeline;

// Read-only result of EmlMimeReader::read(). textBody is preferred;
// matchers fall back to a stripped-tags htmlBody only when textBody is
// null — multipart/alternative ordering is provider-specific and not
// guaranteed to put text/plain first.
final readonly class ParsedMimeMessage
{
    /**
     * @param  array<string, string>  $headers
     * @param  list<string>  $attachmentFilenames
     */
    public function __construct(
        public ?string $textBody,
        public ?string $htmlBody,
        public array $headers,
        public array $attachmentFilenames,
    ) {}
}
