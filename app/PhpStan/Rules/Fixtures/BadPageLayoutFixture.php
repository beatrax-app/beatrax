<?php

declare(strict_types=1);

namespace Modules\Categorization\Internal\Examples;

use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Contracts\View\View;

// DELIBERATELY BAD: a layout name that is not a string, a parameter bag that is
// not an array, and one of the six Livewire macros this project does not
// declare. All three must still be reported once `extends()` resolves.
final class BadPageLayoutFixture
{
    public function render(ViewFactory $views): View
    {
        $view = $views->make('categorization::livewire.rules-page');

        $view->extends(123);
        $view->extends('layouts.app', 'not-a-parameter-bag');
        $view->layout('layouts.app');

        return $view;
    }
}
