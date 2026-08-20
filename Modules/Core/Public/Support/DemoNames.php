<?php

declare(strict_types=1);

namespace Modules\Core\Public\Support;

use Illuminate\Container\Container;
use Illuminate\Contracts\Translation\Translator;
use Modules\Core\Public\Enums\Locale;

// Demo rows are deduplicated by name, and those names are translated. Seeding
// once, changing the locale and seeding again produced a second copy of every
// goal, pot and saved report. Matching every locale's rendering keeps a
// re-seed idempotent whichever language either run used.
final class DemoNames
{
    /** @var array<string, list<string>> */
    private static array $cache = [];

    /**
     * @return list<string> every locale's rendering of a `core::demo.*` key
     */
    public static function everyRendering(string $key): array
    {
        if (isset(self::$cache[$key])) {
            return self::$cache[$key];
        }

        $line = 'core::demo.'.$key;
        $translator = Container::getInstance()->make(Translator::class);

        $names = [];

        foreach (Locale::cases() as $locale) {
            $name = $translator->get($line, [], $locale->value);

            // A missing key comes back as the key itself, which names nothing
            // and would match no row.
            if (is_string($name) && $name !== '' && $name !== $line) {
                $names[$name] = true;
            }
        }

        return self::$cache[$key] = array_keys($names);
    }
}
