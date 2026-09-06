<?php

declare(strict_types=1);
use Illuminate\Contracts\Translation\Translator;
use Modules\Core\Public\Support\PatternScan;

/**
 * @link ../../.docs/conventions/which-actions-ask-before-they-act.md
 */

// Pinned rather than derived: no scan can tell a write that can be undone from
// one that cannot. Each row is an action read and judged irreversible, and the
// list is what stops a gate being dropped in a refactor. Adding a row means
// having done the reading; the doc page holds the reasoning for each.
const ACTIONS_THAT_ASK_FIRST = [
    'Modules/Categorization/Resources/views/livewire/rules-page.blade.php' => [
        'triggerReapply' => 'categorization::rules.reapply_confirm',
    ],
    'Modules/Auth/Resources/views/livewire/recovery-codes-section.blade.php' => [
        'regenerate' => 'auth::recovery_codes.settings.regenerate_confirm',
    ],
    'Modules/Tax/Resources/views/components/tax-tag-popover.blade.php' => [
        'applyBatchTag' => 'tax::picker.batch_confirm',
    ],
    'Modules/Migration/Resources/views/livewire/preview-migration.blade.php' => [
        'discard' => 'migration::preview.discard_confirm',
    ],
    'Modules/DevMode/Resources/views/livewire/audit-log-page.blade.php' => [
        'truncateAll' => 'dev::audit.clear_all_confirm',
    ],
];

it('puts a question in front of every action that cannot be taken back', function (): void {
    $missing = [];

    foreach (ACTIONS_THAT_ASK_FIRST as $file => $actions) {
        $source = (string) file_get_contents(base_path($file));

        foreach ($actions as $method => $key) {
            // The two attributes have to sit on the SAME element, so the match
            // runs from the wire:click to the end of its tag rather than over
            // the whole file — a confirm three controls away is not a gate.
            // choice() reads the same line as get(); a question naming a count
            // has to be read with it or the reader is shown both its arms.
            $found = preg_match(
                '~wire:click="'.preg_quote($method, '~').'"[^<>]*wire:confirm="\{\{ Lang::(?:get|choice)\(\''.preg_quote($key, '~').'\'~s',
                $source
            ) === 1;

            if (! $found) {
                $missing[] = $file.' — '.$method.'() is not gated by '.$key;
            }
        }
    }

    expect($missing)->toBe([], "These rewrite or destroy something no screen can put back, and must ask first:\n  ".implode("\n  ", $missing));
});

it('gives every one of those questions a line in all 26 languages', function (): void {
    $translator = app(Translator::class);
    $locales = [];

    foreach ((array) glob(base_path('Modules/Core/Resources/lang/*'), GLOB_ONLYDIR) as $dir) {
        $locales[] = basename((string) $dir);
    }

    // The description says 26. A glob answering with fewer would leave the
    // sentence describing a walk nobody ran.
    expect(count($locales))->toBeGreaterThanOrEqual(
        26,
        'Found '.count($locales).' shipped locales; this rule claims all 26, so either a locale was dropped or the glob read nothing.',
    );

    $silent = [];

    foreach (ACTIONS_THAT_ASK_FIRST as $actions) {
        foreach ($actions as $key) {
            foreach ($locales as $locale) {
                $line = $translator->get($key, [], $locale, false);

                if (! is_string($line) || $line === $key) {
                    $silent[] = $locale.' → '.$key;
                }
            }
        }
    }

    expect($silent)->toBe([], "A confirmation with no line behind it shows the reader a key instead of a question:\n  ".implode("\n  ", $silent));
});

// The pinned list answers "must this action ask?", which takes a reader's
// judgement and cannot be derived. The rules below answer "is this one of the
// three shapes?", which takes none — and nothing asked it, which is how a
// hand-rolled browser dialog sat on the audit log page unseen.
const CONFIRMATION_SHAPE_BLADE_FLOOR = 200;

const CONFIRMATION_SHAPE_HANDLER_FLOOR = 50;

// wire:confirm and the strip both hang off a wire:click. Neither can reach a
// method an Alpine handler calls on $wire itself, so a destructive verb spelled
// that way is ungated by construction, whatever else the element carries.
const CONFIRMATION_SHAPE_DESTRUCTIVE_VERBS = [
    'truncate', 'delete', 'destroy', 'wipe', 'purge', 'erase', 'discard', 'revoke', 'unpair', 'forget',
];

// The event names are a closed list rather than a wildcard so that a Blade
// directive — @if, @include, @class — cannot be read as an Alpine handler.
const CONFIRMATION_SHAPE_HANDLER_PATTERN = '~(?:x-on:|@)(?:click|dblclick|mousedown|pointerdown|touchstart|keydown|keyup|keypress|change|input|submit|close)[\w.:-]*="([^"]*)"~s';

/** @return list<string> absolute paths to every Blade template a confirmation can be spelled in */
function confirmationShapeBladeFiles(): array
{
    $files = [];

    foreach (['Modules', 'resources'] as $dir) {
        $root = base_path($dir);
        if (! is_dir($root)) {
            continue;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
        );
        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            if ($file->isFile() && str_ends_with($file->getPathname(), '.blade.php')) {
                $files[] = $file->getPathname();
            }
        }
    }

    sort($files);

    return $files;
}

/** @return string the repo-relative file and line an offset falls on */
function confirmationShapeWhere(string $path, string $source, int $offset): string
{
    return str_replace(base_path().'/', '', $path).':'.(substr_count(substr($source, 0, $offset), "\n") + 1);
}

function confirmationShapeIsDestructive(string $method): bool
{
    foreach (CONFIRMATION_SHAPE_DESTRUCTIVE_VERBS as $verb) {
        if (str_starts_with(strtolower($method), $verb)) {
            return true;
        }
    }

    return false;
}

/** @return list<string> every method the pinned list has already judged irreversible */
function confirmationShapePinnedMethods(): array
{
    $methods = [];

    foreach (ACTIONS_THAT_ASK_FIRST as $actions) {
        foreach (array_keys($actions) as $method) {
            $methods[] = $method;
        }
    }

    return $methods;
}

/** @return list<string> every confirmation one template spells outside the three shapes */
function confirmationShapeHandRolledIn(string $path, string $source): array
{
    $found = [];

    foreach (PatternScan::setsWithOffsets('~window\.confirm\b~', $source) as $match) {
        $found[] = confirmationShapeWhere($path, $source, $match[0][1]).' — window.confirm';
    }

    foreach (PatternScan::setsWithOffsets(CONFIRMATION_SHAPE_HANDLER_PATTERN, $source) as $handler) {
        if (PatternScan::setsWithOffsets('~(?<![\w.$])confirm\s*\(~', $handler[1][0]) === []) {
            continue;
        }

        $found[] = confirmationShapeWhere($path, $source, $handler[0][1]).' — confirm() in an Alpine handler';
    }

    return $found;
}

/**
 * @param  list<string>  $pinned
 * @return array{handlers: int, ungated: list<string>}
 */
function confirmationShapeUngatedIn(string $path, string $source, array $pinned): array
{
    $handlers = 0;
    $ungated = [];

    foreach (PatternScan::setsWithOffsets(CONFIRMATION_SHAPE_HANDLER_PATTERN, $source) as $handler) {
        $handlers++;

        foreach (PatternScan::setsWithOffsets('~\$wire\.(?:call\(\s*[\'"])?([A-Za-z_]\w*)~', $handler[1][0]) as $call) {
            $method = $call[1][0];

            if (! in_array($method, $pinned, true) && ! confirmationShapeIsDestructive($method)) {
                continue;
            }

            $ungated[] = confirmationShapeWhere($path, $source, $handler[0][1]).' — $wire.'.$method.'()';
        }
    }

    return ['handlers' => $handlers, 'ungated' => $ungated];
}

it('spells no confirmation a fourth way', function (): void {
    $files = confirmationShapeBladeFiles();
    $denominator = count($files);

    expect($denominator > CONFIRMATION_SHAPE_BLADE_FLOOR)
        ->toBe(true, 'Read '.$denominator.' Blade templates, too few to have covered the product.');

    $handRolled = [];

    foreach ($files as $path) {
        $handRolled = array_merge($handRolled, confirmationShapeHandRolledIn($path, (string) file_get_contents($path)));
    }

    expect($handRolled)->toBe([], implode("\n", [
        'Read '.$denominator.' Blade templates. A browser confirm() is not the fourth shape, it is no shape at all:',
        ...$handRolled,
        '',
        'Use x-core::confirm-strip for a row action, wire:confirm for a single',
        'high-stakes button, or a typed phrase for an account-level one.',
    ]));
});

it('reaches no destructive action through an Alpine handler, where none of the three can gate it', function (): void {
    $files = confirmationShapeBladeFiles();
    $pinned = confirmationShapePinnedMethods();
    $handlers = 0;
    $ungated = [];

    foreach ($files as $path) {
        $read = confirmationShapeUngatedIn($path, (string) file_get_contents($path), $pinned);
        $handlers += $read['handlers'];
        $ungated = array_merge($ungated, $read['ungated']);
    }

    expect($handlers > CONFIRMATION_SHAPE_HANDLER_FLOOR)
        ->toBe(true, 'Read '.$handlers.' Alpine handlers across '.count($files).' Blade templates, too few to have covered the product.');

    expect($ungated)->toBe([], implode("\n", [
        'Read '.$handlers.' Alpine handlers across '.count($files).' Blade templates. These call a destructive method where no shape can reach it:',
        ...$ungated,
        '',
        'Move the call to wire:click so wire:confirm or the strip can gate it.',
    ]));
});

it('reads a hand-rolled confirmation and a destructive call no shape can gate, and leaves a Blade directive alone', function (): void {
    $blade = <<<'BLADE'
        <div @if ($ready) data-ready @endif>
            <button x-on:click="if (confirm('Sure?')) $wire.deleteAll()">Delete</button>
            <button x-on:click="$wire.refresh()">Refresh</button>
        </div>
        BLADE;

    expect(confirmationShapeHandRolledIn('a.blade.php', $blade))->toBe(
        ['a.blade.php:2 — confirm() in an Alpine handler'],
        'a browser confirm() is not the fourth shape, it is no shape at all',
    );

    $read = confirmationShapeUngatedIn('a.blade.php', $blade, ['triggerReapply']);

    expect($read['handlers'])->toBe(
        2,
        'the two x-on:click handlers are read and the @if is not one; the event names are a closed list so a Blade directive cannot be read as a handler',
    );

    expect($read['ungated'])->toBe(
        ['a.blade.php:2 — $wire.deleteAll()'],
        'a destructive verb called on $wire is ungated by construction, whatever else the element carries',
    );

    expect(confirmationShapeIsDestructive('deleteAll'))->toBeTrue('the verb list is what makes this derived rather than pinned');
    expect(confirmationShapeIsDestructive('deferReminder'))->toBeFalse('a verb that only starts like one of them undoes nothing');
});
