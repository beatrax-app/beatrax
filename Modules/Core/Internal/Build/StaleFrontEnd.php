<?php

declare(strict_types=1);

namespace Modules\Core\Internal\Build;

final readonly class StaleFrontEnd
{
    private const string NO_BUILD_AT_ALL = 'nothing — public/build holds no files';

    public function __construct(
        public string $source,
        public int $sourceModified,
        public ?string $built,
        public ?int $builtModified,
    ) {}

    public function sentence(string $command): string
    {
        $source = self::stamped($this->source, $this->sourceModified);
        $built = $this->built === null || $this->builtModified === null
            ? self::NO_BUILD_AT_ALL
            : self::stamped($this->built, $this->builtModified);

        return sprintf(
            "The front end under public/build is older than the sources it is compiled from, so\n"
            ."  %s would ship a script and a stylesheet that predate this checkout.\n\n"
            ."    newest source      %s\n"
            ."    newest built file  %s\n\n"
            ."  Nothing between here and a device runs Vite: both shells bundle public/build exactly\n"
            ."  as they find it on disk. The manifest still resolves and every view still renders, so\n"
            ."  the older bundle reaches the device in silence — a component nobody has registered\n"
            ."  there yet, a utility class nobody has compiled there yet.\n\n"
            .'  Run `npm run build`, then %s again.',
            $command,
            $source,
            $built,
            $command,
        );
    }

    private static function stamped(string $path, int $modified): string
    {
        return str_pad($path, 44).' '.date('Y-m-d H:i', $modified);
    }
}
