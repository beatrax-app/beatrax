<?php

declare(strict_types=1);

namespace Modules\Notifications\Internal\Support;

use Modules\Notifications\Internal\Enums\NotificationWriteOutcome;

final readonly class NotificationWriteResult
{
    private function __construct(
        public string $id,
        public NotificationWriteOutcome $outcome,
    ) {}

    public static function written(string $id): self
    {
        return new self($id, NotificationWriteOutcome::Written);
    }

    public static function duplicate(string $id): self
    {
        return new self($id, NotificationWriteOutcome::Duplicate);
    }

    // The id is still handed back on a deferral: it is derived from the draft
    // and holds whether or not the row lands, so a caller can ask later whether
    // the content it was denied has since arrived.
    public static function deferred(string $id): self
    {
        return new self($id, NotificationWriteOutcome::Deferred);
    }

    public function landed(): bool
    {
        return $this->outcome !== NotificationWriteOutcome::Deferred;
    }
}
