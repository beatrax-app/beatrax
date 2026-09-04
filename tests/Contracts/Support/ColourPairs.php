<?php

declare(strict_types=1);

namespace Tests\Contracts\Support;

use Modules\Core\Public\Support\PatternScan;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

// Every place a background and a text colour are declared in the same breath.
// Declared together is what makes a pair measurable without a browser: an
// inline style wins the cascade outright, and a rule that sets both sets both
// on whatever it matches.
/**
 * @link ../../../.docs/conventions/invariants-from-shipped-failures.md#a-pair-of-colours-declared-together-is-measurable-without-a-browser
 */
final class ColourPairs
{
    private const UNKNOWABLE = '__blade__';

    private const BOTH = [ThemeColour::LIGHT, ThemeColour::DARK];

    /**
     * @return list<array{file: string, line: int, themes: list<string>, background: string, color: string}>
     */
    public static function inInlineStyles(string $path, string $source): array
    {
        $pairs = [];

        foreach (self::attributes($source) as $attribute) {
            foreach (self::variants($attribute['style']) as $variant) {
                $pair = self::pairIn(self::properties($variant));

                if ($pair !== null) {
                    $pairs[] = ['file' => $path, 'line' => $attribute['line'], 'themes' => self::BOTH] + $pair;
                }
            }
        }

        return $pairs;
    }

    // A style held in a PHP variable and echoed into the attribute. The chip
    // row on a counterparty profile is written this way, so a scan that reads
    // only attributes cannot see either of its two colour pairs.
    /**
     * @return list<array{file: string, line: int, themes: list<string>, background: string, color: string}>
     */
    public static function inPhpStrings(string $path, string $source): array
    {
        $pairs = [];

        $islands = PatternScan::setsWithOffsets('/@php\b(?:.*?)@endphp|<\?php(?:.*?)\?>|@php\((?:.*?)\)/s', $source);

        foreach ($islands as $island) {
            $strings = PatternScan::setsWithOffsets('/\'([^\']*)\'|"([^"]*)"/', (string) $island[0][0]);

            foreach ($strings as $string) {
                $literal = ($string[1][0] ?? '') !== '' ? (string) $string[1][0] : (string) ($string[2][0] ?? '');
                $pair = self::pairIn(self::properties($literal));

                if ($pair !== null) {
                    $at = (int) $island[0][1] + (int) $string[0][1];
                    $pairs[] = [
                        'file' => $path,
                        'line' => substr_count(substr($source, 0, $at), "\n") + 1,
                        'themes' => self::BOTH,
                    ] + $pair;
                }
            }
        }

        return $pairs;
    }

    /**
     * @return list<array{file: string, line: int, themes: list<string>, background: string, color: string}>
     */
    public static function inStylesheet(string $path, string $source): array
    {
        $css = self::blankComments($source);
        $night = self::darkMediaRegions($css);
        $rules = self::rules($css, $night);
        $overrides = self::darkOverrides($rules);

        $pairs = [];

        foreach ($rules as $rule) {
            if ($rule['themes'] === [ThemeColour::DARK] && self::overridesABaseRule($rule['selector'])) {
                continue;
            }

            foreach ($rule['themes'] as $theme) {
                $properties = $rule['properties'];

                if ($theme === ThemeColour::DARK) {
                    foreach (self::split($rule['selector'], ',') as $selector) {
                        $properties = array_merge($properties, $overrides[$selector] ?? []);
                    }
                }

                $pair = self::pairIn($properties);

                if ($pair !== null) {
                    $pairs[] = ['file' => $path, 'line' => $rule['line'], 'themes' => [$theme]] + $pair;
                }
            }
        }

        return $pairs;
    }

    // Attributes whose colour cannot be read from the source at all: the value
    // is a Blade expression with no literal in it. Reported as a count rather
    // than guessed at.
    /**
     * @return list<string>
     */
    public static function opaqueInlineStyles(string $path, string $source): array
    {
        $opaque = [];

        foreach (self::attributes($source) as $attribute) {
            foreach (self::variants($attribute['style']) as $variant) {
                if (! str_contains($variant, self::UNKNOWABLE)) {
                    continue;
                }

                $properties = self::properties($variant);
                $colours = array_intersect_key($properties, array_flip(['background', 'background-color', 'color']));

                if ($properties === [] || implode('', $colours) !== str_replace(self::UNKNOWABLE, '', implode('', $colours))) {
                    $opaque[] = "{$path}:{$attribute['line']}  ".PatternScan::replace('/\s+/', ' ', trim($attribute['style']));
                }
            }
        }

        return array_values(array_unique($opaque));
    }

    /**
     * @param  list<array{file: string, line: int, themes: list<string>, background: string, color: string}>  $pairs
     * @return array{failing: list<string>, unreadable: list<string>, transparent: list<string>, measured: int}
     */
    public static function measure(array $pairs): array
    {
        $report = ['failing' => [], 'unreadable' => [], 'transparent' => [], 'measured' => 0];

        foreach ($pairs as $pair) {
            $where = "{$pair['file']}:{$pair['line']}";
            $worst = null;

            foreach ($pair['themes'] as $theme) {
                if (in_array(strtolower(trim($pair['background'])), ['transparent', 'none'], true)) {
                    $report['transparent'][] = "{$where}  color: {$pair['color']};";

                    continue 2;
                }

                $backgrounds = self::grounds($pair['background'], $theme);
                $text = self::colour($pair['color'], $theme);

                if ($backgrounds === null || $text === null) {
                    $report['unreadable'][] = "{$where}  background: {$pair['background']}; color: {$pair['color']};";

                    continue 2;
                }

                if (count($backgrounds) === 1 && $backgrounds[0][3] === 0.0) {
                    $report['transparent'][] = "{$where}  color: {$pair['color']};";

                    continue 2;
                }

                foreach ($backgrounds as $background) {
                    $ground = ThemeColour::over($background, ThemeColour::ground($theme));
                    $ratio = ThemeColour::ratio(ThemeColour::over($text, $ground), $ground);

                    if ($worst === null || $ratio < $worst[1]) {
                        $worst = [$theme, $ratio];
                    }
                }
            }

            if ($worst === null) {
                continue;
            }

            $report['measured']++;

            if ($worst[1] < ThemeColour::FLOOR) {
                $report['failing'][] = sprintf(
                    '%s  in %s  background: %s; color: %s;  reads %.2f:1',
                    $where,
                    $worst[0],
                    $pair['background'],
                    $pair['color'],
                    $worst[1]
                );
            }
        }

        foreach (['failing', 'unreadable', 'transparent'] as $bucket) {
            $report[$bucket] = array_values(array_unique($report[$bucket]));
            sort($report[$bucket]);
        }

        return $report;
    }

    /**
     * @return list<array{path: string, source: string}> every template under Modules/ and resources/
     */
    public static function templates(): array
    {
        $found = [];

        foreach (['Modules', 'resources'] as $root) {
            $walk = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(base_path($root)));

            foreach ($walk as $file) {
                $path = $file->getPathname();

                if (! str_ends_with($path, '.blade.php') || str_contains($path, '/tests/')) {
                    continue;
                }

                $found[] = [
                    'path' => str_replace(base_path().'/', '', $path),
                    'source' => (string) file_get_contents($path),
                ];
            }
        }

        usort($found, static fn (array $a, array $b): int => strcmp($a['path'], $b['path']));

        return $found;
    }

    /**
     * @return list<array{line: int, style: string}>
     */
    private static function attributes(string $source): array
    {
        $matches = PatternScan::setsWithOffsets('/(?<![-:\w])style="([^"]*)"/s', $source);

        return array_map(static fn (array $match): array => [
            'line' => substr_count(substr($source, 0, (int) $match[0][1]), "\n") + 1,
            'style' => (string) $match[1][0],
        ], $matches);
    }

    // Every ground the background can paint. A gradient is a background-IMAGE,
    // so a computed-style probe reads transparent and walks past it — but its
    // stops are written down here, and the text has to clear the worst of them.
    /**
     * @return ?list<array{0: float, 1: float, 2: float, 3: float}>
     */
    private static function grounds(string $value, string $theme): ?array
    {
        if (preg_match('/^(?:linear|radial|conic)-gradient\((.*)\)$/is', trim($value), $gradient) !== 1) {
            $single = self::colour($value, $theme);

            return $single === null ? null : [$single];
        }

        $stops = [];
        foreach (self::split($gradient[1], ',') as $stop) {
            $stop = PatternScan::replace('/\s+-?[0-9.]+(%|[a-z]+)\s*$/i', '', trim($stop));
            $resolved = self::colour($stop, $theme);

            if ($resolved !== null) {
                $stops[] = $resolved;
            }
        }

        return $stops === [] ? null : $stops;
    }

    // A background shorthand may carry an image and a position beside its
    // colour, so the first component that resolves is the colour.
    /**
     * @return ?array{0: float, 1: float, 2: float, 3: float}
     */
    private static function colour(string $value, string $theme): ?array
    {
        if (str_contains($value, self::UNKNOWABLE)) {
            return null;
        }

        $whole = ThemeColour::resolve($value, $theme);

        if ($whole !== null) {
            return $whole;
        }

        foreach (ThemeColour::split($value, ' ') as $component) {
            $part = ThemeColour::resolve($component, $theme);

            if ($part !== null) {
                return $part;
            }
        }

        return null;
    }

    // One reading per branch of the Blade expressions in the attribute. The
    // branches align: a style whose background is chosen by a condition picks
    // its text colour by the same condition, so branch one is one reading and
    // branch two another. Crossing them would invent a pair no render makes.
    /**
     * @return list<string>
     */
    private static function variants(string $attribute): array
    {
        $regions = PatternScan::sets('/\{\{(.*?)\}\}/s', $attribute);

        if ($regions === []) {
            return [$attribute];
        }

        $branches = [];
        foreach ($regions as $region) {
            $literals = PatternScan::all("/'([^']*)'/", $region[1]);
            $branches[] = $literals[1] === [] ? [self::UNKNOWABLE] : $literals[1];
        }

        $variants = [];

        for ($reading = 0; $reading < max(array_map('count', $branches)); $reading++) {
            $variant = $attribute;

            foreach ($regions as $index => $region) {
                $choices = $branches[$index];
                $variant = str_replace($region[0], $choices[min($reading, count($choices) - 1)], $variant);
            }

            $variants[] = $variant;
        }

        return $variants;
    }

    /**
     * @return array<string, string> the declarations of one block, last spelling winning as the cascade has it
     */
    private static function properties(string $block): array
    {
        $declared = [];

        foreach (self::split($block, ';') as $declaration) {
            if (preg_match('/^\s*([a-z-]+)\s*:(.*)$/is', $declaration, $parts) === 1) {
                $declared[strtolower($parts[1])] = trim($parts[2]);
            }
        }

        return $declared;
    }

    /**
     * @param  array<string, string>  $properties
     * @return ?array{background: string, color: string}
     */
    private static function pairIn(array $properties): ?array
    {
        $background = $properties['background'] ?? $properties['background-color'] ?? null;
        $text = $properties['color'] ?? null;

        if ($background === null || $text === null) {
            return null;
        }

        return ['background' => $background, 'color' => $text];
    }

    private static function blankComments(string $source): string
    {
        return PatternScan::replaceCallback(
            '#/\*.*?\*/#s',
            static fn (array $comment): string => PatternScan::replace('/[^\n]/', ' ', $comment[0]),
            $source
        );
    }

    /**
     * @param  list<array{0: int, 1: int}>  $night
     * @return list<array{selector: string, line: int, themes: list<string>, properties: array<string, string>}>
     */
    private static function rules(string $css, array $night): array
    {
        $matches = PatternScan::setsWithOffsets('/([^{}]*)\{([^{}]*)\}/s', $css);

        $rules = [];
        foreach ($matches as $match) {
            $at = (int) $match[2][1];
            $inNight = false;
            foreach ($night as $region) {
                $inNight = $inNight || ($at >= $region[0] && $at < $region[1]);
            }

            $selector = PatternScan::replace('/\s+/', ' ', trim((string) $match[1][0]));

            $rules[] = [
                'selector' => $selector,
                'line' => substr_count(substr($css, 0, $at), "\n") + 1,
                'themes' => self::themesFor($selector, $inNight),
                'properties' => self::properties((string) $match[2][0]),
            ];
        }

        return $rules;
    }

    // The themes a rule can be seen in. A declaration under .dark never
    // renders against the light tokens, and measuring it there reports a
    // pairing the product cannot produce.
    /**
     * @return list<string>
     */
    private static function themesFor(string $selector, bool $night): array
    {
        if ($night || preg_match('/(^|[\s,>+~(])(:root|html)?\.dark\b/', $selector) === 1) {
            return [ThemeColour::DARK];
        }

        if (preg_match('/:not\(\s*\.dark\s*\)|\.light\b/', $selector) === 1) {
            return [ThemeColour::LIGHT];
        }

        return self::BOTH;
    }

    private static function overridesABaseRule(string $selector): bool
    {
        foreach (self::split($selector, ',') as $part) {
            if (preg_match('/^\.dark\s+\S/', $part) !== 1) {
                return false;
            }
        }

        return $selector !== '';
    }

    // What `.dark .thing` says about `.thing`. A base rule measured against
    // the night tokens has to be read through its override, or the guard
    // reports a pairing the dark theme already replaced.
    /**
     * @param  list<array{selector: string, line: int, themes: list<string>, properties: array<string, string>}>  $rules
     * @return array<string, array<string, string>>
     */
    private static function darkOverrides(array $rules): array
    {
        $overrides = [];

        foreach ($rules as $rule) {
            if ($rule['themes'] !== [ThemeColour::DARK]) {
                continue;
            }

            foreach (self::split($rule['selector'], ',') as $part) {
                if (preg_match('/^\.dark\s+(\S.*)$/', $part, $base) === 1) {
                    $overrides[$base[1]] = array_merge($overrides[$base[1]] ?? [], $rule['properties']);
                }
            }
        }

        return $overrides;
    }

    /**
     * @return list<array{0: int, 1: int}>
     */
    private static function darkMediaRegions(string $css): array
    {
        $opens = PatternScan::setsWithOffsets('/@media[^{]*prefers-color-scheme\s*:\s*dark[^{]*\{/i', $css);

        $regions = [];
        foreach ($opens as $open) {
            $opening = (int) $open[0][1] + strlen((string) $open[0][0]) - 1;
            $depth = 1;
            $cursor = $opening + 1;

            while ($cursor < strlen($css) && $depth > 0) {
                if ($css[$cursor] === '{') {
                    $depth++;
                } elseif ($css[$cursor] === '}') {
                    $depth--;
                }
                $cursor++;
            }

            $regions[] = [$opening, $cursor];
        }

        return $regions;
    }

    /**
     * @return list<string>
     */
    private static function split(string $text, string $separator): array
    {
        return ThemeColour::split($text, $separator);
    }
}
