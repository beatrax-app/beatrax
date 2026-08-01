<?php

declare(strict_types=1);

namespace Modules\Core\Public\Support;

use Illuminate\Container\Container;
use Illuminate\Contracts\Translation\Translator;

// The blade-facing translation seam: views @use this class and call
// Lang::get('ns::group.key') instead of the banned __()/@lang globals
// (the same reason URLs route through a @use'd class, not route()). The
// translator is resolved from the container the way bootstrap/app.php does.
final class Lang
{
    /**
     * @param  array<string, string|int|float>  $replace
     */
    public static function get(string $key, array $replace = []): string
    {
        $line = Container::getInstance()->make(Translator::class)->get($key, $replace);

        // A missing key returns the key itself (or, for a group file, an
        // array); collapse anything non-scalar to the key so a template
        // never renders "Array" and a typo stays visible in the UI.
        return is_string($line) ? $line : $key;
    }
}
