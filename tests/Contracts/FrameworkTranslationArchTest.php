<?php

declare(strict_types=1);

use Modules\Core\Public\Enums\Locale;

/**
 * @link ../../.docs/conventions/invariants-from-shipped-failures.md#framework-translations-missing-from-every-locale
 */

/** @return list<string> the framework lang files every locale must carry */
function frameworkTranslationFiles(): array
{
    return ['validation.php', 'auth.php', 'passwords.php', 'pagination.php'];
}

/** @return list<string> every file name any locale directory under lang/ actually holds */
function frameworkTranslationFilesOnDisk(): array
{
    $names = [];

    foreach ((array) glob(base_path('lang/*/*.php')) as $path) {
        $names[basename((string) $path)] = true;
    }

    $found = array_keys($names);
    sort($found);

    return $found;
}

// A hand-written list is a claim about the framework's own lang directory, and
// nothing re-checks it: a fifth file shipped into one locale would be carried
// by that locale and by no other, with this rule reporting parity.
it('names every framework lang file the tree actually ships', function (): void {
    $onDisk = frameworkTranslationFilesOnDisk();

    expect($onDisk)->not->toBeEmpty('lang/ holds no locale file at all, so both rules below read nothing.');

    $unlisted = array_values(array_diff($onDisk, frameworkTranslationFiles()));
    $stale = array_values(array_diff(frameworkTranslationFiles(), $onDisk));

    expect($unlisted)->toBe([], implode("\n", [
        'lang/ carries these files and frameworkTranslationFiles() does not name them, so nothing',
        'checks that every locale has them:',
        ...$unlisted,
    ]));

    expect($stale)->toBe([], implode("\n", [
        'frameworkTranslationFiles() names these and no locale directory holds one. The entry',
        'requires a file the framework no longer publishes, so it excuses nothing and asks for nothing:',
        ...$stale,
    ]));
});

it('ships the framework lang files in every supported locale', function (): void {
    $cases = Locale::cases();

    // Read before the verdict: with one case the loop below asks nothing at
    // all. The floor sits far under today's 26.
    expect(count($cases))->toBeGreaterThan(
        10,
        'Locale declares '.count($cases).' cases, which is too few to be the supported set.'
    );

    $missing = [];
    $checked = 0;

    foreach ($cases as $case) {
        if ($case->value === Locale::DEFAULT) {
            // English is the framework's own default and needs no override.
            continue;
        }

        foreach (frameworkTranslationFiles() as $file) {
            $checked++;
            $path = base_path("lang/{$case->value}/{$file}");

            if (! is_file($path)) {
                $missing[] = "{$case->value}/{$file}";
            }
        }
    }

    expect($checked)->toBeGreaterThan(
        40,
        'only '.$checked.' locale/file pairs were checked, which is too few to be 25 locales.'
    );

    expect($missing)->toBe([], "Laravel's own messages fall back to English in these:\n  ".implode("\n  ", $missing));
});

it('actually resolves a validation message in each locale, rather than falling through to English', function (): void {
    $original = app()->getLocale();
    $untranslated = [];
    $resolved = 0;

    try {
        // Resolved before the loop, not inside it. Locale declares four cases
        // ahead of En, and each of those compared its message against a null
        // English that had not been read yet — so cs, da, de and et could not
        // be reported however they rendered.
        app()->setLocale(Locale::DEFAULT);
        $english = validator(['field' => null], ['field' => 'required'])->errors()->first('field');

        foreach (Locale::cases() as $case) {
            if ($case->value === Locale::DEFAULT) {
                continue;
            }

            app()->setLocale($case->value);
            $resolved++;

            $message = validator(['field' => null], ['field' => 'required'])
                ->errors()
                ->first('field');

            // A locale whose message is byte-identical to English has no file of
            // its own and fell through — which is the failure this guards.
            if ($message === $english) {
                $untranslated[] = $case->value;
            }
        }
    } finally {
        // Restored here rather than after the assertions: a failing expectation
        // throws, and every later test in this worker would then run under
        // whichever locale the loop stopped on.
        app()->setLocale($original);
    }

    expect($english)->not->toBe('', 'the English validation message resolved empty, so every comparison below was against nothing.');

    expect($resolved)->toBeGreaterThan(
        10,
        'only '.$resolved.' non-English locales were resolved, which is too few to be the supported set.'
    );

    expect($untranslated)->toBe([], 'These locales still render the English validation message: '.implode(', ', $untranslated));
});
