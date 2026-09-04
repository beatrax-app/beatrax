<?php

declare(strict_types=1);

namespace Modules\Categorization\Internal\Examples;

use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Contracts\View\View;

final class GoodPageLayoutFixture
{
    public function render(ViewFactory $views): View
    {
        $view = $views->make('categorization::livewire.rules-page');

        $view->extends('layouts.app', ['title' => 'Rules']);

        return $view;
    }
}
