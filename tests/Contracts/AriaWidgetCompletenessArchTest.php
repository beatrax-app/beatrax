<?php

declare(strict_types=1);

/*
 * A control that takes an ARIA role instead of a native element has to carry
 * the state that native element would have supplied for free. A <progress>
 * reports its value without being asked; a div with role="progressbar" and no
 * aria-valuenow announces nothing. An <input type="radio"> reports checked; a
 * button with role="radio" and no aria-checked announces a selection that is
 * always absent.
 *
 * This replaces Web:S6819 for Blade, which is switched off in
 * sonar-project.properties. That rule asks a narrower question — is there a
 * native element for this role — and its answer is wrong for every one of the
 * 57 findings left after the calendar grid became a real table: an Alpine
 * popover is not <dialog>, a chip toolbar is not <fieldset>, a Livewire
 * picker is not <select>, and a CSS-drawn dot is not <img>. It also never
 * asks the question that actually decides whether the widget works, which is
 * the one below.
 *
 * Scope: it checks presence, not correctness of the value. An aria-checked
 * that is always "false" would pass here and is not something a template can
 * be read for.
 */

/**
 * @return list<string>
 */
function ariaCompletenessBladeFiles(): array
{
    $root = dirname(__DIR__, 2);
    $files = [];

    foreach (['Modules', 'resources'] as $dir) {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root.'/'.$dir, FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            if ($file instanceof SplFileInfo && str_ends_with($file->getFilename(), '.blade.php')) {
                $files[] = $file->getPathname();
            }
        }
    }

    sort($files);

    return $files;
}

/**
 * Every opening tag in the source, as [attributes, byte offset].
 *
 * The attribute run is matched quote-aware because Alpine expressions put
 * ">" inside attribute values (x-show="a > b"), and a naive [^>]* would cut
 * the tag in half there and lose the attributes after it.
 *
 * @return list<array{0: string, 1: int}>
 */
function ariaCompletenessOpenTags(string $source): array
{
    preg_match_all(
        '~<[a-zA-Z][\w:.-]*((?:[^>"\']|"[^"]*"|\'[^\']*\')*)>~s',
        $source,
        $matches,
        PREG_SET_ORDER | PREG_OFFSET_CAPTURE
    );

    $tags = [];
    foreach ($matches as $match) {
        $tags[] = [$match[1][0], (int) $match[0][1]];
    }

    return $tags;
}

function ariaCompletenessHasName(string $attributes): bool
{
    return str_contains($attributes, 'aria-label=')
        || str_contains($attributes, 'aria-labelledby=')
        || str_contains($attributes, ':aria-label=');
}

// What each role must carry, and why. The message names the state a screen
// reader would otherwise never hear.
/**
 * @return array<string, array{check: callable(string): bool, missing: string}>
 */
function ariaCompletenessRules(): array
{
    $named = static fn (string $a): bool => ariaCompletenessHasName($a);
    $has = static fn (string ...$needles): callable => static function (string $a) use ($needles): bool {
        foreach ($needles as $needle) {
            if (str_contains($a, $needle)) {
                return true;
            }
        }

        return false;
    };

    return [
        'dialog' => ['check' => $named, 'missing' => 'an accessible name (aria-label/aria-labelledby)'],
        'group' => ['check' => $named, 'missing' => 'an accessible name — an unnamed group conveys nothing a plain div would not'],
        'img' => ['check' => $named, 'missing' => 'an accessible name — role="img" without one announces an empty image'],
        'listbox' => ['check' => $named, 'missing' => 'an accessible name'],
        'radiogroup' => ['check' => $named, 'missing' => 'an accessible name'],
        'option' => ['check' => $has('aria-selected'), 'missing' => 'aria-selected — the selection is never announced without it'],
        'radio' => ['check' => $has('aria-checked'), 'missing' => 'aria-checked — the selection is never announced without it'],
        'progressbar' => ['check' => $has('aria-valuenow', 'aria-valuetext'), 'missing' => 'aria-valuenow (or aria-valuetext when indeterminate)'],
    ];
}

it('gives every ARIA-roled widget the state its native element would have carried', function (): void {
    $rules = ariaCompletenessRules();
    $offenders = [];

    foreach (ariaCompletenessBladeFiles() as $path) {
        $source = (string) file_get_contents($path);

        foreach (ariaCompletenessOpenTags($source) as [$attributes, $offset]) {
            if (preg_match('~\brole="([a-z]+)"~', $attributes, $found) !== 1) {
                continue;
            }

            $role = $found[1];
            if (! isset($rules[$role])) {
                continue;
            }

            // A role on a hidden element is not announced at all, so there is
            // no name or state for it to be missing.
            if (str_contains($attributes, 'aria-hidden="true"')) {
                continue;
            }

            if (($rules[$role]['check'])($attributes)) {
                continue;
            }

            $line = substr_count(substr($source, 0, $offset), "\n") + 1;
            $offenders[] = sprintf('%s:%d — role="%s" without %s', $path, $line, $role, $rules[$role]['missing']);
        }
    }

    expect($offenders)->toBe([], "An ARIA role must carry the state its native element implies. Offenders:\n  ".implode("\n  ", $offenders));
});

// A radio outside a radiogroup, or an option outside a listbox, is announced
// as a lone control with no set to be one of — "selected" with nothing to be
// selected among. Native <input type="radio"> gets this from its name
// attribute and <option> from its <select>; a roled widget only gets it from
// the container being there.
it('encloses every roled radio and option in the container that gives it meaning', function (): void {
    $containers = ['radio' => 'radiogroup', 'option' => 'listbox'];
    $offenders = [];

    foreach (ariaCompletenessBladeFiles() as $path) {
        $source = (string) file_get_contents($path);

        foreach ($containers as $child => $container) {
            if (! str_contains($source, 'role="'.$child.'"')) {
                continue;
            }

            if (! str_contains($source, 'role="'.$container.'"')) {
                $offenders[] = sprintf('%s — has role="%s" but no role="%s" around it', $path, $child, $container);
            }
        }
    }

    expect($offenders)->toBe([], "A roled child needs its container role in the same template. Offenders:\n  ".implode("\n  ", $offenders));
});
