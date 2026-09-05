<?php

declare(strict_types=1);

namespace Modules\Desktop\Internal\Native;

use Modules\Core\Public\Services\SessionFactory;

// Session-scoped so a logged-out file-open survives until that same session
// authenticates, and is never inherited by a different user's session. Only the
// reading half lives here: FileOpenHandoff takes the write, because the shell's
// event route has no session for it to start in.
final readonly class PendingFileIntent
{
    public const string SESSION_KEY = 'desktop.pending_file_intent';

    public function __construct(
        private SessionFactory $session,
        private ShellHandoff $handoff,
    ) {}

    /**
     * @return array{path: string, extension: string}|null
     */
    public function pending(): ?array
    {
        $raw = $this->claimed() ?? ($this->session)()->get(self::SESSION_KEY);
        if (! is_array($raw)) {
            return null;
        }

        $intent = $this->canonicalize($raw);
        if ($intent === null) {
            $this->clear();
        }

        return $intent;
    }

    // The one hop the shell cannot make itself. A double-click that launched
    // the app raises OpenFile before any window exists, so nothing in the page
    // could have heard it; this is where that fact becomes session state.
    /**
     * @return array<array-key, mixed>|null
     */
    private function claimed(): ?array
    {
        $left = $this->handoff->take(ShellHandoff::FILE_INTENT);

        if ($left !== null) {
            ($this->session)()->put(self::SESSION_KEY, $left);
        }

        return $left;
    }

    /**
     * @param  array<array-key, mixed>  $raw
     * @return array{path: string, extension: string}|null
     */
    private function canonicalize(array $raw): ?array
    {
        $path = $raw['path'] ?? null;
        $extension = $raw['extension'] ?? null;
        if (! is_string($path) || ! is_string($extension) || ! in_array($extension, FileOpenIntake::SUPPORTED_EXTENSIONS, true)) {
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
