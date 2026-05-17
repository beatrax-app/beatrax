<?php

declare(strict_types=1);

namespace Modules\Receipts\Internal\Pipeline;

use ZBateson\MailMimeParser\Header\HeaderConsts;
use ZBateson\MailMimeParser\MailMimeParser;
use ZBateson\MailMimeParser\Message;

/**
 * Thin facade over zbateson/mail-mime-parser that decodes the body
 * + header surface every Receipts matcher needs.
 *
 * Body-extraction policy:
 *
 *   - text/plain when present (zbateson auto-chooses the first
 *     text/plain part via getTextContent()).
 *   - text/html fallback only when text/plain is null. The html body
 *     is returned verbatim; matchers call html_entity_decode +
 *     strip_tags when they need a plain rendering. Reading text/plain
 *     first matters because multipart/alternative ordering is not
 *     guaranteed to put text/plain first — relying on order would
 *     silently parse the wrong part.
 *
 * Header surface: every header is returned as a lowercase-keyed
 * map (sender-email lowercasing is the only normalisation; everything
 * else is verbatim).
 *
 * Attachment surface: only filenames, since Wave 0 matchers do not
 * yet parse attachment bodies. A future PDF-receipt arm can re-read
 * the .eml to extract bytes.
 *
 * Stateless / singleton-safe.
 */
final class EmlMimeReader
{
    public function read(string $rawEml): ParsedMimeMessage
    {
        $parser = new MailMimeParser;
        $message = $parser->parse($rawEml, true);

        $textBody = null;
        $htmlBody = null;
        $headers = [];
        $attachmentFilenames = [];

        if ($message instanceof Message) {
            $textContent = $message->getTextContent();
            if (is_string($textContent) && $textContent !== '') {
                $textBody = $textContent;
            }

            $htmlContent = $message->getHtmlContent();
            if (is_string($htmlContent) && $htmlContent !== '') {
                $htmlBody = $htmlContent;
            }

            $headers = $this->extractHeaders($message);
            $attachmentFilenames = $this->extractAttachmentFilenames($message);
        }

        return new ParsedMimeMessage(
            textBody: $textBody,
            htmlBody: $htmlBody,
            headers: $headers,
            attachmentFilenames: $attachmentFilenames,
        );
    }

    /**
     * Pull the small set of canonical headers Receipts matchers
     * consult. The map is keyed by lowercase header name. Sender
     * email gets lowercased; everything else is returned verbatim.
     *
     * @return array<string, string>
     */
    private function extractHeaders(Message $message): array
    {
        $headers = [];

        $from = $this->headerValue($message, HeaderConsts::FROM);
        if ($from !== null) {
            $headers['from'] = strtolower($from);
        }

        $subject = $this->headerValue($message, HeaderConsts::SUBJECT);
        if ($subject !== null) {
            $headers['subject'] = $subject;
        }

        $messageId = $this->headerValue($message, HeaderConsts::MESSAGE_ID);
        if ($messageId !== null) {
            $headers['message-id'] = $messageId;
        }

        $date = $this->headerValue($message, HeaderConsts::DATE);
        if ($date !== null) {
            $headers['date'] = $date;
        }

        return $headers;
    }

    private function headerValue(Message $message, string $name): ?string
    {
        $value = $message->getHeaderValue($name);
        if (! is_string($value) || $value === '') {
            return null;
        }

        return $value;
    }

    /**
     * @return list<string>
     */
    private function extractAttachmentFilenames(Message $message): array
    {
        $filenames = [];
        foreach ($message->getAllAttachmentParts() as $part) {
            $name = $part->getFilename();
            if (is_string($name) && $name !== '') {
                $filenames[] = $name;
            }
        }

        return $filenames;
    }
}
