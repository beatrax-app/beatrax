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
 * recomputes the scrub-set from the current `oauth_secrets` table
 * state. Result: a rotated secret takes effect on the very next
 * write through the redaction pipeline (CONTEXT D-30).
 *
 * Constructor DI on the singleton scrub-set (Pattern A in
 * PATTERNS.md — `final readonly` class, constructor DI). The
 * observer dispatch contract from Eloquent's
 * `Illuminate\Database\Eloquent\Concerns\HasEvents::observe()` will
 * forward each named event ('saved', 'deleted', ...) to a
 * matching public method on this class; we provide exactly the two
 * events the threat model requires.
 *
 * NOTE: Eloquent fires `saved` after both `created` and `updated`
 * lifecycles, so a single `saved` handler covers both transitions
 * without registering two methods. The `deleted` event is the
 * separate rotation-out path the OAuthScrubSet::load() honours when
 * it picks up the now-missing row.
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
