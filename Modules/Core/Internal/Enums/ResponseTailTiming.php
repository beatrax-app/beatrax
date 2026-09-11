<?php

declare(strict_types=1);

namespace Modules\Core\Internal\Enums;

use Modules\Core\Public\Services\UserDataPathService;

// Where a terminable middleware's work actually falls relative to the bytes the
// reader is waiting for. Measured, because the two runtimes disagree and the
// abstraction that defers the work reads the same in both.
/**
 * @link ../../../../.docs/features/mobile/a-tail-the-reader-waits-for.md
 */
enum ResponseTailTiming
{
    // public/index.php: Application::handleRequest sends the response and then
    // terminates. Nothing is holding a socket open for this work.
    case AfterTheResponse;

    // Native\Mobile\Runtime::dispatch terminates and only then returns the
    // response, which the shell bridge buffers whole before handing it to the
    // WebView. Every millisecond here is a millisecond of blank screen.
    case InsideTheReadersWait;

    public static function ofThisRuntime(): self
    {
        return UserDataPathService::isMobileRuntime()
            ? self::InsideTheReadersWait
            : self::AfterTheResponse;
    }

    // Not the same quantity twice. One is measured against the page it is added
    // to on the device; the other is a ceiling on how long a request may hold a
    // process nobody is waiting on, which no measured tail comes near.
    public function milliseconds(): int
    {
        return match ($this) {
            self::AfterTheResponse => 2_000,
            self::InsideTheReadersWait => 100,
        };
    }
}
