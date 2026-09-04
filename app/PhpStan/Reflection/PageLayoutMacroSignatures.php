<?php

declare(strict_types=1);

namespace App\PhpStan\Reflection;

use Illuminate\Contracts\View\View as ViewContract;

// The macros Livewire registers on a view at boot, written as PHP so the
// analyser checks the signature it is handed rather than one hand-built out of
// Type objects. Nothing implements this: PageLayoutMacrosExtension reflects it
// and re-declares the method on the interface the call sites use.
interface PageLayoutMacroSignatures
{
    /**
     * @param  array<string, mixed>  $params
     */
    public function extends(string $view, array $params = []): ViewContract;
}
