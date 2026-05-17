<?php

declare(strict_types=1);

namespace Modules\EmailScan\Internal;

use DateTimeImmutable;
use Throwable;
use ZBateson\MailMimeParser\Header\AddressHeader;
use ZBateson\MailMimeParser\Header\DateHeader;
use ZBateson\MailMimeParser\MailMimeParser;

/**
 * Thin facade over zbateson/mail-mime-parser that pulls the four
 * header values the fetcher persists into inbox_messages at write
 * time: lowercase-normalised sender email, optional display name,
 * optional decoded subject, and the RFC 822 Date stamp.
 *
 * Single surface method, parseHeadersWithFallbackDate(), so the
 * caller MUST resolve the missing-Date-header fallback at the call
 * site. Production callers pass either the provider-stamped internal
 * date (Gmail `internalDate` / Graph `receivedDateTime`) OR an
 * explicit `$clock->now()->toDateTimeImmutable()` for paths where no
 * provider date is available. Routing the fallback through Clock at
 * the call site keeps test-frozen time honoured and the parser
 * deterministic — the class itself never reaches for `new
 * DateTimeImmutable('now')`.
 *
 * The sender_email is lowercased at parse time per the project's
 * normalisation rule (the Phase 6 receipts are stable on the
 * lowercase form; the `+plus` strip is explicitly out of scope for
 * this phase). The display name and subject are returned verbatim
 * after zbateson's RFC 2047 decode of any Q-encoded or B-encoded
 * encoded-word runs.
 *
 * Stateless and singleton-safe: the underlying MailMimeParser keeps
 * no per-call state, so a single instance can serve every fetcher
 * worker without contention.
 */
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
                // Malformed Date header — fall back to the provider-
                // supplied date. Production callers always pass a real
                // internal_date so the fallback never silently lands on
                // the wall clock.
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
