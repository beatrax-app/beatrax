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

it('ships the framework lang files in every supported locale', function (): void {
    $missing = [];

    foreach (Locale::cases() as $case) {
        if ($case->value === Locale::DEFAULT) {
            // English is the framework's own default and needs no override.
            continue;
        }

        foreach (frameworkTranslationFiles() as $file) {
            $path = base_path("lang/{$case->value}/{$file}");

            if (! is_file($path)) {
                $missing[] = "{$case->value}/{$file}";
            }
        }
    }

    expect($missing)->toBe([], "Laravel's own messages fall back to English in these:\n  ".implode("\n  ", $missing));
});

it('actually resolves a validation message in each locale, rather than falling through to English', function (): void {
    $english = null;
    $untranslated = [];

    foreach (Locale::cases() as $case) {
        app()->setLocale($case->value);

        $message = validator(['field' => null], ['field' => 'required'])
            ->errors()
            ->first('field');

        if ($case->value === Locale::DEFAULT) {
            $english = $message;

            continue;
        }

        // A locale whose message is byte-identical to English has no file of
        // its own and fell through — which is the failure this guards.
        if ($message === $english) {
            $untranslated[] = $case->value;
        }
    }

    expect($untranslated)->toBe([], 'These locales still render the English validation message: '.implode(', ', $untranslated));
});
