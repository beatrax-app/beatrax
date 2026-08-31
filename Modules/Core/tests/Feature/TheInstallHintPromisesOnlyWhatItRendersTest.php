<?php

declare(strict_types=1);

// The docblock promised an iOS Safari arm showing "Tap Share, then Add to Home
// Screen". `shown` is set true in two places — beforeinstallprompt, which is
// Chromium-only, and a >=1024px media query — so an iPhone matches neither and
// the card never renders there. The claim was repeated on the dashboard.

function installHintSource(): string
{
    return (string) file_get_contents(
        base_path('Modules/Core/Resources/views/components/install-hint.blade.php'),
    );
}

it('sets its shown flag from exactly the two arms it documents', function (): void {
    $source = installHintSource();

    expect(substr_count($source, 'this.shown = true'))->toBe(2)
        ->and($source)->toContain('beforeinstallprompt')
        ->toContain('(min-width: 1024px)');
});

it('promises no iOS branch while it has none', function (): void {
    $source = installHintSource();

    // The words a reader would go looking for a branch behind. If an iOS arm
    // is ever added, this test is what says the docblock has to earn them back.
    expect($source)->toContain('There is NO iOS arm');

    $docblock = (string) (preg_split('/^-->|--}}/m', $source)[0] ?? '');

    expect($docblock)->not->toContain('shows instructions');
});

it('ships no install copy that nothing can reach', function (): void {
    /** @var array<string, mixed> $components */
    $components = require base_path('Modules/Core/Resources/lang/en/components.php');

    /** @var array<string, string> $install */
    $install = $components['install'] ?? [];
    $source = installHintSource();

    $unreachable = [];
    foreach (array_keys($install) as $key) {
        if (! str_contains($source, "install.{$key}")) {
            $unreachable[] = $key;
        }
    }

    expect($unreachable)->toBe([], 'these install.* keys are rendered by nothing');
});
