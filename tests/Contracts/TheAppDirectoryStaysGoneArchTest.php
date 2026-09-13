<?php

declare(strict_types=1);

// app/ held four unrelated things — PHPStan rules, three commands, seven
// fixture rebasers and three providers — and its only claim on them was
// Laravel's default. They live with what they belong to now.
//
// It does not stay gone on its own. `native:install` calls
// `vendor:publish --tag=nativephp-provider` unconditionally, and
// spatie/laravel-package-tools hard-codes the destination as
// base_path("app/Providers/{$name}.php"), so a `composer update` republishes a
// provider nothing reads and recreates the directory around it.

it('has no app directory', function (): void {
    $path = base_path('app');

    $held = is_dir($path)
        ? array_values(array_diff((array) scandir($path), ['.', '..']))
        : [];

    expect(is_dir($path))->toBeFalse(
        'app/ is back, holding: '.implode(', ', $held)
        .'. If composer put it there, scripts/nativephp_forget_published_provider.php did not run.'
    );
});

it('keeps the composer hook that deletes what native:install republishes', function (): void {
    /** @var array{scripts?: array<string, string|list<string>>} $composer */
    $composer = json_decode((string) file_get_contents(base_path('composer.json')), true, 512, JSON_THROW_ON_ERROR);

    $steps = implode(' ', (array) ($composer['scripts']['post-update-cmd'] ?? []));

    expect($steps)->toContain('native:install')
        ->and($steps)->toContain('nativephp_forget_published_provider');

    expect(is_file(base_path('scripts/nativephp_forget_published_provider.php')))->toBeTrue(
        'The hook names a script that is not there, so the next composer update leaves app/ behind.'
    );
});

it('has no App namespace left to autoload', function (): void {
    /** @var array{autoload: array{psr-4: array<string, string>}, autoload-dev: array{psr-4: array<string, string>}} $composer */
    $composer = json_decode((string) file_get_contents(base_path('composer.json')), true, 512, JSON_THROW_ON_ERROR);

    expect($composer['autoload']['psr-4'])->not->toHaveKey('App\\')
        ->and($composer['autoload-dev']['psr-4'])->toHaveKey('Beatrax\\Tooling\\PhpStan\\');

    // The tooling root is dev-only on purpose: its PHPStan rules run against
    // this tree and have no business in a --no-dev install, which is what a
    // shipped bundle is built from.
    expect($composer['autoload']['psr-4'])->not->toHaveKey('Beatrax\\Tooling\\PhpStan\\');

    // And it points at tools/PhpStan, not tools/. The wider root swept in
    // tools/phpstan-stubs/, whose files declare `Native\Mobile\...` at paths
    // no psr-4 rule can map, so composer dropped each with a warning on every
    // install — and the phpstan.neon comment promising they live outside every
    // psr-4 root quietly stopped being true.
    expect($composer['autoload-dev']['psr-4']['Beatrax\\Tooling\\PhpStan\\'])->toBe('tools/PhpStan/');
});

it('says the same thing from the second composer root', function (): void {
    /** @var array{autoload: array{psr-4: array<string, string>}} $mobile */
    $mobile = json_decode((string) file_get_contents(base_path('mobile-app/composer.json')), true, 512, JSON_THROW_ON_ERROR);

    expect($mobile['autoload']['psr-4'])->not->toHaveKey('App\\');

    // mobile-app/app was a tracked symlink onto the directory that is gone.
    expect(is_link(base_path('mobile-app/app')))->toBeFalse('the mobile root still links a directory that does not exist');
});

// Removing app/ took the thing Laravel guesses the application namespace from.
// `Application::getNamespace()` walks composer.json's psr-4 roots for the one
// whose directory IS app_path(), and throws when none is. It is not a
// console-only path: Blade's ComponentTagCompiler calls it for every `<x-…>` tag
// whose class name it has to guess, so a view renders into a RuntimeException.
it('still answers what the application namespace is', function (): void {
    $namespace = app()->getNamespace();

    expect($namespace)->toBe('Modules\\');

    // Answered by a psr-4 root that EXISTS. Before app/ went, this was answered
    // by accident: a dead `Database\Factories\` entry pointed at a directory
    // that was not there either, realpath() returned false for it and for the
    // missing app/, and false === false matched. The framework was handed a
    // namespace nothing in this repository uses, and nothing failed.
    /** @var array{autoload: array{psr-4: array<string, string>}} $composer */
    $composer = json_decode((string) file_get_contents(base_path('composer.json')), true, 512, JSON_THROW_ON_ERROR);

    expect($composer['autoload']['psr-4'])->toHaveKey($namespace);
    expect(is_dir(base_path($composer['autoload']['psr-4'][$namespace])))->toBeTrue(
        'The application namespace resolves to a directory that is not there, which is how it came to be answered by accident.'
    );
});

it('tells both roots where the application directory is', function (): void {
    // The mobile root boots from its own bootstrap/app.php and would throw the
    // same RuntimeException on the phone alone, where nobody is running Pest.
    foreach (['bootstrap/app.php', 'mobile-app/bootstrap/app.php'] as $manifest) {
        expect((string) file_get_contents(base_path($manifest)))
            ->toContain("useAppPath(\$app->basePath('Modules'))");
    }
});
