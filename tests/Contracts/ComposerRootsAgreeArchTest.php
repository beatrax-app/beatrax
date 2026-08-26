<?php

declare(strict_types=1);

// The app has two Composer roots — the repo root the desktop and the test
// suite run from, and mobile-app/, whose vendor/ is the one that ships inside
// the phone build. They share every line of Modules/, so a library pinned
// differently in the two files is code written against one version and shipped
// against another. brick/money sat at ^0.14 here and ^0.11 there: the phone
// fatalled with `Class "Brick\Money\ExchangeRateProvider\Configurable\
// ConfigurableProviderBuilder" not found` the first time anything asked it to
// convert a currency, and CI could not see it because the mobile root runs
// only the Mobile testsuite.

/**
 * @return array{0: array<string, string>, 1: array<string, string>}
 */
function composerRootRequires(): array
{
    $decode = static function (string $path): array {
        $raw = (string) file_get_contents($path);
        /** @var array{require?: array<string, string>} $json */
        $json = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);

        return $json['require'] ?? [];
    };

    return [
        $decode(base_path('composer.json')),
        $decode(base_path('mobile-app/composer.json')),
    ];
}

it('pins every shared runtime dependency to the same constraint in both Composer roots', function (): void {
    [$root, $mobile] = composerRootRequires();

    $mismatched = [];
    foreach (array_keys($root) as $package) {
        if (! isset($mobile[$package])) {
            continue;
        }
        if ($root[$package] !== $mobile[$package]) {
            $mismatched[$package] = sprintf('root %s vs mobile-app %s', $root[$package], $mobile[$package]);
        }
    }

    expect($mismatched)->toBe([]);
});
