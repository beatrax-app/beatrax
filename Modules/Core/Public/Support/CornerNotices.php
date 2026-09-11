<?php

declare(strict_types=1);

namespace Modules\Core\Public\Support;

// The bottom-right corner is one surface with several occupants, and each of
// them used to pin itself there: `position: fixed` boxes counting their own gap
// from the same screen edge, stacked by nothing. The taller of two drew under
// the shorter and lost its question while keeping its buttons clickable.
/**
 * @link ../../../../.docs/conventions/invariants-from-shipped-failures.md#four-overlays-pinned-to-one-corner-stacked-by-nothing
 */
final class CornerNotices
{
    public const string REGION_ID = 'beatrax-corner-notices';

    public const string TELEPORT_TARGET = '#'.self::REGION_ID;

    // Whether a `class` attribute anchors its element to the viewport's
    // bottom-right corner. A full-width bottom bar (`inset-x-0`) and a centred
    // one (`left-1/2`) reach neither edge this asks about: other surfaces,
    // which the region has nothing to say about.
    public static function pinsToTheCorner(?string $classAttribute): bool
    {
        $fixed = false;
        $bottom = false;
        $right = false;

        foreach (self::classes($classAttribute ?? '') as $class) {
            $utility = self::utility($class);

            $fixed = $fixed || $utility === 'fixed';
            $bottom = $bottom || str_starts_with($utility, 'bottom-');
            $right = $right || str_starts_with($utility, 'right-');
        }

        return $fixed && $bottom && $right;
    }

    /**
     * @return list<string>
     */
    private static function classes(string $attribute): array
    {
        $flat = str_replace(["\r", "\n", "\t"], ' ', $attribute);

        return array_values(array_filter(explode(' ', $flat), static fn (string $token): bool => $token !== ''));
    }

    // A breakpoint in front of an offset does not make it a different offset:
    // `md:bottom-4` pins on every screen from md up, and a negative offset is
    // the same utility with its sign in front.
    private static function utility(string $class): string
    {
        $colon = strrpos($class, ':');

        return ltrim($colon === false ? $class : substr($class, $colon + 1), '-');
    }
}
