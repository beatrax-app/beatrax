<?php

declare(strict_types=1);

namespace Modules\Notifications\Internal\Support;

use Closure;
use Illuminate\Contracts\Translation\Translator;
use Illuminate\Database\DatabaseManager;
use Modules\Core\Public\Enums\Locale;

// Renders a notification's copy in the RECIPIENT's language, not the request's:
// digests and reminders fire from jobs with no request locale, and the copy
// belongs to whoever the notification is for. The draft is built inside a scope
// where the translator speaks the user's stored locale, then it is restored.
/**
 * @link ../../../../.docs/features/notifications/architecture.md
 */
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
        $this->translator->setLocale($this->localeFor($userId));

        try {
            return $build();
        } finally {
            $this->translator->setLocale($previous);
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
