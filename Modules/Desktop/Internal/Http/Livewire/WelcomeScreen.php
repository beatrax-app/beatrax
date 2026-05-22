<?php

declare(strict_types=1);

namespace Modules\Desktop\Internal\Http\Livewire;

use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Contracts\View\View;
use Livewire\Component;

/**
 * Welcome screen (D-22).
 *
 * Renders after the first-launch DB bootstrap finishes on a fresh
 * install — a centered brand mark, the "Welcome to diederik" heading,
 * and a single emerald "Get started" button that drops the user onto
 * `/signup` (Phase 12 D-03 gates the signup route to `User::count() === 0`,
 * so the welcome page → signup path is internally consistent).
 *
 * Stateless — zero properties, no constructor. `phpstan-strict-rules`
 * forbids a constructor on a Livewire `Component` subclass.
 */
final class WelcomeScreen extends Component
{
    public function render(ViewFactory $views): View
    {
        $view = $views->make('desktop::welcome');

        /** @phpstan-ignore-next-line method.notFound — registered at runtime by Livewire's SupportPageComponents */
        $view->extends('layouts.app', ['title' => 'Welcome · diederik']);

        return $view;
    }
}
