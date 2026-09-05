<?php

declare(strict_types=1);

namespace Modules\Desktop\Internal\Listeners;

use Modules\Desktop\Internal\Native\PendingFileIntent;

final readonly class ContinuePendingFileIntentAfterLogin
{
    public function __construct(
        private PendingFileIntent $intent,
    ) {}

    public function handle(): void
    {
        // Read for both of pending()'s effects. It claims the hand-off the
        // shell left, which is the whole delivery for a document double-clicked
        // before any window existed; and its realpath()/is_file() drops an
        // intent whose file went with an unmounted drive.
        $this->intent->pending();
    }
}
