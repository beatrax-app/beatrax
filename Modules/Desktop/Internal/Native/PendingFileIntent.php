<?php

declare(strict_types=1);

namespace Modules\Desktop\Internal\Native;

use Illuminate\Contracts\Session\Session;
use Modules\Core\Public\Contracts\Clock;
use Modules\Desktop\Public\Contracts\RemembersPendingFileIntent;

// Session-scoped store for a pending OS file-open intent: a logged-out
// file-open is saved here until the user authenticates, then
// ContinuePendingFileIntentAfterLogin routes them to the staging page
// bound to that file — never inherited across a different session.
final class PendingFileIntent implements RemembersPendingFileIntent
{
    public const SESSION_KEY = 'desktop.pending_file_intent';

    private const ALLOWED_EXTENSIONS = ['csv', 'eml'];

    public function __construct(
        private readonly Session $session,
        private readonly Clock $clock,
    ) {}

    public function remember(string $path, string $extension): void
    {
        if (! in_array($extension, self::ALLOWED_EXTENSIONS, true)) {
            return;
        }

        $this->session->put(self::SESSION_KEY, [
            'path' => $path,
            'extension' => $extension,
            'remembered_at' => $this->clock->now()->getTimestamp(),
        ]);
    }

    /**
     * @return array{path: string, extension: string}|null
     */
    public function pending(): ?array
    {
        $raw = $this->session->get(self::SESSION_KEY);
        if (! is_array($raw)) {
            return null;
        }
        $path = $raw['path'] ?? null;
        $extension = $raw['extension'] ?? null;
        if (! is_string($path) || ! is_string($extension)) {
            $this->clear();

            return null;
        }
        if (! in_array($extension, self::ALLOWED_EXTENSIONS, true)) {
            $this->clear();

            return null;
        }
        // A flash drive unmounted between the double-click and login
        // makes realpath() return false — discard the stale intent.
        $canonical = realpath($path);
        if ($canonical === false || ! is_file($canonical)) {
            $this->clear();

            return null;
        }

        return ['path' => $canonical, 'extension' => $extension];
    }

    public function clear(): void
    {
        $this->session->forget(self::SESSION_KEY);
    }
}
