<?php

declare(strict_types=1);

namespace Modules\Receipts\Database\Seeders\Demo;

// Descriptor for one demo file_imports row. Groups the per-receipt
// fields the seeder would otherwise pass positionally; the on-disk
// source_kind is derived from the filename extension rather than
// carried separately, keeping the two always in step.
final readonly class DemoFileImportSpec
{
    public function __construct(
        public string $providerMessageId,
        public string $sourceFilename,
        public string $senderEmail,
        public string $senderName,
        public string $subject,
        public string $matcherKey,
        public int $ageHours,
    ) {}

    public function sourceKind(): string
    {
        return strtolower(pathinfo($this->sourceFilename, PATHINFO_EXTENSION));
    }
}
