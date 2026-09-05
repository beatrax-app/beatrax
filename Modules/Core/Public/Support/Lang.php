<?php

declare(strict_types=1);

namespace Modules\Core\Public\Support;

use Illuminate\Container\Container;
use Illuminate\Contracts\Translation\Translator;
use Illuminate\Translation\MessageSelector;
use LogicException;

// The blade-facing translation seam: views @use this class and call
// Lang::get('ns::group.key') instead of the banned __()/@lang globals
// (the same reason URLs route through a @use'd class, not route()). The
// translator is resolved from the container the way bootstrap/app.php does.
final class Lang
{
    // How many numbers the table arms() ships addresses one by one. Every rule
    // in MessageSelector compares the number itself only against constants
    // below twelve and otherwise reads it modulo ten and modulo a hundred, so
    // the index for any n at or above the span is the one held for n % span.
    private const int ARMS_TABLE_SPAN = 100;

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

    // The locale the reader is being answered in. A view that has to compare
    // it against some OTHER language — a corpus paragraph written in the
    // provider's — asks here rather than resolving the translator itself.
    public static function locale(): string
    {
        return Container::getInstance()->make(Translator::class)->getLocale();
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

    // choice() cannot run for a number the server does not have: a count Alpine
    // works out in the browser arrives after the response has left. This hands
    // the browser the arms and the reader locale's own index table instead, so
    // the selection stays the language's rather than a JavaScript n === 1.
    /**
     * @return array{span: int, index: list<int>, forms: array<string, list<string>>}
     */
    public static function arms(string ...$keys): array
    {
        $translator = Container::getInstance()->make(Translator::class);

        $forms = [];
        foreach ($keys as $key) {
            $line = $translator->get($key);
            $forms[$key] = is_string($line) ? self::segments($key, $line) : [$key];
        }

        return [
            'span' => self::ARMS_TABLE_SPAN,
            'index' => self::pluralIndexTable($translator->getLocale()),
            'forms' => $forms,
        ];
    }

    /** @return list<string> */
    private static function segments(string $key, string $line): array
    {
        $segments = explode('|', $line);

        foreach ($segments as $segment) {
            // MessageSelector matches a {0} or [2,*] range against the number
            // before the rule table is consulted at all, and a range can name a
            // number past the end of the table this ships. Nothing on the tree
            // does it; a translator who starts is told here, not by a wrong arm.
            if (preg_match('/^\s*[\{\[]/', $segment) === 1) {
                throw new LogicException('a line read in the browser cannot carry an explicit range: '.$key);
            }
        }

        return $segments;
    }

    /** @return list<int> */
    private static function pluralIndexTable(string $locale): array
    {
        $selector = new MessageSelector;
        $table = [];

        foreach (range(0, self::ARMS_TABLE_SPAN * 2 - 1) as $number) {
            $table[] = $selector->getPluralIndex($locale, $number);
        }

        return $table;
    }
}
