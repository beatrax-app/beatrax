<?php

declare(strict_types=1);

use Modules\Core\Public\Support\PatternScan;

/**
 * @link ../../.docs/conventions/invariants-from-shipped-failures.md#two-phone-constraints-and-three-dialog-naming-failures
 * @link ../../.docs/conventions/invariants-from-shipped-failures.md#a-guard-that-lists-element-names-misses-the-one-nobody-listed
 */

// Brace counting, not a nested-quantifier regex: the coarse-pointer floor grew
// past PCRE's JIT stack, and preg_match_all answered a truncated list rather
// than failing — the guard stopped looking and said the rules were gone. Depth
// also lets a block hold an @supports, which one of them does.
/**
 * @return list<array{0: int, 1: int}> the offset and length of every balanced
 *                                     `<at-rule> { ... }` run in $css
 */
function phoneUiBalancedSpans(string $css, string $opening): array
{
    $spans = [];
    $offset = 0;
    $length = strlen($css);

    while (preg_match($opening, $css, $match, PREG_OFFSET_CAPTURE, $offset) === 1) {
        $start = $match[0][1];
        $cursor = $start + strlen($match[0][0]);
        $depth = 1;
        while ($depth > 0 && $cursor < $length) {
            if ($css[$cursor] === '{') {
                $depth++;
            } elseif ($css[$cursor] === '}') {
                $depth--;
            }
            $cursor++;
        }

        $spans[] = [$start, $cursor - $start];
        $offset = $cursor;
    }

    return $spans;
}

/**
 * @return string the stylesheet with every balanced `@layer name { ... }` block
 *                removed, so what is left is exactly the unlayered rules
 */
function phoneUiUnlayeredCss(): string
{
    $css = (string) file_get_contents(base_path('resources/css/app.css'));

    $out = '';
    $offset = 0;
    foreach (phoneUiBalancedSpans($css, '/@layer\s+[a-z]+\s*\{/') as [$start, $length]) {
        $out .= substr($css, $offset, $start - $offset);
        $offset = $start + $length;
    }

    return $out.substr($css, $offset);
}

/** @return list<string> every `@media (pointer: coarse)` block that is not inside a cascade layer */
function phoneUiCoarsePointerBlocks(): array
{
    $css = phoneUiUnlayeredCss();

    $blocks = [];
    foreach (phoneUiBalancedSpans($css, '/@media \(pointer: coarse\)\s*\{/') as [$start, $length]) {
        $blocks[] = substr($css, $start, $length);
    }

    return $blocks;
}

// A null replacement is not "nothing to change", it is "stopped reading", and
// this file has already shipped a guard that reported the opposite of the truth
// after PCRE gave up on a long block. Every substitution here answers through
// this.
function phoneUiReplaced(string $reading, ?string $out): string
{
    if ($out === null || preg_last_error() !== PREG_NO_ERROR) {
        throw new RuntimeException($reading.' stopped reading: '.preg_last_error_msg());
    }

    return $out;
}

/** @return list<string> every Blade view in the two roots that hold them */
function phoneUiBladeViews(): array
{
    $views = [];
    foreach (['Modules', 'resources'] as $root) {
        $directory = new RecursiveDirectoryIterator(base_path($root), FilesystemIterator::SKIP_DOTS);
        foreach (new RecursiveIteratorIterator($directory) as $file) {
            if (str_ends_with((string) $file, '.blade.php')) {
                $views[] = (string) $file;
            }
        }
    }

    sort($views);

    return $views;
}

// Blanked rather than cut, and newlines kept, so every offset and every line
// number still points at the source the reader has open.
/** @return string the view with its comments and PHP blocks blanked out */
function phoneUiMarkupOnly(string $blade): string
{
    foreach (['/\{\{--.*?--\}\}/s', '/@php\b.*?@endphp/s', '/@props\s*\(\[.*?\]\)/s', '/<!--.*?-->/s'] as $pattern) {
        $found = PatternScan::allWithOffsets($pattern, $blade);
        foreach ($found[0] as [$text, $at]) {
            $blank = phoneUiReplaced('blanking', preg_replace('/[^\n]/', ' ', $text));
            $blade = substr_replace($blade, $blank, $at, strlen($text));
        }
    }

    return $blade;
}

// `[^>]*` ends a tag at the first `>` it meets, and a Blade attribute is full of
// them: `['id' => $x]` in an href, `$attributes->merge(...)` in a component. 113
// of the 424 button and summary tags in this tree were read half-way by exactly
// that pattern, class attribute included. Quotes and echoes are stepped over.
/**
 * @return list<array{0: int, 1: string, 2: string}> the offset, the start tag and
 *                                                   the inner source of every
 *                                                   `<$name>` element in $blade
 */
function phoneUiElements(string $blade, string $name): array
{
    $elements = [];
    $length = strlen($blade);
    $at = 0;

    while (($at = strpos($blade, '<'.$name, $at)) !== false) {
        $after = $blade[$at + strlen($name) + 1] ?? '';
        if ($after !== '' && $after !== '>' && ! ctype_space($after)) {
            $at += strlen($name) + 1;

            continue;
        }

        $end = phoneUiTagEnd($blade, $at + strlen($name) + 1, $length);
        $close = strpos($blade, '</'.$name, $end);
        $elements[] = [
            $at,
            substr($blade, $at, $end - $at + 1),
            $close === false ? '' : substr($blade, $end + 1, $close - $end - 1),
        ];
        $at = $end + 1;
    }

    return $elements;
}

/** @return int the offset of the `>` that closes the start tag opened before $from */
function phoneUiTagEnd(string $blade, int $from, int $length): int
{
    $quote = '';
    while ($from < $length) {
        $character = $blade[$from];

        if ($quote !== '') {
            if ($character === $quote) {
                $quote = '';
            }
        } elseif ($character === '"' || $character === "'") {
            $quote = $character;
        } elseif ($character === '{' && ($blade[$from + 1] ?? '') === '{') {
            $echo = strpos($blade, '}}', $from);

            return $echo === false ? $length - 1 : phoneUiTagEnd($blade, $echo + 2, $length);
        } elseif ($character === '>') {
            return $from;
        }

        $from++;
    }

    return $length - 1;
}

/**
 * @return list<string> the class attribute of every element a finger reaches for,
 *                      across all Blade views
 */
function phoneUiTouchControlClassLists(): array
{
    $lists = [];
    foreach (phoneUiBladeViews() as $view) {
        $markup = phoneUiMarkupOnly((string) file_get_contents($view));
        foreach (['button', 'summary', 'a'] as $name) {
            foreach (phoneUiElements($markup, $name) as [, $tag]) {
                $lists = array_merge($lists, phoneUiClassAttributes($tag));
            }
        }
    }

    return $lists;
}

// Two spellings, because a component builds its class list in PHP and merges it:
// x-core::primary-button's link arm carries `tap-chip` only inside the echo.
// A `{{ $x }}` inside a literal list is blanked rather than split, or the token
// it is glued to reads as `side-item{{`.
/** @return list<string> the class lists an element declares, merged ones included */
function phoneUiClassAttributes(string $tag): array
{
    $literal = PatternScan::all('/(?<![-:\w])class="([^"]*)"/s', $tag);

    $lists = [];
    foreach ($literal[1] as $list) {
        $lists[] = phoneUiReplaced('class echo', preg_replace('/\{\{.*?\}\}/s', ' ', $list));
    }

    $echoes = PatternScan::all('/\{\{(.*?)\}\}/s', $tag);
    foreach ($echoes[1] as $php) {
        $strings = PatternScan::all("/'([^']*)'/s", $php);
        $lists = array_merge($lists, $strings[1]);
    }

    return $lists;
}

it('keeps every touch form control at or above the iOS auto-zoom threshold', function (): void {
    // The viewport deliberately allows zoom, so raising the control is the only
    // remedy for iOS auto-zoom that does not break pinch-zoom for everyone.
    $covering = array_filter(
        phoneUiCoarsePointerBlocks(),
        static fn (string $block): bool => str_contains($block, 'font-size: 16px')
            && str_contains($block, 'input')
            && str_contains($block, 'select')
            && str_contains($block, 'textarea'),
    );

    expect($covering)->not->toBeEmpty(
        'app.css has no unlayered coarse-pointer rule holding input/select/textarea at 16px'
    );
});

it('gives every touch button a 44px hit area', function (): void {
    // Unlayered, or `h-10` outranks it.
    $covering = array_filter(
        phoneUiCoarsePointerBlocks(),
        static fn (string $block): bool => str_contains($block, 'min-height: 44px')
            && str_contains($block, 'min-width: 44px')
            && preg_match('/(^|[\s,])button\s*[,{]/m', $block) === 1,
    );

    expect($covering)->not->toBeEmpty(
        'app.css has no unlayered coarse-pointer 44px floor covering <button>'
    );
});

it('names the bottom sheet dialog by its heading rather than by a second copy of it', function (): void {
    $sheet = file_get_contents(base_path('Modules/Core/Resources/views/components/bottom-sheet.blade.php'));
    expect($sheet)->toBeString();

    // The same sheet is both "create" and "edit", and a duplicated name went
    // stale on the round-trip that updated the heading.
    expect($sheet)->toContain('aria-labelledby=')
        ->and($sheet)->not->toContain('aria-label="{{ $title }}"');
});

it('never leaves the sheet dialog without an accessible name', function (): void {
    $sheet = (string) file_get_contents(base_path('Modules/Core/Resources/views/components/bottom-sheet.blade.php'));

    // A title can be conditional at the call site — the calendar's is '' before
    // a day is picked — so the empty branch has to name the dialog too.
    expect($sheet)->toContain('@else')
        ->and($sheet)->toContain("aria-label=\"{{ Lang::get('core::components.sheet_untitled') }}\"");
});

it('backs every open-sheet dispatch with a sheet that answers to that name', function (): void {
    $files = [];
    foreach (['Modules', 'resources/views'] as $root) {
        $dir = base_path($root);
        if (! is_dir($dir)) {
            continue;
        }
        $walk = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
        foreach ($walk as $file) {
            if (str_ends_with($file->getFilename(), '.blade.php')) {
                $files[] = $file->getPathname();
            }
        }
    }

    expect($files)->not->toBeEmpty();

    // A dispatched sheet name that only a flux:modal carries is inert on a
    // phone, and the page still looks complete.
    $orphans = [];
    foreach ($files as $path) {
        $blade = (string) file_get_contents($path);

        $dispatched = PatternScan::all('/open-sheet[\'"]?\s*,\s*\{?\s*name\s*:\s*[\'"]([a-z0-9-]+)[\'"]/', $blade);
        $declared = PatternScan::all('/<x-core::bottom-sheet\s+name=["\']([a-z0-9-]+)["\']/', $blade);

        foreach (array_unique($dispatched[1]) as $name) {
            if (! in_array($name, $declared[1], true)) {
                $orphans[] = $name.' in '.str_replace(base_path().'/', '', $path);
            }
        }
    }

    expect($orphans)->toBe([], 'open-sheet dispatched at a name no bottom sheet answers to: '.implode(', ', $orphans));
});

it('keeps the 44px floor from deforming controls the design draws smaller', function (): void {
    // The floor inflates the border box, so a fixed-size pill-radius control
    // becomes a circle and its positioned children strand. Anything it would
    // deform opts out and takes its touch reach from a pseudo-element.
    $css = phoneUiUnlayeredCss();

    $drawnSmall = [];
    $rules = PatternScan::sets('/(?<selector>[^{}]+)\{(?<body>[^{}]*)\}/', $css);
    foreach ($rules as $rule) {
        $selector = trim($rule['selector']);
        $name = PatternScan::first('/^\.([a-zA-Z0-9_-]+)$/', $selector);
        if ($name === []) {
            continue;
        }

        $width = PatternScan::first('/(?<![a-z-])width:\s*(\d+)px/', $rule['body']);
        $height = PatternScan::first('/(?<![a-z-])height:\s*(\d+)px/', $rule['body']);

        $sizes = array_map(intval(...), array_column([$width, $height], 1));
        if ($sizes !== [] && min($sizes) < 44) {
            $drawnSmall[$name[1]] = true;
        }
    }

    $onButtons = [];
    foreach (phoneUiTouchControlClassLists() as $classList) {
        foreach (preg_split('/\s+/', $classList) ?: [] as $class) {
            if (isset($drawnSmall[$class])) {
                $onButtons[$class] = true;
            }
        }
    }

    $unprotected = array_keys(array_filter(
        $onButtons,
        static fn (bool $_, string $class): bool => preg_match(
            '/\.'.preg_quote($class, '/').'(::after)?\s*[,{]/',
            implode('', phoneUiCoarsePointerBlocks())
        ) !== 1,
        ARRAY_FILTER_USE_BOTH
    ));

    expect($unprotected)->toBe([], sprintf(
        '%s sit on a touch control at a fixed size below 44px and the coarse-pointer floor will deform them',
        implode(', ', array_map(static fn (string $c): string => '.'.$c, $unprotected))
    ));
});

/**
 * @return list<string> every selector an unlayered coarse-pointer rule holds at
 *                      44px, one entry per comma-separated part
 */
function phoneUiCoarse44pxSelectors(): array
{
    $selectors = [];
    foreach (phoneUiCoarsePointerBlocks() as $block) {
        $block = (string) preg_replace('#/\*.*?\*/#s', '', $block);

        $rules = PatternScan::sets('/(?<selector>[^{}]+)\{(?<body>[^{}]*)\}/', $block);
        foreach ($rules as $rule) {
            if (preg_match('/(?<![a-z-])(?:min-)?height:\s*44px/', $rule['body']) !== 1) {
                continue;
            }

            foreach (explode(',', $rule['selector']) as $selector) {
                $selectors[] = trim((string) preg_replace('/\s+/', ' ', $selector));
            }
        }
    }

    return $selectors;
}

it('gives the sidebar rows real height on touch', function (): void {
    // 26 rows at a 31px pitch sit flush, so the pseudo-element halo the chips
    // take their reach from would only steal from the row below.
    expect(phoneUiCoarse44pxSelectors())->toContain('.side-item', '.side-search');
});

it('gives every touch control the floor and the halo both miss a 44px hit area', function (): void {
    // A select is tapped like a button and matched like neither; the checkbox
    // label is the target because it wraps the input.
    expect(phoneUiCoarse44pxSelectors())->toContain(
        'select',
        '.srch-input',
        "label:has(> input[type='checkbox'])",
        '.tap-link::after',
    );
});

/**
 * @return list<string> the declarations of every unlayered coarse-pointer rule
 *                      whose selector list carries exactly this selector
 */
function phoneUiCoarseRuleBodies(string $selector): array
{
    $bodies = [];

    foreach (phoneUiCoarsePointerBlocks() as $block) {
        $block = (string) preg_replace('#/\*.*?\*/#s', '', $block);

        $rules = PatternScan::sets('/(?<selector>[^{}]+)\{(?<body>[^{}]*)\}/', $block);
        foreach ($rules as $rule) {
            foreach (explode(',', $rule['selector']) as $part) {
                if (trim((string) preg_replace('/\s+/', ' ', $part)) === $selector) {
                    $bodies[] = $rule['body'];
                }
            }
        }
    }

    return $bodies;
}

it('drops the native appearance the select floor is inert without', function (): void {
    // Measured on an iPhone with the floor already in place: 29pt, with the
    // rule applying and its padding, radius and colours all landing. WebKit
    // sizes a select that keeps its native appearance from the font alone and
    // ignores height on it, so the declaration was inert rather than lost.
    $floors = array_values(array_filter(
        phoneUiCoarseRuleBodies('select'),
        static fn (string $body): bool => preg_match('/(?<![a-z-])min-height:\s*44px/', $body) === 1,
    ));

    expect($floors)->not->toBeEmpty(
        'no unlayered coarse-pointer rule holds a bare `select` at 44px any more'
    );

    // Removing the appearance also removes the arrow the platform drew, and a
    // select cannot carry a pseudo-element to put one back — so the rule that
    // takes the arrow away is the one that has to redraw it.
    $inert = array_values(array_filter(
        $floors,
        static fn (string $body): bool => ! str_contains($body, '-webkit-appearance: none')
            || preg_match('/(?<![-a-z])appearance:\s*none/', $body) !== 1
            || ! str_contains($body, 'background-image: var(--select-chevron)'),
    ));

    expect($inert)->toBe([], implode("\n", [
        'A select that keeps its native appearance is sized by WebKit from its font,',
        'and this floor measured 29pt on device while applying. It needs BOTH spellings',
        'of appearance: none — the prefixed one for WebKit, the standard one for every',
        'engine that has dropped the prefix — and a drawn chevron, because dropping the',
        'appearance takes the platform arrow with it. Rules missing one of the three:',
        ...$inert,
    ]));
});

it('defines the drawn chevron in both colour schemes', function (): void {
    // A background-image reading a custom property nothing defines paints
    // nothing at all, and the control loses its arrow rather than its height —
    // which looks like a design choice from every angle except a device.
    $css = phoneUiUnlayeredCss();

    expect($css)->toMatch('/:root\s*\{[^{}]*--select-chevron:/');
    expect($css)->toMatch('/:root\.dark\s*\{[^{}]*--select-chevron:/');
});

/** @return list<string> every class token an element carries */
function phoneUiClassTokens(string $tag): array
{
    $tokens = [];
    foreach (phoneUiClassAttributes($tag) as $list) {
        foreach ((array) preg_split('/\s+/', trim($list)) as $token) {
            if (is_string($token) && $token !== '') {
                $tokens[] = $token;
            }
        }
    }

    return $tokens;
}

// Blocks, and the controls that are not blocks but end a line of prose all the
// same: the words inside a <button> are that button's label, not the sentence a
// link after it is set in.
/** Elements a link cannot be mid-sentence of, because they close the line before it. */
const PHONE_UI_FLOW_BREAKS = 'address|article|aside|blockquote|button|dd|details|div|dl|dt'
    .'|fieldset|figcaption|figure|footer|form|h1|h2|h3|h4|h5|h6|header|hr|label|li|main|nav'
    .'|ol|p|pre|section|select|summary|table|tbody|td|textarea|tfoot|th|thead|tr|ul';

/** @return array<string, float> every `--name: <length>` app.css defines, in px */
function phoneUiCssLengths(string $css): array
{
    $lengths = [];
    $found = PatternScan::sets('/(--[a-z0-9-]+):\s*([^;}]+)[;}]/', $css);
    foreach ($found as $property) {
        $px = phoneUiPixels($property[2], []);
        if ($px !== null) {
            $lengths[$property[1]] = $px;
        }
    }

    return $lengths;
}

/** @param array<string, float> $lengths */
function phoneUiPixels(string $value, array $lengths): ?float
{
    $value = trim($value);

    if (preg_match('/^var\(\s*(--[a-z0-9-]+)\s*\)$/', $value, $token) === 1) {
        return $lengths[$token[1]] ?? null;
    }
    if (preg_match('/^([0-9.]+)px$/', $value, $px) === 1) {
        return (float) $px[1];
    }

    // The root is 17px on a coarse pointer, so 16 understates every rem and
    // errs towards reporting a control rather than excusing one.
    return preg_match('/^([0-9.]+)rem$/', $value, $rem) === 1 ? ((float) $rem[1]) * 16 : null;
}

// A single line of text is 20px in this app's `text-sm`, so a control clears the
// floor on its own once it holds 12px of padding a side. That arithmetic is what
// separates .card-list-item, which is exactly 44, from a `py-2` pill at 36.
/** @return array<string, true> every class app.css itself sizes at or past the floor */
function phoneUiSelfSizingClasses(string $css): array
{
    $lengths = phoneUiCssLengths($css);
    $classes = [];

    $rules = PatternScan::sets('/(?<selector>[^{}]+)\{(?<body>[^{}]*)\}/s', $css);
    foreach ($rules as $rule) {
        if (! phoneUiRuleClearsTheFloor($rule['body'], $lengths)) {
            continue;
        }

        foreach (explode(',', $rule['selector']) as $selector) {
            $named = PatternScan::all('/\.([a-zA-Z_][a-zA-Z0-9_-]*)/', $selector);
            foreach ($named[1] as $class) {
                $classes[$class] = true;
            }
        }
    }

    return $classes;
}

/** @param array<string, float> $lengths */
function phoneUiRuleClearsTheFloor(string $body, array $lengths): bool
{
    if (preg_match('/(?<![a-z-])(?:min-)?height:\s*(?:44px|max\(100%,\s*44px\))/', $body) === 1) {
        return true;
    }

    if (preg_match('/(?<![a-z-])padding(?:-block)?:\s*([^;]+);/', $body, $padding) !== 1) {
        return false;
    }

    $vertical = phoneUiPixels((string) strtok(trim($padding[1]), ' '), $lengths);

    return $vertical !== null && $vertical * 2 + 20 >= 44;
}

/** @return float the height one line of text takes in an element carrying these classes */
function phoneUiLineBox(array $classes): float
{
    $scale = ['text-xs' => 16.0, 'text-base' => 24.0, 'text-lg' => 28.0, 'text-xl' => 28.0];
    foreach ($classes as $class) {
        if (isset($scale[$class])) {
            return $scale[$class];
        }
    }

    return 20.0;
}

/** @return float|null the px a Tailwind spacing step stands for, arbitrary values included */
function phoneUiSpacingStep(string $step): ?float
{
    if (preg_match('/^\[(\d+)px\]$/', $step, $arbitrary) === 1) {
        return (float) $arbitrary[1];
    }

    return preg_match('/^(\d+(?:\.\d+)?)$/', $step) === 1 ? ((float) $step) * 4 : null;
}

/** @return string|null the box a utility class draws, or null when it draws none */
function phoneUiUtilityBox(string $class, float $line): ?string
{
    $class = phoneUiReplaced('variant prefix', preg_replace('/^[a-z]+:/', '', $class));

    if (preg_match('/^(?:min-)?h-(.+)$/', $class, $height) === 1) {
        $px = phoneUiSpacingStep($height[1]);
        if ($px !== null && $px >= 44) {
            return 'a height utility';
        }
    }

    if (preg_match('/^(?:p|py|pt|pb)-(.+)$/', $class, $padding) === 1) {
        $px = phoneUiSpacingStep($padding[1]);
        if ($px !== null && $px * 2 + $line >= 44) {
            return 'a padding utility';
        }
    }

    return null;
}

/** @return string|null the box an element declares in its own class list */
function phoneUiDeclaredBox(string $tag, array $classes, array $selfSizing): ?string
{
    foreach ($classes as $class) {
        if (isset($selfSizing[$class])) {
            return 'the class .'.$class;
        }
    }

    $line = phoneUiLineBox($classes);
    foreach ($classes as $class) {
        $box = phoneUiUtilityBox($class, $line);
        if ($box !== null) {
            return $box;
        }
    }

    if (preg_match('/(?<![-:\w])style="([^"]*)"/s', $tag, $style) !== 1) {
        return null;
    }

    // A style attribute built in PHP is a box this guard cannot read, and a
    // guard that cannot read a value must not claim it is too small.
    if (str_contains($style[1], '{{')) {
        return 'a style the call site computes';
    }

    return preg_match('/(?<![a-z-])(?:min-height|padding|height)(?:-block)?:/', $style[1]) === 1
        ? 'an inline box'
        : null;
}

/** @return bool whether the link stands inside a table cell */
function phoneUiInsideTableCell(string $before): bool
{
    $opened = max((int) strrpos(' '.$before, '<td'), (int) strrpos(' '.$before, '<th'));
    $closed = max((int) strrpos(' '.$before, '</td'), (int) strrpos(' '.$before, '</th'));

    return $opened > 0 && $opened > $closed;
}

/** @return bool whether text of its own runs ahead of the link on the same line */
function phoneUiFollowsText(string $before): bool
{
    $from = 0;
    $blocks = PatternScan::allWithOffsets('#</?(?:'.PHONE_UI_FLOW_BREAKS.')\b[^>]*>#s', $before);
    if ($blocks[0] !== []) {
        $last = end($blocks[0]);
        $from = $last[1] + strlen($last[0]);
    }

    $lead = substr($before, $from);
    $lead = phoneUiReplaced('directives', preg_replace('/@[a-z]+\s*(\((?:[^()]|\((?:[^()]|\([^()]*\))*\))*\))?/s', '', $lead));
    $lead = phoneUiReplaced('sibling links', preg_replace('#<a\b[^>]*>.*?</a>#s', '', $lead));

    return trim(phoneUiReplaced('markup', preg_replace('/<[^>]*>/s', '', $lead))) !== '';
}

/**
 * @param  array<string, true>  $selfSizing
 * @return string|null the reason this link needs no halo, or null when its whole
 *                     height is one line of text and nothing gives it reach
 */
function phoneUiLinkReach(string $markup, array $element, array $selfSizing): ?string
{
    [$at, $tag, $inner] = $element;

    $box = phoneUiDeclaredBox($tag, phoneUiClassTokens($tag), $selfSizing);
    if ($box !== null) {
        return $box;
    }

    if (preg_match('/<(?:'.PHONE_UI_FLOW_BREAKS.')\b/s', $inner) === 1
        || preg_match('/@(?:livewire|include)\s*\(/s', $inner) === 1
        || str_contains($inner, '<x-')) {
        return 'a block it wraps';
    }

    $before = substr($markup, 0, $at);

    // app.css gives `td > a:only-child` a halo and its cell 44px of height, so a
    // link in a table takes its reach from the cell.
    if (phoneUiInsideTableCell($before)) {
        return 'the table cell around it';
    }

    // The floor exempts a link inside a sentence, and so does the guideline: it
    // cannot be 44px without breaking the line the sentence is set on.
    return phoneUiFollowsText($before) ? 'the sentence it sits in' : null;
}

it('gives every link that is only as tall as its own text a 44px reach', function (): void {
    // No floor covers an <a>, so a link the design draws as a line of text is
    // whatever its font measures: 17px for the /chains settlement date, 20px for
    // the login recovery-code link. The halo is what makes one reachable, and
    // nothing required it — the guard read `<button|summary>` and nothing else.
    $selfSizing = phoneUiSelfSizingClasses((string) file_get_contents(base_path('resources/css/app.css')));
    expect(count($selfSizing))->toBeGreaterThan(20, 'app.css yielded almost no self-sizing classes, so this guard is excusing links it cannot size');

    $views = phoneUiBladeViews();
    expect(count($views))->toBeGreaterThan(200, 'the Blade walk found almost nothing, so a clean answer here means nothing');

    $examined = 0;
    $textSized = [];
    foreach ($views as $view) {
        $markup = phoneUiMarkupOnly((string) file_get_contents($view));
        foreach (phoneUiElements($markup, 'a') as $element) {
            $examined++;
            if (phoneUiLinkReach($markup, $element, $selfSizing) === null) {
                $textSized[] = str_replace(base_path().'/', '', $view)
                    .':'.(substr_count($markup, "\n", 0, $element[0]) + 1);
            }
        }
    }

    expect($examined)->toBeGreaterThan(100, 'fewer links than this tree holds were read, so the walk stopped early');

    expect($textSized)->toBe([], implode("\n", [
        sprintf(
            '%d of %d links across %d views stand at the height of their own text — 17 to 20px — against a 44px floor.',
            count($textSized),
            $examined,
            count($views)
        ),
        'Each needs `tap-link`, which lays a 44px band over the link without moving it, or real height where',
        'a flush-stacked list leaves the band nothing to take but its neighbour\'s. Check first what else is',
        'stacked within 44px: two bands that overlap are resolved by markup order, not by which link was aimed at.',
        ...$textSized,
    ]));
});

it('lets the reader\'s text-size choice reach the type scale', function (): void {
    // Every --text-* token is a rem, so the scale follows the root — and
    // nothing moved the root, which is why Larger Text did nothing at all.
    $css = phoneUiUnlayeredCss();
    $at = strpos($css, '@supports (font: -apple-system-body)');

    expect($at)->toBeInt(
        'app.css never adopts the Dynamic Type body size, so the root stays at 16px'
    );

    // -apple-system-body is 13px on macOS, so an unscoped rule would shrink the
    // whole app in desktop Safari to fix a phone.
    expect(substr($css, max(0, (int) $at - 200), 200))->toContain('@media (pointer: coarse) {');

    // The shorthand carries a family and a line height too, and neither is the
    // one this app draws with.
    $block = substr($css, (int) $at, 300);
    expect($block)->toContain('font-family: var(--font-sans)')
        ->and($block)->toContain('line-height: 1.5');
});
