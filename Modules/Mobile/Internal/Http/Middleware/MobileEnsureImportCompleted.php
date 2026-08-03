<?php

declare(strict_types=1);

namespace Modules\Mobile\Internal\Http\Middleware;

use Closure;
use Illuminate\Contracts\Routing\UrlGenerator;
use Illuminate\Database\DatabaseManager;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Modules\Core\Public\Contracts\CurrentUser;
use Modules\Mobile\Internal\Sync\MobileImportIntentGate;
use Symfony\Component\HttpFoundation\Response;

/**
 * @link ../../../../../.docs/features/mobile/architecture.md
 */
final readonly class MobileEnsureImportCompleted
{
    // Every surface the abandoned ceremony itself needs, plus the escape
    // hatches a stuck user must always keep. Prefix-matched, mirroring
    // MobileEnsureDatabaseReady's own exempt list.
    /** @var array<int, string> */
    private const EXEMPT_ROUTE_PREFIXES = [
        'mobile.pair',
        'mobile.setup',
        'mobile.lock',
        'mobile.import',
        'mobile.welcome',
        'auth.lock',
        'logout',
        'sync.index',
    ];

    // The Livewire AJAX endpoint drives the pairing and setup screens
    // themselves, so bouncing it would break the very flow this gate is
    // trying to return the user to.
    /** @var array<int, string> */
    private const EXEMPT_ROUTE_SUFFIXES = [
        'livewire.update',
    ];

    // The terminal phase InitialSyncPuller writes once the bidirectional
    // catch-up has finished and, for an import device, the delivered
    // epochs have re-projected the full op-log.
    private const PHASE_COMPLETE = 'complete';

    public function __construct(
        private CurrentUser $currentUser,
        private MobileImportIntentGate $importIntent,
        private DatabaseManager $db,
        private UrlGenerator $urls,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->currentUser->isAuthenticated() || $this->isExempt($request)) {
            return $this->pass($request, $next);
        }

        $userId = $this->currentUser->user()->id;

        if (! $this->importIntent->isImporting($userId)) {
            return $this->pass($request, $next);
        }

        // An import device with no epoch has not been admitted as a peer
        // yet, so the only thing it can usefully do is finish pairing.
        if (! $this->hasEpoch($userId)) {
            return new RedirectResponse($this->urls->route('mobile.pair', ['mode' => 'import']));
        }

        // Paired but still pulling: the setup gate is deliberately
        // blocking, because a half-populated balance is worse than a
        // progress bar.
        if (! $this->hasCompletedInitialSync($userId)) {
            return new RedirectResponse($this->urls->route('mobile.setup'));
        }

        // Converged - retire the marker so this gate costs one boolean
        // lookup and nothing more for the rest of the device's life.
        $this->importIntent->clearImporting($userId);

        return $this->pass($request, $next);
    }

    private function pass(Request $request, Closure $next): Response
    {
        /** @var Response $response */
        $response = $next($request);

        return $response;
    }

    private function hasEpoch(int $userId): bool
    {
        return $this->db->connection()
            ->table('sync_encryption_state')
            ->where('user_id', $userId)
            ->value('current_epoch') !== null;
    }

    private function hasCompletedInitialSync(int $userId): bool
    {
        return $this->db->connection()
            ->table('mobile_sync_progress')
            ->where('user_id', $userId)
            ->where('phase', self::PHASE_COMPLETE)
            ->exists();
    }

    private function isExempt(Request $request): bool
    {
        $name = $request->route()?->getName();
        if (! is_string($name)) {
            return false;
        }

        foreach (self::EXEMPT_ROUTE_PREFIXES as $prefix) {
            if (str_starts_with($name, $prefix)) {
                return true;
            }
        }

        foreach (self::EXEMPT_ROUTE_SUFFIXES as $suffix) {
            if (str_ends_with($name, $suffix)) {
                return true;
            }
        }

        return false;
    }
}
