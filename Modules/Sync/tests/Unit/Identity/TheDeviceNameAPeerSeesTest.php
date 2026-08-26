<?php

declare(strict_types=1);

use Modules\Core\Public\Contracts\DeviceNameSource;
use Modules\Sync\Internal\Identity\DeviceNameDetector;

// This name is stored in device_registry.name and EXCHANGED with peers, and
// none of its four fallback branches had a test. It read PHP_OS_FAMILY inline,
// so a test could only ever have asserted the branch for the machine it ran on
// -- the shape that made sixteen biometric tests pass on a Mac and fail on a
// Linux runner.

function namedBy(?string $platformName, string $family): string
{
    $source = $platformName === null ? null : new class($platformName) implements DeviceNameSource
    {
        public function __construct(private readonly string $name) {}

        public function name(): ?string
        {
            return $this->name;
        }
    };

    return (new DeviceNameDetector($source, $family))->detect();
}

it('prefers the name the platform itself offers', function (): void {
    expect(namedBy("Wessel's iPhone", 'Linux'))->toBe("Wessel's iPhone");
});

it('falls back to a neutral OS label, never the hostname', function (string $family, string $expected): void {
    expect(namedBy(null, $family))->toBe($expected);
})->with([
    'macOS' => ['Darwin', 'Mac'],
    'Windows' => ['Windows', 'PC'],
    'Linux, and Android which reports Linux' => ['Linux', 'Linux'],
    'anything else PHP_OS_FAMILY can answer' => ['BSD', 'This device'],
]);

it('falls back when the platform offers an empty name rather than none', function (): void {
    expect(namedBy('', 'Darwin'))->toBe('Mac');
});
