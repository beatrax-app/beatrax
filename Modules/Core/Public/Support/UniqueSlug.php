<?php

declare(strict_types=1);

namespace Modules\Core\Public\Support;

use Closure;
use Illuminate\Support\Str;

final class UniqueSlug
{
    /**
     * @param  Closure(string): bool  $isFree
     */
    public static function walk(string $base, Closure $isFree): string
    {
        if ($isFree($base)) {
            return $base;
        }

        $suffix = 2;
        while (! $isFree($base.'-'.$suffix)) {
            $suffix++;
        }

        return $base.'-'.$suffix;
    }

    // A name of nothing but emoji or punctuation slugs to the empty string,
    // which no unique index can carry, so the caller names the literal that
    // stands in for it. Only the fallback is a parameter: a table whose slugs
    // are already stored cannot change transliterator without re-slugging.
    public static function slugify(string $name, string $fallback): string
    {
        $slug = Str::slug($name);

        return $slug === '' ? $fallback : $slug;
    }
}
