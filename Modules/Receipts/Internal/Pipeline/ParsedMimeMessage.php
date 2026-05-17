<?php

declare(strict_types=1);

namespace Modules\Receipts\Internal\Pipeline;

/**
 * Read-only result of EmlMimeReader::read(). Exposes the decoded
 * text + html body, the parsed header map, and the list of
 * attachment filenames so a matcher can branch on body content
 * (text/plain preferred) and surface attachments to a future
 * PDF-receipt matcher arm.
 *
 * The reader prefers text/plain over text/html when both are
 * present; the matcher consults `textBody` first and falls back to
 * a stripped-tags `htmlBody` only when `textBody` is null. The
 * preference order is non-negotiable: multipart/alternative ordering
 * is provider-specific and not guaranteed to put text/plain first.
 */
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
