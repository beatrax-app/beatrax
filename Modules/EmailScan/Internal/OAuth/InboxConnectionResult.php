<?php

declare(strict_types=1);

namespace Modules\EmailScan\Internal\OAuth;

// A refused connection carries the line the reader is shown rather than an
// exception: every refusal here ends on the same settings screen, and the id
// is 0 on that path for the same reason the rest of this flow uses 0 — there
// is no inbox yet.
final readonly class InboxConnectionResult
{
    private function __construct(
        public int $inboxId,
        public ?string $failure,
    ) {}

    public static function connected(int $inboxId): self
    {
        return new self($inboxId, null);
    }

    public static function refused(string $failure): self
    {
        return new self(0, $failure);
    }
}
