<?php

declare(strict_types=1);

namespace Modules\Notifications\Internal\Support;

use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Contracts\Translation\Translator;
use Illuminate\Database\DatabaseManager;
use Modules\Core\Public\Enums\Locale;

// Renders copy in the RECIPIENT's language, not the request's: digests and
// reminders fire from jobs that have no request locale at all.
final class NotificationCopyRenderer
{
    public function __construct(
        private readonly DatabaseManager $db,
        private readonly Translator $translator,
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
        $this->translator->setLocale($locale);
        // Carbon carries its own locale, so it has to be scoped too or a
        // job-built notification's dates won't match its language.
        CarbonImmutable::setLocale($locale);

        try {
            return $build();
        } finally {
            $this->translator->setLocale($previous);
            CarbonImmutable::setLocale($previous);
        }
    }

    private function localeFor(int $userId): string
    {
        $stored = $this->db->connection()
            ->table('users')
            ->where('id', $userId)
            ->value('locale');

        return is_string($stored) && $stored !== '' ? $stored : Locale::DEFAULT;
    }
}
