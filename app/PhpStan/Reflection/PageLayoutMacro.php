<?php

declare(strict_types=1);

namespace App\PhpStan\Reflection;

use PHPStan\Reflection\ClassMemberReflection;
use PHPStan\Reflection\ClassReflection;
use PHPStan\Reflection\FunctionVariant;
use PHPStan\Reflection\MethodReflection;
use PHPStan\Reflection\ParameterReflection;
use PHPStan\TrinaryLogic;
use PHPStan\Type\Generic\TemplateTypeMap;
use PHPStan\Type\Type;

final class PageLayoutMacro implements MethodReflection
{
    /**
     * @param  list<ParameterReflection>  $parameters
     */
    public function __construct(
        private readonly ClassReflection $declaringClass,
        private readonly string $name,
        private readonly array $parameters,
        private readonly Type $returnType,
    ) {}

    public function getDeclaringClass(): ClassReflection
    {
        return $this->declaringClass;
    }

    // larastan's own macro reflection reports a closure macro as static, which
    // turns every correct call into a staticMethod.dynamicCall. View::macro()
    // binds its closure to the view instance.
    public function isStatic(): bool
    {
        return false;
    }

    public function isPrivate(): bool
    {
        return false;
    }

    public function isPublic(): bool
    {
        return true;
    }

    public function getDocComment(): ?string
    {
        return null;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getPrototype(): ClassMemberReflection
    {
        return $this;
    }

    /**
     * @return list<FunctionVariant>
     */
    public function getVariants(): array
    {
        return [
            new FunctionVariant(
                TemplateTypeMap::createEmpty(),
                null,
                $this->parameters,
                false,
                $this->returnType,
            ),
        ];
    }

    public function isDeprecated(): TrinaryLogic
    {
        return TrinaryLogic::createNo();
    }

    public function getDeprecatedDescription(): ?string
    {
        return null;
    }

    public function isFinal(): TrinaryLogic
    {
        return TrinaryLogic::createNo();
    }

    public function isInternal(): TrinaryLogic
    {
        return TrinaryLogic::createNo();
    }

    public function getThrowType(): ?Type
    {
        return null;
    }

    // The macro mutates the view's layout configuration, so discarding the
    // returned $this is the normal call shape rather than a dead expression.
    public function hasSideEffects(): TrinaryLogic
    {
        return TrinaryLogic::createYes();
    }
}
