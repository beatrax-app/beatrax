<?php

declare(strict_types=1);

namespace Modules\DevMode\Internal\Listeners;

use Modules\DevMode\Internal\Services\OAuthScrubSet;

// Eloquent observer attached to OAuthSecret in
// DevModeServiceProvider::boot(); busts the scrub set on every
// saved/deleted event so a rotated secret takes effect on the very next
// redaction-pipeline write, no app restart required.
final readonly class BustOAuthScrubSetOnSecretChange
{
    public function __construct(
        private OAuthScrubSet $scrubSet,
    ) {}

    // The changed OAuthSecret model Eloquent passes to the observer hook
    // is not read — the scrub set rebuilds itself lazily from the live
    // table on its next compiledPattern() call, so busting is enough.
    public function saved(): void
    {
        $this->scrubSet->bust();
    }

    public function deleted(): void
    {
        $this->scrubSet->bust();
    }
}
