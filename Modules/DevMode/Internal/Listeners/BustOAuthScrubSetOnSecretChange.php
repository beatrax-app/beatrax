<?php

declare(strict_types=1);

namespace Modules\DevMode\Internal\Listeners;

use Modules\DevMode\Internal\Services\OAuthScrubSet;
use Modules\EmailScan\Models\OAuthSecret;

// Eloquent observer attached to OAuthSecret in
// DevModeServiceProvider::boot(); busts the scrub set on every
// saved/deleted event so a rotated secret takes effect on the very next
// redaction-pipeline write, no app restart required.
final readonly class BustOAuthScrubSetOnSecretChange
{
    public function __construct(
        private OAuthScrubSet $scrubSet,
    ) {}

    public function saved(OAuthSecret $secret): void
    {
        $this->scrubSet->bust();
    }

    public function deleted(OAuthSecret $secret): void
    {
        $this->scrubSet->bust();
    }
}
