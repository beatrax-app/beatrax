<?php

declare(strict_types=1);

namespace Modules\EmailScan\Public\Dto;

use InvalidArgumentException;
use Spatie\LaravelData\Data;

/**
 * Normalises the Gmail history cursor and Microsoft Graph delta-link
 * behind a single readonly value object.
 *
 * The two providers report "where the scan stopped" with different
 * primitives: Gmail returns a numeric `historyId`; Graph returns a
 * full URL that itself encodes the cursor. This DTO carries both
 * fields with provider-discriminated factories so the rest of the
 * module can treat a scan cursor as one type without per-provider
 * conditionals threading through every call site.
 *
 * `emptyFor()` constructs a cursor with both fields nullable for the
 * provider — the InboxScanStateMachine treats an empty cursor as a
 * no-op when persisting (Phase 6 never explicitly clears a cursor,
 * but the affordance exists for tests + a future explicit-reset
 * action).
 *
 * Validation: gmail() rejects an empty historyId; microsoft() rejects
 * a delta-link URL that does not start with the Graph base. Both
 * factories reject an unknown provider value via the private
 * constructor's guard. These rejections keep malformed cursors from
 * ever reaching the DB layer where the only diagnosis would be a
 * silent broken resume.
 */
final class ScanCursor extends Data
{
    public function __construct(
        public readonly string $provider,
        public readonly ?string $historyId,
        public readonly ?string $deltaLink,
    ) {
        if ($provider !== 'gmail' && $provider !== 'microsoft') {
            throw new InvalidArgumentException(
                "ScanCursor provider must be 'gmail' or 'microsoft', got '{$provider}'."
            );
        }
    }

    public static function gmail(string $historyId): self
    {
        if ($historyId === '') {
            throw new InvalidArgumentException(
                'ScanCursor::gmail historyId must not be empty.'
            );
        }

        return new self('gmail', $historyId, null);
    }

    public static function microsoft(string $deltaLink): self
    {
        if (! str_starts_with($deltaLink, 'https://graph.microsoft.com/')) {
            throw new InvalidArgumentException(
                'ScanCursor::microsoft deltaLink must start with https://graph.microsoft.com/.'
            );
        }

        return new self('microsoft', null, $deltaLink);
    }

    public static function emptyFor(string $provider): self
    {
        return new self($provider, null, null);
    }

    public function isEmpty(): bool
    {
        return $this->historyId === null && $this->deltaLink === null;
    }
}
