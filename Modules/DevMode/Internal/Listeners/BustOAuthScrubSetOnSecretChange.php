<?php

declare(strict_types=1);

namespace Modules\DevMode\Internal\Listeners;

use Modules\DevMode\Internal\Services\OAuthScrubSet;
use Modules\EmailScan\Models\OAuthSecret;

/**
 * Eloquent observer attached to {@see OAuthSecret} in
 * `DevModeServiceProvider::boot()` via
 * `OAuthSecret::observe(BustOAuthScrubSetOnSecretChange::class)`.
 *
 * On every `saved` (created OR updated) and `deleted` event, calls
 * {@see OAuthScrubSet::bust()} so the next log line / audit row
 * recomputes the scrub-set from the current oauth_secrets table
 * state. A rotated secret therefore takes effect on the very next
 * write through the redaction pipeline — no app restart required.
 *
 * Eloquent fires `saved` after both `created` and `updated`
 * lifecycles, so a single `saved` handler covers both transitions.
 * The `deleted` event is the separate rotation-out path that lets
 * OAuthScrubSet::load() drop the now-missing row from the set.
 */
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
