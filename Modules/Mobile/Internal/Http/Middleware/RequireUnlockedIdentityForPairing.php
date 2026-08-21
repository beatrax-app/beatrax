<?php

declare(strict_types=1);

namespace Modules\Mobile\Internal\Http\Middleware;

use Closure;
use Illuminate\Contracts\Routing\UrlGenerator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Modules\Auth\Public\Services\AppLockClientConfig;
use Modules\Auth\Public\Services\MobileLockGateway;
use Modules\Core\Public\Contracts\CurrentUser;
use Modules\Core\Public\Support\Lang;
use Modules\Mobile\Internal\Http\Livewire\MobilePairingScan;
use Modules\Sync\Public\Services\PairingGateway;
use Symfony\Component\HttpFoundation\Response;

// A pairing code is single-use, lives ten minutes and only one exists per
// account, so letting submit() discover the lock spends the token on an
// operation that could never have completed. The page load is the last moment
// a still-live code can be saved.
final readonly class RequireUnlockedIdentityForPairing
{
    public function __construct(
        private CurrentUser $currentUser,
        private PairingGateway $pairing,
        private AppLockClientConfig $lock,
        private UrlGenerator $urls,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->sealedIdentity($request)) {
            /** @var Response $response */
            $response = $next($request);

            return $response;
        }

        $session = $request->session();

        // Flashed for the lock screen to render: a PIN pad arriving with no
        // explanation is the dead end this redirect exists to avoid.
        $session->flash(
            MobilePairingScan::LOCKED_IDENTITY_FLASH,
            Lang::get('mobile::pairing.errors.identity_locked'),
        );

        // The whole URL, so an importing device returns to the arm it was on
        // rather than to a pairing screen that has forgotten it is importing.
        $session->put(MobileLockGateway::SESSION_INTENDED_URL, $request->fullUrl());

        return new RedirectResponse($this->urls->route('mobile.lock'));
    }

    // Gated on the FILE plus an unreadable load, never on the load alone: a
    // device with no identity yet legitimately mints one on submit, and only a
    // sealed existing one makes the screen unable to spend what it accepts.
    private function sealedIdentity(Request $request): bool
    {
        if (! $this->currentUser->isAuthenticated()) {
            return false;
        }

        $userId = $this->currentUser->id();

        // With no app lock there is nothing to unlock with, so the PIN pad
        // strands the user; the screen's own copy says that instead.
        if (! $this->lock->isEnabled($userId)) {
            return false;
        }

        return $this->pairing->hasIdentityFile($userId)
            && ! $this->pairing->hasUsableIdentity($userId, $request->session());
    }
}
