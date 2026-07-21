<?php

declare(strict_types=1);

namespace Modules\Receipts\Public\Dto;

use DateTimeImmutable;
use Spatie\LaravelData\Data;

final class FileImportDto extends Data
{
    public function __construct(
        public readonly int $id,
        public readonly int $userId,
        public readonly string $sourceKind,
        public readonly string $sourceFilename,
        public readonly string $providerMessageId,
        public readonly DateTimeImmutable $internalDate,
        public readonly string $senderEmail,
        public readonly ?string $senderName,
        public readonly ?string $subject,
        public readonly string $emlPath,
        public readonly string $status,
        public readonly ?string $matcherKey,
        public readonly DateTimeImmutable $fetchedAt,
    ) {}
}
