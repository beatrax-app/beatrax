<?php

declare(strict_types=1);

namespace Modules\Core\Internal\Listeners;

use Illuminate\Console\Events\CommandStarting;
use Modules\Core\Internal\Build\BuiltFrontEnd;
use Modules\Core\Internal\Build\StaleFrontEndException;

// No build hook can carry this refusal. nativephp/desktop prints "Command
// failed" for a prebuild command that exits non-zero and packages anyway,
// native:run runs no prebuild at all, and nativephp/mobile has no hook array
// to add one to. This fires ahead of every one of them, in both roots.
final readonly class RefuseToShipAStaleFrontEnd
{
    // mobile:package-android is named beside native:package rather than
    // covered by it: it reaches that command through $this->call(), for which
    // CommandStarting never fires.
    public const array SHIPS_THE_BUILT_FRONT_END = [
        'mobile:package-android',
        'native:build',
        'native:package',
        'native:run',
    ];

    public function __construct(private BuiltFrontEnd $frontEnd) {}

    public function handle(CommandStarting $event): void
    {
        if (! in_array($event->command, self::SHIPS_THE_BUILT_FRONT_END, true)) {
            return;
        }

        $stale = $this->frontEnd->staleness();

        if ($stale === null) {
            return;
        }

        throw new StaleFrontEndException($stale->sentence($event->command));
    }
}
