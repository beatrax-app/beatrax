<?php

declare(strict_types=1);

namespace Modules\Mobile\Internal\Http;

use Illuminate\Container\Container;
use Illuminate\Contracts\Routing\UrlGenerator;

// The pairing screen reads `?mode=import` to decide whether the ceremony it is
// running belongs to an import, and six surfaces send a device there carrying
// it. Spelled once here so the reader and the senders cannot drift apart: a
// sender that stops matching loses the import without saying anything.
final class PairingEntryUrl
{
    private const string ROUTE = 'mobile.pair';

    public const string MODE_PARAM = 'mode';

    public const string MODE_IMPORT = 'import';

    public static function importingFrom(UrlGenerator $urls): string
    {
        return $urls->route(self::ROUTE, [self::MODE_PARAM => self::MODE_IMPORT]);
    }

    public static function bareFrom(UrlGenerator $urls): string
    {
        return $urls->route(self::ROUTE);
    }

    // For Blade, which cannot inject the generator. Code that can injects it
    // and calls importingFrom().
    public static function importing(): string
    {
        return self::importingFrom(Container::getInstance()->make(UrlGenerator::class));
    }
}
