<?php

declare(strict_types=1);

use Modules\Core\Public\Support\PatternScan;
use Tests\Contracts\Support\BackendSourceFiles;

// A dispatch names its listener in a string, and nothing joins the two. A name
// nobody listens for throws nothing and warns nothing: the button is simply
// inert, and `assertDispatched('x')` still passes. The mirror case is as dead —
// a listener nothing dispatches is a feature that can never run.

/**
 * @var list<string> Alpine modifiers that trail an event name in `x-on:` / `@`
 */
const EVENT_CHANNEL_ALPINE_MODIFIERS = [
    'window', 'document', 'prevent', 'stop', 'outside', 'once', 'self',
    'debounce', 'throttle', 'passive', 'capture', 'camel', 'dot', 'away',
];

/**
 * Names the browser and the frameworks raise. Not part of the app's channel, so
 * a listener for one needs no dispatcher here. Not an exemption list and not
 * ratcheted: it is an external vocabulary, and naming more of it costs nothing.
 *
 * @var list<string>
 */
const EVENT_CHANNEL_BROWSER_EVENTS = [
    'abort', 'activate', 'animationend', 'beforeinstallprompt', 'beforeunload',
    'blur', 'cancel', 'change', 'click', 'close', 'contextmenu', 'copy', 'cut',
    'dblclick', 'drop', 'dragover', 'error', 'fetch', 'focus', 'focusin',
    'focusout', 'hashchange', 'input', 'install', 'invalid', 'keydown',
    'keypress', 'keyup', 'load', 'message', 'mousedown', 'mouseenter',
    'mouseleave', 'mousemove', 'mouseup', 'offline', 'online', 'pagehide',
    'pageshow', 'paste', 'pointercancel', 'pointerdown', 'pointerenter',
    'pointerleave', 'pointermove', 'pointerout', 'pointerover', 'pointerup',
    'gotpointercapture', 'lostpointercapture',
    'popstate', 'reset', 'resize', 'scroll', 'select',
    'storage', 'submit', 'toggle', 'touchcancel', 'touchend', 'touchmove',
    'touchstart', 'transitionend', 'unload', 'visibilitychange', 'wheel',
    'DOMContentLoaded',
    'alpine:init', 'alpine:initialized', 'alpine:initializing',
    'livewire:init', 'livewire:initialized', 'livewire:navigate',
    'livewire:navigated', 'livewire:navigating',
    // Livewire raises these on the file input itself, not through the app's
    // channel. `livewire-upload-error` is how a body refused by post_max_size
    // reaches a reader at all.
    'livewire-upload-start', 'livewire-upload-finish',
    'livewire-upload-error', 'livewire-upload-progress',
    'livewire-upload-cancel',
];

/**
 * Names that are legitimately one-sided because the other end sits outside this
 * repository. Every line carries its reason, and the list only shrinks: a test
 * below fails on a pin nothing needs any more.
 *
 * @return array<string, string> name => the half that lives here
 */
function eventChannelPinnedOneSided(): array
{
    return [
        // Flux's own JS opens the modal, and the handler is in
        // vendor/livewire/flux, which this scan does not read. `modal-close`
        // needs no pin: a bottom sheet in this repo binds that one too.
        'modal-show' => 'dispatch',

        // The mirror of open-sheet, kept as the bottom sheet's public way to be
        // closed from outside. BottomSheetClosesOnSaveTest pins the same
        // listener from the other direction.
        'close-sheet' => 'listen',

        // NativePHP Mobile raises these from the scanner activity on the native
        // side of the bridge, so nothing in this repo dispatches them.
        'native:Native\Mobile\Events\Scanner\CodeScanned' => 'listen',
        'native:Native\Mobile\Events\Scanner\ScannerCancelled' => 'listen',

        // Android's BiometricPrompt answers asynchronously: the native callback
        // raises these back into Livewire once it has stashed or failed to
        // stash the decrypted blob, so the dispatcher is the native side.
        'cold-start-recovered' => 'listen',
        'cold-start-failed' => 'listen',
    ];
}

/** @return list<string> production PHP, Blade and JS — the files that carry the channel */
function eventChannelFiles(): array
{
    $files = [];

    foreach ([base_path('Modules'), base_path('app'), base_path('resources')] as $root) {
        if (! is_dir($root)) {
            continue;
        }

        /** @var SplFileInfo $file */
        foreach (new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS),
        ) as $file) {
            $path = $file->getPathname();

            if (! $file->isFile() || preg_match('/\.(php|js)$/', $path) !== 1) {
                continue;
            }
            if (str_contains($path, '/tests/') || str_contains($path, '/Database/Migrations/')) {
                continue;
            }
            // Native-shell markup is NativePHP Mobile's own component dialect,
            // not HTML: `@press` there is a bottom-nav callback the native
            // runtime invokes, so reading it as an Alpine listener would be a
            // category error rather than a finding.
            if (str_contains($path, '/resources/views/native/')) {
                continue;
            }

            $files[] = $path;
        }
    }

    sort($files);

    return $files;
}

/** @return bool whether the file is PHP the tokenizer can read as code */
function eventChannelIsPhp(string $path): bool
{
    return str_ends_with($path, '.php') && ! str_ends_with($path, '.blade.php');
}

/**
 * @param  list<string>  $paths
 * @return array<string, string> 'Class::NAME' and 'self::NAME' => the literal
 */
function eventChannelConstants(array $paths): array
{
    $constants = [];

    foreach ($paths as $path) {
        if (! eventChannelIsPhp($path)) {
            continue;
        }

        $contents = (string) file_get_contents($path);
        $class = preg_match('/\b(?:class|enum|interface|trait)\s+([A-Za-z0-9_]+)/', $contents, $m) === 1
            ? $m[1]
            : basename($path, '.php');

        $pattern = '/const\s+(?:string\s+)?([A-Z][A-Z0-9_]*)\s*=\s*[\'"]([^\'"]*)[\'"]/';
        $hits = PatternScan::sets($pattern, $contents);

        foreach ($hits as $hit) {
            $constants[$class.'::'.$hit[1]] = $hit[2];
            $constants['self::'.$hit[1]] = $hit[2];
            $constants['static::'.$hit[1]] = $hit[2];
        }
    }

    return $constants;
}

/**
 * @param  array<string, string>  $constants
 * @return string|null the string behind a literal or a resolvable constant
 */
function eventChannelNameOf(array $constants, ?string $argument): ?string
{
    if ($argument === null || $argument === '') {
        return null;
    }
    if (preg_match('/^[\'"]([^\'"]*)[\'"]$/', $argument, $m) === 1) {
        return $m[1];
    }
    // An enum case reaches here too, and resolves to null: `dispatch(Audit::X)`
    // on a class's own private dispatch() names no browser event.
    if (preg_match('/^(?:[A-Za-z_\\\\][A-Za-z0-9_\\\\]*|self|static)::[A-Za-z][A-Za-z0-9_]*$/', $argument) !== 1) {
        return null;
    }

    $short = PatternScan::replace('/^.*\\\\/', '', $argument);

    return $constants[$argument] ?? $constants[$short] ?? null;
}

/** @return string the event a `x-on:` / `@` attribute binds, modifiers stripped */
function eventChannelAlpineName(string $raw): string
{
    $parts = explode('.', $raw);
    $name = (string) array_shift($parts);

    // A key modifier on keydown is an arbitrary key name, so nothing after the
    // first segment of a browser event can be read as part of the name.
    if (in_array($name, EVENT_CHANNEL_BROWSER_EVENTS, true)) {
        return $name;
    }

    foreach ($parts as $part) {
        if (in_array($part, EVENT_CHANNEL_ALPINE_MODIFIERS, true) || preg_match('/^\d+m?s?$/', $part) === 1) {
            continue;
        }

        $name .= '.'.$part;
    }

    return $name;
}

/**
 * The call's top-level arguments as source text, or [] when the name at $index
 * opens no call.
 *
 * @param  list<array{0:int,1:string,2:int}|string>  $tokens
 * @return list<string>
 */
function eventChannelArguments(array $tokens, int $index): array
{
    if (($tokens[$index + 1] ?? null) !== '(') {
        return [];
    }

    $depth = 0;
    $arguments = [];
    $current = '';

    for ($i = $index + 1, $count = count($tokens); $i < $count; $i++) {
        $text = is_array($tokens[$i]) ? $tokens[$i][1] : $tokens[$i];

        if ($text === '(' || $text === '[') {
            $depth++;
            if ($depth === 1) {
                continue;
            }
        } elseif ($text === ')' || $text === ']') {
            $depth--;
            if ($depth === 0) {
                $arguments[] = trim($current);

                return $arguments;
            }
        } elseif ($text === ',' && $depth === 1) {
            $arguments[] = trim($current);
            $current = '';

            continue;
        }

        $current .= $text;
    }

    return $arguments;
}

/**
 * @param  list<array{0:int,1:string,2:int}|string>  $tokens
 * @return string|null the argument that names the event, by call shape
 */
function eventChannelEventArgument(array $tokens, int $index, string $callee): ?string
{
    $arguments = eventChannelArguments($tokens, $index);
    // dispatchTo() addresses a component first, so its event name is second.
    $wanted = $callee === 'dispatchto' ? 1 : 0;

    return $arguments[$wanted] ?? null;
}

/** @return list<array{0:string,1:int}> code-only tokens are what keeps prose out */
function eventChannelPhpHits(string $path, array $constants, bool $listening): array
{
    $tokens = array_values(array_filter(
        BackendSourceFiles::codeTokens($path),
        static fn (array|string $token): bool => ! is_array($token) || $token[0] !== T_WHITESPACE,
    ));

    $hits = [];

    foreach ($tokens as $index => $token) {
        if (! is_array($token)) {
            continue;
        }

        $short = PatternScan::replace('/^.*\\\\/', '', $token[1]);

        if ($listening && $short === 'On' && in_array($token[0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)) {
            $name = eventChannelNameOf($constants, eventChannelArguments($tokens, $index)[0] ?? null);
            if ($name !== null) {
                $hits[] = [$name, $token[2]];
            }

            continue;
        }

        if ($listening || $token[0] !== T_STRING) {
            continue;
        }

        $callee = strtolower($token[1]);
        if (! in_array($callee, ['dispatch', 'dispatchto', 'dispatchself'], true)) {
            continue;
        }

        $name = eventChannelNameOf($constants, eventChannelEventArgument($tokens, $index, $callee));
        if ($name !== null) {
            $hits[] = [$name, $token[2]];
        }
    }

    return $listening ? [...$hits, ...eventChannelListenersArray($tokens)] : $hits;
}

/**
 * @param  list<array{0:int,1:string,2:int}|string>  $tokens
 * @return list<array{0:string,1:int}> the keys of a `protected $listeners = [...]`
 */
function eventChannelListenersArray(array $tokens): array
{
    $hits = [];

    foreach ($tokens as $index => $token) {
        if (! is_array($token) || $token[0] !== T_VARIABLE || $token[1] !== '$listeners') {
            continue;
        }

        $depth = 0;
        for ($i = $index + 1, $count = count($tokens); $i < $count; $i++) {
            $text = is_array($tokens[$i]) ? $tokens[$i][1] : $tokens[$i];

            if ($text === '[') {
                $depth++;
            } elseif ($text === ']') {
                $depth--;
                if ($depth === 0) {
                    break;
                }
            } elseif ($text === ';' && $depth === 0) {
                break;
            }

            $key = $tokens[$i];
            $next = $tokens[$i + 1] ?? null;
            $isArrow = is_array($next) && $next[0] === T_DOUBLE_ARROW;
            if ($depth === 1 && $isArrow && is_array($key) && $key[0] === T_CONSTANT_ENCAPSED_STRING) {
                $hits[] = [trim($key[1], "'\""), $key[2]];
            }
        }
    }

    return $hits;
}

/**
 * A Blade listener may name its event through an @use'd class constant, which
 * is the house pattern for keeping the two halves from drifting. Reading only
 * the raw attribute would make that spelling invisible to this guard.
 *
 * @param  array<string, string>  $constants
 * @return list<array{0:string,1:int}>
 */
function eventChannelBladeConstantHits(string $contents, array $constants): array
{
    $pattern = '/x-on:\{\{\s*((?:[A-Za-z_\\\\][A-Za-z0-9_\\\\]*)::[A-Za-z][A-Za-z0-9_]*)\s*\}\}((?:\.[A-Za-z0-9_-]+)*)/';
    $matches = PatternScan::setsWithOffsets($pattern, $contents);

    $hits = [];

    foreach ($matches as $match) {
        $name = eventChannelNameOf($constants, $match[1][0]);
        if ($name === null) {
            continue;
        }

        $hits[] = [
            eventChannelAlpineName($name.$match[2][0]),
            substr_count($contents, "\n", 0, $match[0][1]) + 1,
        ];
    }

    return $hits;
}

/**
 * Blade and JS cannot be tokenized as PHP, so comments come out first: a
 * dispatch described in prose is not a dispatch.
 */
function eventChannelMarkupCode(string $path): string
{
    // Blanked, never deleted: a comment that took its newlines with it would
    // move every line number this guard reports below it.
    $blank = static fn (array $m): string => str_repeat("\n", substr_count($m[0], "\n"));

    $contents = (string) file_get_contents($path);
    $contents = PatternScan::replaceCallback('/\{\{--.*?--\}\}/s', $blank, $contents);
    $contents = PatternScan::replaceCallback('#/\*.*?\*/#s', $blank, $contents);

    // `(?<![:\w])` keeps the `//` of an https:// URL out of it.
    return PatternScan::replace('#(?<![:\w])//[^\n]*#', '', $contents);
}

/**
 * @param  array<string, string>  $constants
 * @return list<array{0:string,1:int}>
 */
function eventChannelMarkupHits(string $path, bool $listening, array $constants): array
{
    $contents = eventChannelMarkupCode($path);
    $blade = str_ends_with($path, '.blade.php');

    $patterns = $listening
        ? [
            '/\b(?:window|document)\s*\.\s*addEventListener\s*\(\s*[\'"]([^\'"]+)[\'"]/s',
            '/(?:Livewire|@this|\$wire)\s*\.\s*on\s*\(\s*[\'"]([^\'"]+)[\'"]/s',
        ]
        : [
            '/\bdispatchTo\s*\(\s*[\'"][^\'"]*[\'"]\s*,\s*[\'"]([^\'"]+)[\'"]/s',
            '/\bdispatch(?:Self)?\s*\(\s*[\'"]([^\'"]+)[\'"]/s',
            '/\bnew\s+(?:Custom)?Event\s*\(\s*[\'"]([^\'"]+)[\'"]/s',
        ];

    if ($listening && $blade) {
        $patterns[] = '/x-on:([A-Za-z0-9_.:\\\\-]+)\s*=/';
        $patterns[] = '/(?<![\w:.@-])@([a-z][A-Za-z0-9_.:-]*)\s*=\s*["\']/';
    }

    $hits = $listening && $blade ? eventChannelBladeConstantHits($contents, $constants) : [];

    foreach ($patterns as $pattern) {
        $matches = PatternScan::setsWithOffsets($pattern, $contents);

        foreach ($matches as $match) {
            $name = $listening && $blade ? eventChannelAlpineName($match[1][0]) : $match[1][0];
            $hits[] = [$name, substr_count($contents, "\n", 0, $match[0][1]) + 1];
        }
    }

    return $hits;
}

/**
 * @param  list<string>  $paths
 * @param  array<string, string>  $constants
 * @return array<string, list<string>> name => the places that reach it
 */
function eventChannelScan(array $paths, array $constants, bool $listening): array
{
    $found = [];

    foreach ($paths as $path) {
        $hits = eventChannelIsPhp($path)
            ? eventChannelPhpHits($path, $constants, $listening)
            : eventChannelMarkupHits($path, $listening, $constants);

        foreach ($hits as [$name, $line]) {
            $found[$name][] = str_replace(base_path().'/', '', $path).':'.$line;
        }
    }

    return $found;
}

/**
 * @param  array<string, list<string>>  $from
 * @param  array<string, list<string>>  $to
 * @return list<string> one entry per name in $from that $to never answers
 */
function eventChannelOrphans(array $from, array $to, string $half): array
{
    $pinned = eventChannelPinnedOneSided();
    $orphans = [];

    foreach ($from as $name => $places) {
        if (isset($to[$name]) || in_array($name, EVENT_CHANNEL_BROWSER_EVENTS, true)) {
            continue;
        }
        if (($pinned[$name] ?? null) === $half) {
            continue;
        }

        $orphans[] = "'{$name}' at ".implode(', ', array_slice($places, 0, 3));
    }

    sort($orphans);

    return $orphans;
}

it('has a listener for every event it dispatches', function (): void {
    $files = eventChannelFiles();
    expect($files)->not->toBeEmpty();

    $constants = eventChannelConstants($files);

    expect(eventChannelOrphans(
        eventChannelScan($files, $constants, listening: false),
        eventChannelScan($files, $constants, listening: true),
        'dispatch',
    ))->toBe([], "Nothing listens for these, so the dispatch is inert and a test that\n".
        "asserts it passes anyway. Wire the listener or drop the dispatch — and\n".
        "if a package outside this repo handles it, pin it in\n".
        'eventChannelPinnedOneSided() with the reason. Offenders:');
});

it('has a dispatcher for every event it listens for', function (): void {
    $files = eventChannelFiles();
    expect($files)->not->toBeEmpty();

    $constants = eventChannelConstants($files);

    expect(eventChannelOrphans(
        eventChannelScan($files, $constants, listening: true),
        eventChannelScan($files, $constants, listening: false),
        'listen',
    ))->toBe([], "Nothing dispatches these, so the handler can never run and whatever it\n".
        "was meant to refresh never refreshes. Dispatch the name or drop the\n".
        "listener — and if something outside this repo raises it, pin it in\n".
        'eventChannelPinnedOneSided() with the reason. Offenders:');
});

it('carries no pin for an event that has both halves again', function (): void {
    // The ratchet. A pin outlives its reason the moment the other half lands in
    // this repo, and pins nobody needs are how a guard stops meaning anything.
    $files = eventChannelFiles();
    $constants = eventChannelConstants($files);

    $dispatches = eventChannelScan($files, $constants, listening: false);
    $listeners = eventChannelScan($files, $constants, listening: true);
    $stale = [];

    foreach (eventChannelPinnedOneSided() as $name => $half) {
        $present = $half === 'dispatch' ? $dispatches : $listeners;
        $other = $half === 'dispatch' ? $listeners : $dispatches;

        if (! isset($present[$name])) {
            $stale[] = "'{$name}' is pinned as {$half}-only, but nothing has that half now";
        } elseif (isset($other[$name])) {
            $stale[] = "'{$name}' is pinned as {$half}-only, but this repo now has both halves";
        }
    }

    expect($stale)->toBe([], "Delete the pin. This list only shrinks:\n  ".implode("\n  ", $stale));
});

it('sees a dispatch that reaches no listener', function (): void {
    $planted = tempnam(sys_get_temp_dir(), 'event-channel').'.php';
    file_put_contents($planted, <<<'PHP'
        <?php
        final class PlantedDispatch
        {
            public function run(): void
            {
                $this->dispatch('toast', message: 'heard');
                $this->dispatch('nobody-is-listening-for-this');
                $this->events->dispatch(new SomethingHappened(1));
            }
        }
        PHP);

    try {
        $found = eventChannelScan([$planted], [], listening: false);
    } finally {
        @unlink($planted);
    }

    expect(array_keys($found))->toBe(['toast', 'nobody-is-listening-for-this']);
    expect(eventChannelOrphans($found, ['toast' => ['somewhere']], 'dispatch'))
        ->toBe(["'nobody-is-listening-for-this' at ".$planted.':7']);
});

it('sees a listener that no dispatch reaches', function (): void {
    $planted = tempnam(sys_get_temp_dir(), 'event-channel').'.blade.php';
    file_put_contents($planted, <<<'BLADE'
        <div
            x-on:keydown.escape.window="close()"
            x-on:nobody-dispatches-this.window="refresh()"
            x-on:buffer-editor:saved.window="open = false"
        ></div>
        BLADE);

    try {
        $found = eventChannelScan([$planted], [], listening: true);
    } finally {
        @unlink($planted);
    }

    // The key modifier collapses to `keydown`; a colon in the name survives.
    expect(array_keys($found))->toBe(['keydown', 'nobody-dispatches-this', 'buffer-editor:saved']);
    expect(eventChannelOrphans($found, ['buffer-editor:saved' => ['somewhere']], 'listen'))
        ->toBe(["'nobody-dispatches-this' at ".$planted.':3']);
});

it('reads no event name out of a string, a comment or a regex', function (): void {
    // The false-positive half. A scanner that matched prose would be worse than
    // no scanner: every line below would have to be pinned to keep it green.
    $planted = tempnam(sys_get_temp_dir(), 'event-channel-prose').'.php';
    file_put_contents($planted, <<<'PHP'
        <?php
        // The panel used to dispatch('ghost-from-a-comment') before this landed.
        /** @see dispatch('ghost-from-a-doc') */
        final class PlantedProse
        {
            public string $prose = "dispatch('ghost-from-a-string')";

            public function run(): void
            {
                preg_match('/dispatch\(\s*[\'"](ghost-from-a-regex)[\'"]/', $this->prose);
                $this->dispatch('really-dispatched');
            }
        }
        PHP);

    try {
        $found = eventChannelScan([$planted], [], listening: false);
    } finally {
        @unlink($planted);
    }

    expect(array_keys($found))->toBe(['really-dispatched']);
});

it('reads no listener out of a Blade comment', function (): void {
    $planted = tempnam(sys_get_temp_dir(), 'event-channel-blade-prose').'.blade.php';
    file_put_contents($planted, <<<'BLADE'
        {{-- The host used to carry x-on:ghost-from-a-blade-comment.window here. --}}
        <div x-on:really-listened.window="go()"></div>
        BLADE);

    try {
        $found = eventChannelScan([$planted], [], listening: true);
    } finally {
        @unlink($planted);
    }

    expect(array_keys($found))->toBe(['really-listened']);
});
