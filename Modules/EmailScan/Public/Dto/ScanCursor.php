<?php

declare(strict_types=1);

namespace Modules\EmailScan\Public\Dto;

use InvalidArgumentException;
use Spatie\LaravelData\Data;

// Normalises the Gmail history cursor and Microsoft Graph delta-link
// behind one readonly value object, so callers treat a scan cursor as
// a single type without per-provider conditionals. Both factories
// validate their input to keep a malformed cursor off the DB.
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
            // v1 supports only the global Graph endpoint. Regional
            // clouds (graph.microsoft.de / .us / chinacloudapi.cn)
            // would relax this prefix check on the future-feature
            // boundary.
            throw new InvalidArgumentException(
                'ScanCursor::microsoft deltaLink must start with https://graph.microsoft.com/ '
                .'(regional Graph endpoints are not supported in v1).'
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
