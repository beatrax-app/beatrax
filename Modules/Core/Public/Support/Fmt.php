<?php

declare(strict_types=1);

namespace Modules\Core\Public\Support;

use Illuminate\Container\Container;
use Illuminate\Contracts\Translation\Translator;
use Illuminate\Support\Number;
use IntlException;
use Modules\Core\Public\Enums\Locale;
use ValueError;

// The blade-facing number-formatting seam, the numeric counterpart to Lang:
// views @use this class and call Fmt::number(...) so a count or percentage
// picks up the active locale's grouping and decimal marks (en 1,234.5 vs
// nl 1.234,5). Currency stays with Money, which formats by currency instead.
final class Fmt
{
    public static function number(int|float $value, int $decimals = 0): string
    {
        $locale = Container::getInstance()->make(Translator::class)->getLocale();

        // The mobile build's ext-intl carries English-only ICU data, so
        // NumberFormatter cannot be constructed for any other language — it
        // reports that as IntlException with error-exceptions on, and as
        // ValueError from the constructor otherwise.
        try {
            $formatted = Number::format($value, precision: $decimals, locale: $locale);
        } catch (IntlException|ValueError) {
            $formatted = false;
        }

        if ($formatted !== false) {
            return $formatted;
        }

        $marks = Locale::tryFrom($locale) ?? Locale::En;

        return number_format($value, $decimals, $marks->decimalMark(), $marks->groupMark());
    }
}
