<?php

declare(strict_types=1);

namespace Modules\Core\Internal\Http\Livewire;

use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Contracts\View\View;
use Livewire\Component;

/**
 * Calm, hand-written Livewire login form. State is bound here; the form POSTs
 * to Fortify's `/login` route via a regular HTML form action so Fortify owns
 * the auth pipeline and CSRF + rate-limiter behave canonically.
 */
final class LoginForm extends Component
{
    public string $email = '';

    public string $password = '';

    public bool $remember = true;

    public function render(ViewFactory $views): View
    {
        return $views->make('core::livewire.login-form');
    }
}
