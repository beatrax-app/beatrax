<?php

declare(strict_types=1);

namespace Modules\Receipts\Public\Pipeline;

use ZBateson\MailMimeParser\Header\HeaderConsts;
use ZBateson\MailMimeParser\MailMimeParser;
use ZBateson\MailMimeParser\Message;

// Thin facade over zbateson/mail-mime-parser: prefers text/plain,
// falling back to verbatim text/html only when text/plain is absent —
// multipart/alternative part ordering is not guaranteed to put
// text/plain first.
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
