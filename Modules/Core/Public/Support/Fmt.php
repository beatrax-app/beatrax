<?php

declare(strict_types=1);

namespace Modules\Core\Public\Support;

use Illuminate\Container\Container;
use Illuminate\Contracts\Translation\Translator;
use Illuminate\Support\Number;

// The blade-facing number-formatting seam, the numeric counterpart to Lang:
// views @use this class and call Fmt::number(...) so a count or percentage
// picks up the active locale's grouping and decimal marks (en 1,234.5 vs
// nl 1.234,5). Currency stays with Money, which formats by currency instead.
final class Fmt
{
    public static function number(int|float $value, int $decimals = 0): string
    {
        $locale = Container::getInstance()->make(Translator::class)->getLocale();

        $formatted = Number::format($value, precision: $decimals, locale: $locale);

        return $formatted === false ? (string) $value : $formatted;
    }
}
