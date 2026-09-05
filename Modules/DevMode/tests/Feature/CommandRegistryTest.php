<?php

declare(strict_types=1);

use Modules\Core\Models\User;
use Modules\DevMode\Internal\Enums\ArgType;
use Modules\DevMode\Internal\Enums\CommandTier;
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
        expect($spec->tier)->toBe(CommandTier::Safe);
    }
});

it('binds the concrete CommandRegistry returning 4 DESTRUCTIVE specs', function (): void {
    /** @var DevCommandRegistry $registry */
    $registry = app(DevCommandRegistry::class);

    $destructive = $registry->destructive();
    expect($destructive)->toHaveCount(4);

    $names = array_map(static fn (CommandSpec $spec): string => $spec->name, $destructive);
    expect($names)->toEqual([
        'db:restore',
        'beatrax:regenerate-recovery-codes',
        'beatrax:grant-dev',
        'beatrax:install',
    ]);

    foreach ($destructive as $spec) {
        expect($spec->tier)->toBe(CommandTier::Destructive);
    }
});

it('throws InvalidArgumentException when find() resolves a NEVER-EXPOSED command', function (): void {
    /** @var DevCommandRegistry $registry */
    $registry = app(DevCommandRegistry::class);

    foreach (['migrate', 'migrate:fresh', 'migrate:rollback', 'db:wipe', 'db:seed', 'beatrax:reset-password'] as $name) {
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
    expect($spec->tier)->toBe(CommandTier::Safe);
});

it('returns the matching CommandSpec for a known DESTRUCTIVE-tier name', function (): void {
    /** @var DevCommandRegistry $registry */
    $registry = app(DevCommandRegistry::class);

    $spec = $registry->find('db:restore');
    expect($spec)->toBeInstanceOf(CommandSpec::class);
    expect($spec->name)->toBe('db:restore');
    expect($spec->tier)->toBe(CommandTier::Destructive);
});

it('exposes ArgSpec entries with non-empty name + Laravel-compatible rules array', function (): void {
    /** @var DevCommandRegistry $registry */
    $registry = app(DevCommandRegistry::class);

    $allSpecs = array_merge($registry->safe(), $registry->destructive());
    expect($allSpecs)->toHaveCount(13);

    foreach ($allSpecs as $spec) {
        foreach ($spec->argsSchema as $arg) {
            expect($arg)->toBeInstanceOf(ArgSpec::class);
            expect($arg->name)->not->toBe('');
            expect($arg->labelKey)->not->toBe('');
            expect($arg->rules)->toBeArray();
            foreach ($arg->rules as $rule) {
                expect($rule)->toBeString();
            }
            if ($arg->type === ArgType::Select) {
                expect($arg->options)->toBeArray();
                expect($arg->options)->not->toBe([]);
            }
        }
    }
});

// find() compares with === , so the allow-list is case-sensitive on purpose: a
// name that differs only in case is a different name, and widening the match
// would widen the whitelist that keeps NEVER-EXPOSED commands out. Symfony's
// own console find() retries case-insensitively; this one must not.
it('refuses a registered command name whose case differs', function (): void {
    /** @var DevCommandRegistry $registry */
    $registry = app(DevCommandRegistry::class);

    expect($registry->find('beatrax:doctor')->name)->toBe('beatrax:doctor');
    expect(fn () => $registry->find('Beatrax:doctor'))->toThrow(InvalidArgumentException::class);
});

// The whitelist check runs before find(), so a case-mismatched name is a
// refusal the caller can read rather than an uncaught InvalidArgumentException.
it('answers a case-mismatched spawn with unknown_command rather than a 500', function (): void {
    $user = User::query()->create([
        'username' => 'registry-case-dev',
        'password' => 'fixture-password',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
        'is_developer' => true,
    ]);

    $response = $this->actingAs($user)->postJson('/dev/artisan/spawn', ['command' => 'Beatrax:doctor']);

    $response->assertStatus(422);
    $response->assertJsonPath('error', 'unknown_command');
});
