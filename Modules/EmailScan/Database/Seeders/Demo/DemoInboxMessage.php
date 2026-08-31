<?php

declare(strict_types=1);

namespace Modules\EmailScan\Database\Seeders\Demo;

// The five facts that distinguish one seeded message from the next, carried
// together so the seeder's writer names the reader, the inbox and the clock
// once each rather than restating all eight per row.
final readonly class DemoInboxMessage
{
    public function __construct(
        public string $providerMessageId,
        public string $senderEmail,
        public string $senderName,
        public string $subject,
        public int $ageHours,
    ) {}
}
