<?php

declare(strict_types=1);

namespace Modules\Desktop\Internal\Native;

use Illuminate\Contracts\Session\Session;
use Modules\Core\Public\Contracts\Clock;
use Modules\Core\Public\Services\SessionFactory;
use Modules\Desktop\Public\Contracts\RemembersPendingFileIntent;

// Session-scoped so a logged-out file-open survives until that same session
// authenticates, and is never inherited by a different user's session.
final readonly class PendingFileIntent implements RemembersPendingFileIntent
{
    public const string SESSION_KEY = 'desktop.pending_file_intent';

    private const array ALLOWED_EXTENSIONS = ['csv', 'eml'];

    public function __construct(
        private SessionFactory $session,
        private Clock $clock,
    ) {}

    public function remember(string $path, string $extension): void
    {
        if (! in_array($extension, self::ALLOWED_EXTENSIONS, true)) {
            return;
        }

        ($this->session)()->put(self::SESSION_KEY, [
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
        $raw = ($this->session)()->get(self::SESSION_KEY);
        if (! is_array($raw)) {
            return null;
        }

        $intent = $this->canonicalize($raw);
        if ($intent === null) {
            $this->clear();
        }

        return $intent;
    }

    /**
     * @param  array<array-key, mixed>  $raw
     * @return array{path: string, extension: string}|null
     */
    private function canonicalize(array $raw): ?array
    {
        $path = $raw['path'] ?? null;
        $extension = $raw['extension'] ?? null;
        if (! is_string($path) || ! is_string($extension) || ! in_array($extension, self::ALLOWED_EXTENSIONS, true)) {
            return null;
        }

        // A flash drive unmounted between the double-click and login makes
        // realpath() return false — discard the stale intent.
        $canonical = realpath($path);
        if ($canonical === false || ! is_file($canonical)) {
            return null;
        }

        return ['path' => $canonical, 'extension' => $extension];
    }

    public function clear(): void
    {
        ($this->session)()->forget(self::SESSION_KEY);
    }
}
