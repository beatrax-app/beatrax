<?php

declare(strict_types=1);

namespace App\PhpStan\Reflection;

use Illuminate\Contracts\View\View as ViewContract;
use PHPStan\Reflection\ClassReflection;
use PHPStan\Reflection\MethodReflection;
use PHPStan\Reflection\MethodsClassReflectionExtension;
use PHPStan\Type\ArrayType;
use PHPStan\Type\MixedType;
use PHPStan\Type\StringType;
use PHPStan\Type\ThisType;

final class PageLayoutMacrosExtension implements MethodsClassReflectionExtension
{
    // Livewire's SupportPageComponents registers seven macros on
    // Illuminate\View\View. Only the one this project calls is declared here,
    // so reaching for one of the other six still fails.
    private const string EXTENDS_MACRO = 'extends';

    // larastan resolves a macro on an Illuminate\Contracts interface by pulling
    // the concrete out of the container. The view contract has no binding and
    // is not instantiable, so that lookup returns null and every
    // $view->extends() through it reads as an undefined method.
    public function hasMethod(ClassReflection $classReflection, string $methodName): bool
    {
        return $classReflection->getName() === ViewContract::class
            && $methodName === self::EXTENDS_MACRO;
    }

    public function getMethod(ClassReflection $classReflection, string $methodName): MethodReflection
    {
        return new PageLayoutMacro(
            $classReflection,
            $methodName,
            [
                new PageLayoutMacroParameter('view', false, new StringType),
                new PageLayoutMacroParameter('params', true, new ArrayType(new StringType, new MixedType)),
            ],
            new ThisType($classReflection),
        );
    }
}
