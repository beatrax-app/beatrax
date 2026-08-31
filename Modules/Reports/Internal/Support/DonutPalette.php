<?php

declare(strict_types=1);

namespace Modules\Reports\Internal\Support;

// Ten colours cycled with a modulo, so a fifteen-category ring drew slices 11
// to 15 in the same greys as slices 1 to 5 and the legend could not tell them
// apart. Past the brand set the wheel is split into as many hues as there are
// slices, alternating lightness so neighbours differ twice over.
final class DonutPalette
{
    /** @var list<string> */
    private const array BRAND = ['#0F172A', '#334155', '#64748B', '#94A3B8', '#0EA5E9', '#059669', '#B45309', '#BE123C', '#7C3AED', '#0891B2'];

    private const float SATURATION = 0.5;

    /** @var list<float> */
    private const array LIGHTNESS_CYCLE = [0.36, 0.56, 0.46, 0.64];

    // The brand set opens on slate, so the generated wheel starts on the same
    // hue: a ring of eleven should not read as a different chart from a ring of
    // ten, and a red first slice is the colour this app spends on losses.
    private const int HUE_OFFSET = 215;

    private const int HUE_DEGREES = 360;

    /**
     * @return list<string>
     */
    public static function forSlices(int $count): array
    {
        if ($count <= 0) {
            return [];
        }

        if ($count <= count(self::BRAND)) {
            return array_slice(self::BRAND, 0, $count);
        }

        $colors = [];
        for ($i = 0; $i < $count; $i++) {
            $colors[] = self::hslHex(
                fmod(self::HUE_OFFSET + $i * self::HUE_DEGREES / $count, self::HUE_DEGREES),
                self::SATURATION,
                self::LIGHTNESS_CYCLE[$i % count(self::LIGHTNESS_CYCLE)],
            );
        }

        return $colors;
    }

    private static function hslHex(float $hue, float $saturation, float $lightness): string
    {
        $chroma = (1 - abs(2 * $lightness - 1)) * $saturation;
        $sector = $hue / 60.0;
        $second = $chroma * (1 - abs(fmod($sector, 2.0) - 1));
        $match = $lightness - $chroma / 2;

        [$r, $g, $b] = match ((int) floor($sector) % 6) {
            0 => [$chroma, $second, 0.0],
            1 => [$second, $chroma, 0.0],
            2 => [0.0, $chroma, $second],
            3 => [0.0, $second, $chroma],
            4 => [$second, 0.0, $chroma],
            default => [$chroma, 0.0, $second],
        };

        return sprintf('#%02X%02X%02X', self::channel($r + $match), self::channel($g + $match), self::channel($b + $match));
    }

    private static function channel(float $value): int
    {
        return (int) round(max(0.0, min(1.0, $value)) * 255);
    }
}
