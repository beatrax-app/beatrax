<?php

declare(strict_types=1);

namespace Modules\EmailScan\Internal;

use DateTimeImmutable;
use Throwable;
use ZBateson\MailMimeParser\Header\AddressHeader;
use ZBateson\MailMimeParser\Header\DateHeader;
use ZBateson\MailMimeParser\MailMimeParser;

final class MimeHeaderParser
{
    public function parseHeadersWithFallbackDate(
        string $rawEml,
        DateTimeImmutable $fallbackDate,
    ): ParsedMessageHeaders {
        $parser = new MailMimeParser;
        $message = $parser->parse($rawEml, true);

        $senderEmail = '';
        $senderName = null;

        $fromHeader = $message->getHeader('From');
        if ($fromHeader instanceof AddressHeader) {
            $addresses = $fromHeader->getAddresses();
            $first = $addresses[0] ?? null;
            if ($first !== null) {
                $senderEmail = strtolower($first->getEmail());
                $name = $first->getName();
                if ($name !== '') {
                    $senderName = $name;
                }
            }
        }

        $subjectRaw = $message->getHeaderValue('Subject');
        $subject = (is_string($subjectRaw) && $subjectRaw !== '')
            ? $subjectRaw
            : null;

        $internalDate = $fallbackDate;
        $dateHeader = $message->getHeader('Date');
        if ($dateHeader instanceof DateHeader) {
            try {
                $immutable = $dateHeader->getDateTimeImmutable();
                if ($immutable !== null) {
                    $internalDate = $immutable;
                }
            } catch (Throwable) {
                // Production callers always pass a real internal_date, so
                // this fallback never silently lands on the wall clock.
                $internalDate = $fallbackDate;
            }
        }

        return new ParsedMessageHeaders(
            senderEmail: $senderEmail,
            senderName: $senderName,
            subject: $subject,
            internalDate: $internalDate,
        );
    }
}
