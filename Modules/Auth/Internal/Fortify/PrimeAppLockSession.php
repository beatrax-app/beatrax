<?php

declare(strict_types=1);

namespace Modules\Auth\Internal\Fortify;

use Closure;
use Illuminate\Auth\AuthManager;
use Illuminate\Http\Request;
use Modules\Auth\Internal\Lock\AppLockProvisioner;
use Modules\Core\Models\User;
use Modules\Core\Public\Services\SessionFactory;

// LoginAction primes the app-lock session itself, but the route posts through
// Fortify's pipeline instead and never reaches it. Without this step a form
// submitted before Livewire has booted authenticates into the one state the
// lock forbids: unlocked, holding no data key.
final readonly class PrimeAppLockSession
{
    public function __construct(
        private AppLockProvisioner $provisioner,
        private SessionFactory $session,
        private AuthManager $auth,
    ) {}

    public function handle(Request $request, Closure $next): mixed
    {
        $user = $this->auth->guard()->user();
        $password = $request->input('password');

        // After PrepareAuthenticatedSession, so the key lands in the session
        // the regenerated id belongs to rather than the one just discarded.
        if ($user instanceof User && is_string($password)) {
            $this->provisioner->primeSessionAfterLogin($user->id, $password, ($this->session)());
        }

        return $next($request);
    }
}
