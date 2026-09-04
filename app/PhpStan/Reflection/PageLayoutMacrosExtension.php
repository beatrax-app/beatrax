<?php

declare(strict_types=1);

namespace App\PhpStan\Reflection;

use Illuminate\Contracts\View\View as ViewContract;
use PHPStan\Reflection\ClassReflection;
use PHPStan\Reflection\MethodReflection;
use PHPStan\Reflection\MethodsClassReflectionExtension;
use PHPStan\Reflection\ReflectionProvider;

final class PageLayoutMacrosExtension implements MethodsClassReflectionExtension
{
    public function __construct(private readonly ReflectionProvider $reflectionProvider) {}

    // larastan resolves a macro on an Illuminate\Contracts interface by pulling
    // the concrete out of the container. The view contract has no binding and
    // is not instantiable, so that lookup returns null and every
    // $view->extends() through it reads as an undefined method.
    public function hasMethod(ClassReflection $classReflection, string $methodName): bool
    {
        return $classReflection->getName() === ViewContract::class
            && $this->signatures()->hasNativeMethod($methodName);
    }

    public function getMethod(ClassReflection $classReflection, string $methodName): MethodReflection
    {
        return new PageLayoutMacro($classReflection, $this->signatures()->getNativeMethod($methodName));
    }

    private function signatures(): ClassReflection
    {
        return $this->reflectionProvider->getClass(PageLayoutMacroSignatures::class);
    }
}
