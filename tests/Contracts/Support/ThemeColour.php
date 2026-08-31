<?php

declare(strict_types=1);

namespace Tests\Contracts\Support;

// A colour value resolved to sRGB, in either theme, so a contrast ratio can be
// computed from the number rather than guessed from the text. A regex over
// `oklch(0.514 0.222 16.935)` reads 0.514 as a red channel and produces
// confident nonsense, and this file's fallbacks are written in oklch.
/**
 * @link ../../../.docs/conventions/invariants-from-shipped-failures.md#a-pair-of-colours-declared-together-is-measurable-without-a-browser
 */
final class ThemeColour
{
    public const FLOOR = 4.5;

    public const LIGHT = 'light';

    public const DARK = 'dark';

    /** @var array<string, array<string, string>> */
    private static array $tokens = [];

    /**
     * @return array<string, string> every --color-* token as the named theme resolves it
     */
    public static function tokens(string $theme): array
    {
        if (self::$tokens === []) {
            $css = (string) preg_replace('#/\*.*?\*/#s', '', (string) file_get_contents(base_path('resources/css/app.css')));

            $light = [];
            foreach (self::blocks($css, ['@theme', ':root']) as $body) {
                $light = array_merge($light, self::declarations($body));
            }

            $dark = $light;
            foreach (self::blocks($css, ['.dark', ':root.dark', 'html.dark']) as $body) {
                $dark = array_merge($dark, self::declarations($body));
            }

            self::$tokens = [self::LIGHT => $light, self::DARK => $dark];
        }

        return self::$tokens[$theme];
    }

    /**
     * @return ?array{0: float, 1: float, 2: float, 3: float} sRGB 0-255 plus alpha, or null when the value is not a statically knowable colour
     */
    public static function resolve(string $value, string $theme, int $depth = 0): ?array
    {
        $value = trim($value);

        if ($depth > 8 || $value === '') {
            return null;
        }

        if (preg_match('/^var\(\s*(--[a-z0-9-]+)\s*(?:,(.*))?\)$/is', $value, $var) === 1) {
            $tokens = self::tokens($theme);
            $named = $tokens[$var[1]] ?? null;

            if ($named !== null) {
                return self::resolve($named, $theme, $depth + 1);
            }

            return isset($var[2]) ? self::resolve($var[2], $theme, $depth + 1) : null;
        }

        if (preg_match('/^color-mix\(\s*in\s+[a-z-]+\s*,(.*)\)$/is', $value, $mix) === 1) {
            return self::mix($mix[1], $theme, $depth);
        }

        return self::literal($value);
    }

    /**
     * @param  array{0: float, 1: float, 2: float, 3: float}  $colour
     * @param  array{0: float, 1: float, 2: float, 3: float}  $ground
     * @return array{0: float, 1: float, 2: float, 3: float}
     */
    public static function over(array $colour, array $ground): array
    {
        $a = $colour[3];

        return [
            $colour[0] * $a + $ground[0] * (1 - $a),
            $colour[1] * $a + $ground[1] * (1 - $a),
            $colour[2] * $a + $ground[2] * (1 - $a),
            1.0,
        ];
    }

    /**
     * @param  array{0: float, 1: float, 2: float, 3: float}  $one
     * @param  array{0: float, 1: float, 2: float, 3: float}  $other
     */
    public static function ratio(array $one, array $other): float
    {
        $a = self::luminance($one);
        $b = self::luminance($other);

        return (max($a, $b) + 0.05) / (min($a, $b) + 0.05);
    }

    /**
     * @return array{0: float, 1: float, 2: float, 3: float} the page ground a translucent surface sits on
     */
    public static function ground(string $theme): array
    {
        return self::resolve('var(--color-bg)', $theme) ?? [255.0, 255.0, 255.0, 1.0];
    }

    /**
     * @param  list<string>  $selectors
     * @return list<string>
     */
    private static function blocks(string $css, array $selectors): array
    {
        $found = [];
        $offset = 0;

        while (preg_match('/([^{}]*)\{/', $css, $match, PREG_OFFSET_CAPTURE, $offset) === 1) {
            $opening = $match[0][1] + strlen($match[0][0]) - 1;
            $body = self::body($css, $opening);

            if (in_array(trim($match[1][0]), $selectors, true)) {
                $found[] = $body;
            }

            $offset = $opening + strlen($body) + 2;
        }

        return $found;
    }

    private static function body(string $css, int $opening): string
    {
        $depth = 1;
        $cursor = $opening + 1;
        $length = strlen($css);

        while ($cursor < $length && $depth > 0) {
            if ($css[$cursor] === '{') {
                $depth++;
            } elseif ($css[$cursor] === '}') {
                $depth--;
            }
            $cursor++;
        }

        return substr($css, $opening + 1, $cursor - $opening - 2);
    }

    /**
     * @return array<string, string>
     */
    private static function declarations(string $body): array
    {
        preg_match_all('/(--color-[a-z0-9-]+)\s*:\s*([^;]+);/i', $body, $matches, PREG_SET_ORDER);

        $declared = [];
        foreach ($matches as $match) {
            $declared[$match[1]] = trim($match[2]);
        }

        return $declared;
    }

    /**
     * @return ?array{0: float, 1: float, 2: float, 3: float}
     */
    private static function mix(string $arguments, string $theme, int $depth): ?array
    {
        $parts = self::split($arguments, ',');

        if (count($parts) !== 2) {
            return null;
        }

        $weights = [];
        $colours = [];

        foreach ($parts as $part) {
            $percent = null;

            if (preg_match('/^(.*?)\s+([0-9.]+)%$/s', trim($part), $share) === 1) {
                $percent = (float) $share[2] / 100;
                $part = $share[1];
            }

            $colour = self::resolve(trim($part), $theme, $depth + 1);
            if ($colour === null) {
                return null;
            }

            $colours[] = $colour;
            $weights[] = $percent;
        }

        $first = $weights[0] ?? null;
        $second = $weights[1] ?? null;
        $first ??= $second !== null ? 1 - $second : 0.5;
        $second ??= 1 - $first;

        $mixed = [0.0, 0.0, 0.0, 0.0];
        for ($channel = 0; $channel < 4; $channel++) {
            $mixed[$channel] = $colours[0][$channel] * $first + $colours[1][$channel] * $second;
        }

        return $mixed;
    }

    /**
     * @return ?array{0: float, 1: float, 2: float, 3: float}
     */
    private static function literal(string $value): ?array
    {
        $lower = strtolower($value);

        $named = [
            'transparent' => [0.0, 0.0, 0.0, 0.0],
            'white' => [255.0, 255.0, 255.0, 1.0],
            'black' => [0.0, 0.0, 0.0, 1.0],
        ];

        if (isset($named[$lower])) {
            return $named[$lower];
        }

        if (preg_match('/^#([0-9a-f]{3,8})$/i', $value, $hex) === 1) {
            return self::fromHex($hex[1]);
        }

        if (preg_match('/^(rgba?|hsla?|oklch|oklab)\((.*)\)$/is', $value, $call) === 1) {
            return self::fromFunction(strtolower($call[1]), $call[2]);
        }

        return null;
    }

    /**
     * @return ?array{0: float, 1: float, 2: float, 3: float}
     */
    private static function fromHex(string $digits): ?array
    {
        $length = strlen($digits);

        if ($length === 3 || $length === 4) {
            $digits = (string) preg_replace('/(.)/', '$1$1', $digits);
            $length *= 2;
        }

        if ($length !== 6 && $length !== 8) {
            return null;
        }

        return [
            (float) hexdec(substr($digits, 0, 2)),
            (float) hexdec(substr($digits, 2, 2)),
            (float) hexdec(substr($digits, 4, 2)),
            $length === 8 ? hexdec(substr($digits, 6, 2)) / 255 : 1.0,
        ];
    }

    /**
     * @return ?array{0: float, 1: float, 2: float, 3: float}
     */
    private static function fromFunction(string $name, string $arguments): ?array
    {
        [$body, $alphaText] = array_pad(self::split($arguments, '/'), 2, null);
        $parts = self::split((string) preg_replace('/\s*,\s*/', ' ', (string) $body), ' ');

        if ($alphaText !== null) {
            $parts[] = $alphaText;
        }

        $numbers = [];
        foreach ($parts as $part) {
            if ($part === 'none') {
                continue;
            }
            if (preg_match('/^-?[0-9.]+%?(deg)?$/i', $part) !== 1) {
                return null;
            }
            $numbers[] = $part;
        }

        if (count($numbers) < 3) {
            return null;
        }

        $alpha = isset($numbers[3]) ? self::scalar($numbers[3], 1.0) : 1.0;

        if ($name === 'rgb' || $name === 'rgba') {
            return [
                self::scalar($numbers[0], 255.0),
                self::scalar($numbers[1], 255.0),
                self::scalar($numbers[2], 255.0),
                $alpha,
            ];
        }

        if ($name === 'hsl' || $name === 'hsla') {
            return self::fromHsl(
                self::scalar($numbers[0], 360.0),
                self::scalar($numbers[1], 1.0),
                self::scalar($numbers[2], 1.0),
                $alpha
            );
        }

        $lightness = self::scalar($numbers[0], 1.0);

        if ($name === 'oklab') {
            return self::fromOklab($lightness, self::scalar($numbers[1], 0.4), self::scalar($numbers[2], 0.4), $alpha);
        }

        $chroma = self::scalar($numbers[1], 0.4);
        $hue = deg2rad(self::scalar($numbers[2], 360.0));

        return self::fromOklab($lightness, $chroma * cos($hue), $chroma * sin($hue), $alpha);
    }

    private static function scalar(string $text, float $full): float
    {
        if (str_ends_with($text, '%')) {
            return (float) substr($text, 0, -1) / 100 * $full;
        }

        $text = (string) preg_replace('/deg$/i', '', $text);

        return (float) $text;
    }

    /**
     * @return array{0: float, 1: float, 2: float, 3: float}
     */
    private static function fromHsl(float $hue, float $saturation, float $lightness, float $alpha): array
    {
        $hue = fmod(fmod($hue, 360) + 360, 360) / 60;
        $chroma = (1 - abs(2 * $lightness - 1)) * $saturation;
        $second = $chroma * (1 - abs(fmod($hue, 2) - 1));
        $base = $lightness - $chroma / 2;

        $wheel = [
            [$chroma, $second, 0.0], [$second, $chroma, 0.0], [0.0, $chroma, $second],
            [0.0, $second, $chroma], [$second, 0.0, $chroma], [$chroma, 0.0, $second],
        ];
        $slice = $wheel[(int) floor($hue) % 6];

        return [
            ($slice[0] + $base) * 255,
            ($slice[1] + $base) * 255,
            ($slice[2] + $base) * 255,
            $alpha,
        ];
    }

    /**
     * @return array{0: float, 1: float, 2: float, 3: float}
     */
    private static function fromOklab(float $lightness, float $a, float $b, float $alpha): array
    {
        $l = ($lightness + 0.3963377774 * $a + 0.2158037573 * $b) ** 3;
        $m = ($lightness - 0.1055613458 * $a - 0.0638541728 * $b) ** 3;
        $s = ($lightness - 0.0894841775 * $a - 1.2914855480 * $b) ** 3;

        $linear = [
            4.0767416621 * $l - 3.3077115913 * $m + 0.2309699292 * $s,
            -1.2684380046 * $l + 2.6097574011 * $m - 0.3413193965 * $s,
            -0.0041960863 * $l - 0.7034186147 * $m + 1.7076147010 * $s,
        ];

        $srgb = [];
        foreach ($linear as $channel) {
            $channel = max(0.0, min(1.0, $channel));
            $srgb[] = ($channel <= 0.0031308 ? 12.92 * $channel : 1.055 * $channel ** (1 / 2.4) - 0.055) * 255;
        }

        return [$srgb[0], $srgb[1], $srgb[2], $alpha];
    }

    /**
     * @param  array{0: float, 1: float, 2: float, 3: float}  $colour
     */
    private static function luminance(array $colour): float
    {
        $weights = [0.2126, 0.7152, 0.0722];
        $total = 0.0;

        for ($channel = 0; $channel < 3; $channel++) {
            $value = max(0.0, min(1.0, $colour[$channel] / 255));
            $linear = $value <= 0.04045 ? $value / 12.92 : (($value + 0.055) / 1.055) ** 2.4;
            $total += $weights[$channel] * $linear;
        }

        return $total;
    }

    /**
     * @return list<string> $text cut on $separator, ignoring separators inside parentheses
     */
    public static function split(string $text, string $separator): array
    {
        $parts = [];
        $current = '';
        $depth = 0;

        foreach (str_split($text) as $character) {
            if ($character === '(') {
                $depth++;
            } elseif ($character === ')') {
                $depth--;
            }

            if ($character === $separator && $depth === 0) {
                $parts[] = $current;
                $current = '';

                continue;
            }

            $current .= $character;
        }

        $parts[] = $current;

        return array_values(array_filter(array_map('trim', $parts), static fn (string $part): bool => $part !== ''));
    }
}
