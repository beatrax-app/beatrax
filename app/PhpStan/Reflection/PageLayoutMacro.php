<?php

declare(strict_types=1);

namespace App\PhpStan\Reflection;

use PHPStan\Reflection\ClassMemberReflection;
use PHPStan\Reflection\ClassReflection;
use PHPStan\Reflection\MethodReflection;
use PHPStan\Reflection\ParametersAcceptor;
use PHPStan\TrinaryLogic;
use PHPStan\Type\Type;

// PageLayoutMacroSignatures declares the signature; this moves it onto the
// class the call site actually holds, so an error names
// Illuminate\Contracts\View\View::extends() and not a helper nobody imported.
final class PageLayoutMacro implements MethodReflection
{
    public function __construct(
        private readonly ClassReflection $declaringClass,
        private readonly MethodReflection $signature,
    ) {}

    public function getDeclaringClass(): ClassReflection
    {
        return $this->declaringClass;
    }

    public function isStatic(): bool
    {
        return $this->signature->isStatic();
    }

    public function isPrivate(): bool
    {
        return $this->signature->isPrivate();
    }

    public function isPublic(): bool
    {
        return $this->signature->isPublic();
    }

    public function getDocComment(): ?string
    {
        return $this->signature->getDocComment();
    }

    public function getName(): string
    {
        return $this->signature->getName();
    }

    public function getPrototype(): ClassMemberReflection
    {
        return $this;
    }

    /**
     * @return list<ParametersAcceptor>
     */
    public function getVariants(): array
    {
        return $this->signature->getVariants();
    }

    public function isDeprecated(): TrinaryLogic
    {
        return $this->signature->isDeprecated();
    }

    public function getDeprecatedDescription(): ?string
    {
        return $this->signature->getDeprecatedDescription();
    }

    public function isFinal(): TrinaryLogic
    {
        return $this->signature->isFinal();
    }

    public function isInternal(): TrinaryLogic
    {
        return $this->signature->isInternal();
    }

    public function getThrowType(): ?Type
    {
        return $this->signature->getThrowType();
    }

    // The macro mutates the view's layout configuration, so discarding the
    // returned view is the normal call shape rather than a dead expression.
    public function hasSideEffects(): TrinaryLogic
    {
        return TrinaryLogic::createYes();
    }
}
