<?php

declare(strict_types=1);

namespace Modules\DevMode\Internal\Listeners;

use Modules\DevMode\Internal\Services\OAuthScrubSet;

// Observes OAuthSecret so a rotated secret is redacted from the very next log
// write rather than after a restart.
final readonly class BustOAuthScrubSetOnSecretChange
{
    public function __construct(
        private OAuthScrubSet $scrubSet,
    ) {}

    public function saved(): void
    {
        $this->scrubSet->bust();
    }

    public function deleted(): void
    {
        $this->scrubSet->bust();
    }
}
