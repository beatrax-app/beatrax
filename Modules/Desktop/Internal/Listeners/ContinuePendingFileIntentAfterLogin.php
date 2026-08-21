<?php

declare(strict_types=1);

namespace Modules\Desktop\Internal\Listeners;

use Modules\Desktop\Internal\Native\PendingFileIntent;

final class ContinuePendingFileIntentAfterLogin
{
    public function __construct(
        private readonly PendingFileIntent $intent,
    ) {}

    public function handle(): void
    {
        // Read purely for pending()'s realpath()/is_file() side effect, so the next
        // /desktop/file-staging navigation never finds a vanished file.
        $this->intent->pending();
    }
}
