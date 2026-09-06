<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Lang as LangFacade;
use Modules\Core\Public\Enums\Locale;
use Modules\DevMode\Internal\Enums\CommandTier;
use Modules\DevMode\Public\Contracts\DevCommandRegistry;
use Modules\DevMode\Public\Dto\CommandSpec;

// Sample data is what somebody looking at the application for the first time
// needs to see anything at all, and `demo:seed` was reachable only from a
// terminal. It is a console command now — which also means it is not in a
// store build, because the console is not.

function sdcNames(): callable
{
    return static fn (CommandSpec $spec): string => $spec->name;
}

it('offers loading sample data as a named control rather than a command line', function (): void {
    /** @var DevCommandRegistry $registry */
    $registry = app(DevCommandRegistry::class);

    expect(array_map(sdcNames(), $registry->safe()))->toContain('demo:seed');
});

// The control adds. Tearing down what is already there is a decision that
// should cost more than one click, so `--reset` stays on the command line.
it('carries no flag that could replace what is already here', function (): void {
    /** @var DevCommandRegistry $registry */
    $registry = app(DevCommandRegistry::class);
    $spec = $registry->find('demo:seed');

    expect($spec->tier)->toBe(CommandTier::Safe)
        ->and($spec->argsSchema)->toBe([])
        ->and($spec->fixedFlags)->toBe([]);

    expect(array_map(sdcNames(), $registry->destructive()))->not->toContain('demo:seed');
});

it('names the control in every language the interface ships in', function (): void {
    /** @var DevCommandRegistry $registry */
    $registry = app(DevCommandRegistry::class);
    $spec = $registry->find('demo:seed');

    $silent = [];

    foreach (Locale::codes() as $code) {
        foreach ([$spec->labelKey, $spec->descriptionKey] as $key) {
            if (! is_string($key)) {
                continue;
            }

            $line = LangFacade::get($key, [], $code);

            if (! is_string($line) || $line === '' || $line === $key) {
                $silent[] = "{$code}: {$key}";
            }
        }
    }

    expect($silent)->toBe([], implode("\n", [
        'These locales have no words for the sample-data control, so it would',
        'render its own translation key:',
        ...$silent,
    ]));
});

// The positive control for the case above: a key nothing defines comes back as
// itself, so "the line differs from the key" is a real check rather than one
// that passes on anything.
it('reports a key nothing defines as the key itself', function (): void {
    expect(LangFacade::get('dev::runner.command.no_such_command.label', [], 'en'))
        ->toBe('dev::runner.command.no_such_command.label');
});

it('says plainly that the data is invented', function (): void {
    /** @var DevCommandRegistry $registry */
    $registry = app(DevCommandRegistry::class);
    $spec = $registry->find('demo:seed');

    expect(LangFacade::get((string) $spec->descriptionKey, [], 'en'))
        ->toContain('invented')
        ->toContain("real person's data");
});
