<?php

declare(strict_types=1);

use Modules\Core\Public\Support\Lang;
use Modules\Reports\Internal\Actions\TogglePin;
use Modules\Reports\Internal\Services\PinnedReportsQuery;

// The cap is enforced twice on purpose — once where a pin is written, once
// where the pinned strip is read — so a stray fourth row can never render. Two
// enforcement points is the defence; two different numbers is not. It was
// written out three times: both constants, and again as the digit "3" inside
// the sentence that tells the reader about it, in every one of 26 languages.

/**
 * @return list<string>
 */
function localesSpellingTheCapAsADigit(): array
{
    $offenders = [];

    foreach (glob(base_path('Modules/Reports/Resources/lang/*/index.php')) ?: [] as $file) {
        /** @var array<string, mixed> $strings */
        $strings = require $file;
        $line = $strings['pin_cap'] ?? '';

        if (is_string($line) && preg_match('/\d/', $line) === 1) {
            $offenders[] = basename(dirname($file));
        }
    }

    sort($offenders);

    return $offenders;
}

// Reflection cannot answer this: a constant initialised from another class
// still reports itself as declared where it is written, so only the source
// says whether the number was typed again.
it('types the cap as a number in exactly one file', function (): void {
    $declaring = [];

    $tree = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(base_path('Modules/Reports'), FilesystemIterator::SKIP_DOTS)
    );

    /** @var SplFileInfo $file */
    foreach ($tree as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }

        $contents = (string) file_get_contents($file->getPathname());

        if (preg_match('/const\s+(?:int\s+)?MAX_PINS\s*=\s*\d/', $contents) === 1) {
            $declaring[] = str_replace(base_path().'/', '', $file->getPathname());
        }
    }

    sort($declaring);

    expect($declaring)->toBe(['Modules/Reports/Internal/Support/PinCap.php']);
});

it('enforces the same cap on the read side as on the write side', function (): void {
    $read = new ReflectionClassConstant(PinnedReportsQuery::class, 'MAX_PINS');

    expect($read->getValue())->toBe(TogglePin::MAX_PINS);
});

it('does not spell the cap as a digit inside the sentence that states it', function (): void {
    expect(localesSpellingTheCapAsADigit())->toBe(
        [],
        'These locales hard-code the pin cap in `pin_cap` instead of taking :max, so moving '
        ."the cap leaves them stating the old one:\n  "
        .implode(', ', localesSpellingTheCapAsADigit())
    );
});

it('states the cap the code actually enforces', function (): void {
    expect(Lang::get('reports::index.pin_cap', ['max' => TogglePin::MAX_PINS]))
        ->toContain((string) TogglePin::MAX_PINS);
});
