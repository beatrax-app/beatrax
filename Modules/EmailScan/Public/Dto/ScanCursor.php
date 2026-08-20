<?php

declare(strict_types=1);

namespace Modules\EmailScan\Public\Dto;

use InvalidArgumentException;
use Modules\EmailScan\Public\Enums\MailProvider;
use Spatie\LaravelData\Data;

final class ScanCursor extends Data
{
    public function __construct(
        public readonly string $provider,
        public readonly ?string $historyId,
        public readonly ?string $deltaLink,
    ) {
        if ($provider !== MailProvider::Gmail->value && $provider !== MailProvider::Microsoft->value) {
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

        return new self(MailProvider::Gmail->value, $historyId, null);
    }

    public static function microsoft(string $deltaLink): self
    {
        if (! str_starts_with($deltaLink, 'https://graph.microsoft.com/')) {
            throw new InvalidArgumentException(
                'ScanCursor::microsoft deltaLink must start with https://graph.microsoft.com/ '
                .'(regional Graph endpoints are not supported in v1).'
            );
        }

        return new self(MailProvider::Microsoft->value, null, $deltaLink);
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
