<?php

declare(strict_types=1);

namespace Modules\Desktop\Internal\Boot;

// Joins the reader to the rules. Kept apart from both so the command has one
// call and the review stays a pure function of what was read.
final readonly class MacBundleInspection
{
    public function __construct(
        private MacBundleReader $reader,
        private MacBundleReview $review,
    ) {}

    /**
     * @param  list<string>  $alsoLaunched  relative paths this app spawns that structure alone cannot reveal
     * @return list<string>
     */
    public function refusals(string $bundlePath, array $alsoLaunched = []): array
    {
        $children = [];

        foreach ($this->reader->executablesIn($bundlePath) as $relative) {
            if ($relative === $this->mainExecutableOf($bundlePath)) {
                continue;
            }

            $children[$relative] = [
                'entitlements' => $this->reader->entitlementsOf($bundlePath.'/'.$relative),
                'signed' => $this->reader->isSigned($bundlePath.'/'.$relative),
                'inMacOsDirectory' => $this->reader->isInAMacOsDirectory($relative),
                'launched' => $this->isLaunched($relative, $alsoLaunched),
            ];
        }

        return $this->review->refusals($this->reader->entitlementsOf($bundlePath), $children);
    }

    // An Electron helper under Frameworks is spawned by the shell, which is
    // structural and true of any Electron bundle. Anything else the app
    // launches — the interpreter, for this product — only the caller knows,
    // because a bundled executable and a spawned one look identical on disk.
    /** @param list<string> $alsoLaunched */
    private function isLaunched(string $relative, array $alsoLaunched): bool
    {
        if (in_array($relative, $alsoLaunched, true)) {
            return true;
        }

        return str_starts_with($relative, 'Contents/Frameworks/')
            && str_contains($relative, '.app/Contents/MacOS/');
    }

    public function executableCount(string $bundlePath): int
    {
        return count($this->reader->executablesIn($bundlePath));
    }

    // The app's own binary is judged as the app, not as a child: it is the
    // one executable that carries the full entitlement set rather than
    // inheriting one, so reading it twice would report every app right as a
    // child's violation.
    private function mainExecutableOf(string $bundlePath): string
    {
        return 'Contents/MacOS/'.basename($bundlePath, '.app');
    }
}
