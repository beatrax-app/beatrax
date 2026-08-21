<?php

declare(strict_types=1);

namespace Modules\Notifications\Internal\Support;

use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Contracts\Translation\Translator;
use Illuminate\Database\DatabaseManager;
use Modules\Core\Public\Services\LocaleNegotiator;

// Renders copy in the RECIPIENT's language, not the request's: digests and
// reminders fire from jobs that have no request locale at all.
final class NotificationCopyRenderer
{
    public function __construct(
        private readonly DatabaseManager $db,
        private readonly Translator $translator,
        private readonly LocaleNegotiator $negotiator,
    ) {}

    /**
     * @template T
     *
     * @param  Closure(): T  $build
     * @return T
     */
    public function forUser(int $userId, Closure $build): mixed
    {
        $previous = $this->translator->getLocale();
        $locale = $this->localeFor($userId);
        // The translator alone, so a job never leaves config('app.locale')
        // pointing at one recipient for whatever runs next. Nothing relays that
        // narrower swap to Carbon, which carries its own locale, so its dates
        // are moved and put back by hand alongside.
        $this->translator->setLocale($locale);
        CarbonImmutable::setLocale($locale);

        try {
            return $build();
        } finally {
            $this->translator->setLocale($previous);
            CarbonImmutable::setLocale($previous);
        }
    }

    // Through the negotiator, so a stored code this release no longer ships
    // reads the same here as everywhere else. Handed straight to Carbon it is a
    // no-op that leaves the previous reader's language on the dates while the
    // sentence around them falls back to English.
    private function localeFor(int $userId): string
    {
        $stored = $this->db->connection()
            ->table('users')
            ->where('id', $userId)
            ->value('locale');

        return $this->negotiator->resolve(is_string($stored) ? $stored : null, null, null);
    }
}
