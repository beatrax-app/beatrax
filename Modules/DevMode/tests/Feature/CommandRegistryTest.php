<?php

declare(strict_types=1);

use Modules\DevMode\Public\Contracts\DevCommandRegistry;
use Modules\DevMode\Public\Dto\ArgSpec;
use Modules\DevMode\Public\Dto\CommandSpec;

it('binds the concrete CommandRegistry returning 9 SAFE specs', function (): void {
    /** @var DevCommandRegistry $registry */
    $registry = app(DevCommandRegistry::class);

    $safe = $registry->safe();
    expect($safe)->toHaveCount(9);

    $names = array_map(static fn (CommandSpec $spec): string => $spec->name, $safe);
    expect($names)->toEqual([
        'db:backup',
        'beatrax:doctor',
        'beatrax:failed-jobs',
        'cache:clear',
        'route:list',
        'config:show',
        'view:clear',
        'queue:retry',
        'beatrax:rederive-fingerprints',
    ]);

    foreach ($safe as $spec) {
        expect($spec->tier)->toBe('safe');
    }
});

it('binds the concrete CommandRegistry returning 6 DESTRUCTIVE specs', function (): void {
    /** @var DevCommandRegistry $registry */
    $registry = app(DevCommandRegistry::class);

    $destructive = $registry->destructive();
    expect($destructive)->toHaveCount(6);

    $names = array_map(static fn (CommandSpec $spec): string => $spec->name, $destructive);
    expect($names)->toEqual([
        'db:restore',
        'migrate:fresh',
        'beatrax:reset-password',
        'beatrax:regenerate-recovery-codes',
        'beatrax:grant-dev',
        'beatrax:install',
    ]);

    foreach ($destructive as $spec) {
        expect($spec->tier)->toBe('destructive');
    }
});

it('throws InvalidArgumentException when find() resolves a NEVER-EXPOSED command', function (): void {
    /** @var DevCommandRegistry $registry */
    $registry = app(DevCommandRegistry::class);

    foreach (['migrate', 'migrate:rollback', 'db:seed'] as $name) {
        try {
            $registry->find($name);
            $this->fail("Expected InvalidArgumentException for NEVER-EXPOSED command `{$name}`, none thrown.");
        } catch (InvalidArgumentException $e) {
            expect($e->getMessage())->toContain($name);
        }
    }
});

it('returns the matching CommandSpec for a known SAFE-tier name', function (): void {
    /** @var DevCommandRegistry $registry */
    $registry = app(DevCommandRegistry::class);

    $spec = $registry->find('cache:clear');
    expect($spec)->toBeInstanceOf(CommandSpec::class);
    expect($spec->name)->toBe('cache:clear');
    expect($spec->tier)->toBe('safe');
});

it('returns the matching CommandSpec for a known DESTRUCTIVE-tier name', function (): void {
    /** @var DevCommandRegistry $registry */
    $registry = app(DevCommandRegistry::class);

    $spec = $registry->find('db:restore');
    expect($spec)->toBeInstanceOf(CommandSpec::class);
    expect($spec->name)->toBe('db:restore');
    expect($spec->tier)->toBe('destructive');
});

it('exposes ArgSpec entries with non-empty name + Laravel-compatible rules array', function (): void {
    /** @var DevCommandRegistry $registry */
    $registry = app(DevCommandRegistry::class);

    $allowedTypes = ['text', 'select', 'file-path', 'boolean'];
    $allSpecs = array_merge($registry->safe(), $registry->destructive());
    expect($allSpecs)->toHaveCount(15);

    foreach ($allSpecs as $spec) {
        foreach ($spec->argsSchema as $arg) {
            expect($arg)->toBeInstanceOf(ArgSpec::class);
            expect($arg->name)->not->toBe('');
            expect($arg->label)->not->toBe('');
            expect($arg->type)->toBeIn($allowedTypes);
            expect($arg->rules)->toBeArray();
            foreach ($arg->rules as $rule) {
                expect($rule)->toBeString();
            }
            if ($arg->type === 'select') {
                expect($arg->options)->toBeArray();
                expect($arg->options)->not->toBe([]);
            }
        }
    }
});
