<?php

declare(strict_types=1);

use Tests\Contracts\Support\RepoTree;

/**
 * @return list<string> the 1-based lines on which $source calls the framework helper
 *
 * @link ../../.docs/conventions/invariants-from-shipped-failures.md#numbercurrency-on-the-mobile-icu-build
 */
function currencyThroughMoneyOffenders(string $source): array
{
    $found = [];
    $offset = 0;

    while (($at = strpos($source, 'Number::currency(', $offset)) !== false) {
        $found[] = (string) (substr_count(substr($source, 0, $at), "\n") + 1);
        $offset = $at + 1;
    }

    return $found;
}

it('renders no currency through the framework number helper', function (): void {
    $files = RepoTree::files(RepoTree::PRODUCTION_PHP);

    // The floor sits far under the 6,700 shipped PHP files this scope opens today.
    // A walk that reached none of them reports a clean tree over code nobody read.
    expect(count($files))->toBeGreaterThan(
        2000,
        'The production PHP walk opened almost nothing, so this rule checked nothing.'
    );

    $offenders = [];

    foreach ($files as $path) {
        $contents = (string) file_get_contents($path);

        foreach (currencyThroughMoneyOffenders($contents) as $line) {
            $offenders[] = str_replace(RepoTree::root().'/', '', $path).':'.$line;
        }
    }

    expect($offenders)->toBe(
        [],
        "Number::currency() throws on a runtime whose ICU has no data for the\n".
        "locale. Render money through Money::format() instead, in:\n  ".
        implode("\n  ", $offenders),
    );
});

// The guard is worth its ability to go red, and a substring reader that matched
// nothing would not be.
it('sees the framework helper and leaves the seam that replaced it alone', function (): void {
    expect(currencyThroughMoneyOffenders("<?php\n\$a = 1;\nreturn Number::currency(10, 'EUR');\n"))->toBe(['3']);
    expect(currencyThroughMoneyOffenders("<?php\nreturn Money::ofMinor(1000, 'EUR')->format();\n"))->toBe(
        [],
        'The seam this rule points callers at was read as an offender.'
    );
});
