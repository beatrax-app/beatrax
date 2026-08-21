<?php

declare(strict_types=1);

namespace Modules\EmailScan\Public\Dto;

use DateTimeImmutable;
use Spatie\LaravelData\Data;

final class InboxHealthLine extends Data
{
    public function __construct(
        public readonly string $provider,
        public readonly string $emailLocalPart,
        public readonly ?DateTimeImmutable $lastScanAt,
        public readonly string $status,
    ) {}
}
