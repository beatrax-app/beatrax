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
        // Read purely for pending()'s realpath()/is_file() side effect: an
        // intent whose file went with an unmounted drive is dropped here, so
        // ContinueToStagedFile does not send the reader to a staging screen
        // with nothing on it.
        $this->intent->pending();
    }
}
