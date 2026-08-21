<?php

declare(strict_types=1);

use Modules\Core\Models\User;
use Modules\Migration\Public\Contracts\ParsesMigrationSource;
use Modules\Migration\Public\Dto\MigrationBatch;

it('Contract: ParsesMigrationSource declares format(): string', function (): void {
    $reflection = new ReflectionClass(ParsesMigrationSource::class);

    expect($reflection->isInterface())->toBeTrue();
    expect($reflection->hasMethod('format'))->toBeTrue();

    $method = $reflection->getMethod('format');
    expect($method->getNumberOfParameters())->toBe(0);

    $returnType = $method->getReturnType();
    expect($returnType)->toBeInstanceOf(ReflectionNamedType::class);
    /** @var ReflectionNamedType $returnType */
    expect($returnType->getName())->toBe('string');
});

it('Contract: ParsesMigrationSource declares parse(string $extractedPath, User $user, int $migrationRunId): MigrationBatch', function (): void {
    $reflection = new ReflectionClass(ParsesMigrationSource::class);

    expect($reflection->hasMethod('parse'))->toBeTrue();
    $method = $reflection->getMethod('parse');

    $params = $method->getParameters();
    expect($params)->toHaveCount(3);

    expect($params[0]->getName())->toBe('extractedPath');
    $extractedPathType = $params[0]->getType();
    expect($extractedPathType)->toBeInstanceOf(ReflectionNamedType::class);
    /** @var ReflectionNamedType $extractedPathType */
    expect($extractedPathType->getName())->toBe('string');

    expect($params[1]->getName())->toBe('user');
    $userType = $params[1]->getType();
    expect($userType)->toBeInstanceOf(ReflectionNamedType::class);
    /** @var ReflectionNamedType $userType */
    expect($userType->getName())->toBe(User::class);

    expect($params[2]->getName())->toBe('migrationRunId');
    $runIdType = $params[2]->getType();
    expect($runIdType)->toBeInstanceOf(ReflectionNamedType::class);
    /** @var ReflectionNamedType $runIdType */
    expect($runIdType->getName())->toBe('int');

    $returnType = $method->getReturnType();
    expect($returnType)->toBeInstanceOf(ReflectionNamedType::class);
    /** @var ReflectionNamedType $returnType */
    expect($returnType->getName())->toBe(MigrationBatch::class);
});

it('Contract: format() is documented to return one of the three source products', function (): void {
    $reflection = new ReflectionClass(ParsesMigrationSource::class);
    $docComment = $reflection->getMethod('format')->getDocComment();

    expect($docComment)->toBeString();
    /** @var string $docComment */
    foreach (['ynab4', 'nynab', 'actual'] as $product) {
        expect(str_contains($docComment, $product))->toBeTrue("format() docblock should mention '{$product}'");
    }
});
