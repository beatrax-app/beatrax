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

    // The whole group as a flat key => line map, for a caller that has to ask
    // which keys a group HOLDS rather than what one of them says. A group with
    // no file behind it comes back from the translator as the key string, which
    // is not a map and is reported here as the empty one.
    /**
     * @return array<string, string>
     */
    public static function group(string $key): array
    {
        $lines = Container::getInstance()->make(Translator::class)->get($key);
        if (! is_array($lines)) {
            return [];
        }

        $map = [];
        foreach ($lines as $name => $line) {
            if (is_string($line)) {
                $map[(string) $name] = $line;
            }
        }

        return $map;
    }

    // Picks the plural form for $number, applying the active locale's rule. The
    // count is filled here because the translator fills it with the raw integer:
    // an import of 1200 rows read "1200 transacties" to a reader whose money on
    // the next card read "5.701,66". Selection still runs on $number.
    /**
     * @param  array<string, string|int|float>  $replace
     */
    public static function choice(string $key, int $number, array $replace = []): string
    {
        $replace['count'] = Fmt::number($number);

        return Container::getInstance()->make(Translator::class)->choice($key, $number, $replace);
    }
}
