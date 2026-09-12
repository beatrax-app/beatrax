<?php

declare(strict_types=1);

namespace Tests\Contracts\Support;

// HTML gives an attribute value two delimiters and no preference between them,
// and a template picks the one its value does not already contain: `x-data='{
// "a": 1 }'` is written that way BECAUSE the expression holds double quotes.
// A guard that searches for `x-data="` therefore stops covering the element
// exactly when the expression got complicated enough to be worth covering.
//
// It was silent here. One shipped component writes its Alpine expression in
// single quotes, and the rule on unbalanced Alpine attributes read straight
// past it: the defect was planted in that file and the suite stayed green.
/**
 * @link ../../../.docs/conventions/arch-invariants.md#a-scanner-accounts-for-the-whole-tree
 */
final class MarkupAttribute
{
    public const array DELIMITERS = ['"', "'"];

    /**
     * Whether $attributes carries `$name="$value"` in either delimiter. The
     * value is compared whole, so `role="tab"` never answers for `role="tablist"`.
     */
    public static function carries(string $attributes, string $name, string $value): bool
    {
        return self::valueOf($attributes, $name) === $value;
    }

    /**
     * Whether $attributes names $name at all, whatever its value and whether or
     * not it has one.
     */
    public static function present(string $attributes, string $name): bool
    {
        foreach (self::DELIMITERS as $delimiter) {
            if (str_contains($attributes, $name.'='.$delimiter)) {
                return true;
            }
        }

        return str_contains($attributes, $name);
    }

    /**
     * @return string|null the delivered value, or null when the attribute is
     *                     absent or never closes
     */
    public static function valueOf(string $attributes, string $name): ?string
    {
        foreach (self::DELIMITERS as $delimiter) {
            $opening = $name.'='.$delimiter;
            $at = strpos($attributes, $opening);

            if ($at === false) {
                continue;
            }

            // Rejected rather than read: `aria-controls=` also ends in
            // `controls=`, and answering for the longer attribute would let one
            // needle stand in for another.
            if ($at > 0 && (ctype_alnum($attributes[$at - 1]) || in_array($attributes[$at - 1], ['-', '_', ':', '.'], true))) {
                continue;
            }

            $from = $at + strlen($opening);
            $end = strpos($attributes, $delimiter, $from);

            if ($end === false) {
                continue;
            }

            return substr($attributes, $from, $end - $from);
        }

        return null;
    }

    /**
     * Both spellings of one attribute, for the call site that has to keep some
     * markup around the attribute — a tag name in front of it, say — and so
     * cannot hand the whole question over.
     *
     * @return list<string>
     */
    public static function spellings(string $name, string $value): array
    {
        return array_map(
            static fn (string $delimiter): string => $name.'='.$delimiter.$value.$delimiter,
            self::DELIMITERS,
        );
    }

    /** @return int how many times `$name="$value"` appears, in either delimiter */
    public static function countIn(string $source, string $name, string $value): int
    {
        $count = 0;

        foreach (self::DELIMITERS as $delimiter) {
            $count += substr_count($source, $name.'='.$delimiter.$value.$delimiter);
        }

        return $count;
    }
}
