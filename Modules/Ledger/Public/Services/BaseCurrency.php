<?php

declare(strict_types=1);

namespace Modules\Ledger\Public\Services;

use Illuminate\Container\Container;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Foundation\Application;
use Modules\Core\Models\User;
use Modules\Core\Public\Contracts\CurrentUser;
use Modules\Core\Public\Exceptions\NotAuthenticatedException;
use Modules\Ledger\Public\Enums\Currency;

// The one place the reader's reporting currency resolves, so every roll-up
// renders in the code /settings wrote to users.base_currency rather than in
// whatever the install shipped with.
/**
 * @link ../../../../.docs/features/ledger/architecture.md
 */
final readonly class BaseCurrency
{
    public function __construct(
        private Repository $config,
        private CurrentUser $currentUser,
        private Application $app,
    ) {}

    /**
     * @throws NotAuthenticatedException on a web request with no reader.
     */
    public function code(): string
    {
        try {
            return $this->forUser($this->currentUser->user());
        } catch (NotAuthenticatedException $noReader) {
            // UserScope's split, for the same reason: a web request with no
            // reader would be answering with a guessed sign over somebody's
            // real total. The console has no reader to have a preference.
            if (! $this->app->runningInConsole()) {
                throw $noReader;
            }

            return $this->installDefault();
        }
    }

    // No query and no cache on purpose: this reads an attribute off a model the
    // caller already holds, and code() reads it off the guard's per-request
    // user, so a render printing a hundred figures still costs one lookup.
    public function forUser(User $user): string
    {
        $chosen = $user->base_currency;

        return is_string($chosen) && $chosen !== '' ? $chosen : $this->installDefault();
    }

    // What an install ships with, for a user who has never chosen — the column
    // was added nullable with no backfill, so that is every row older than it.
    public function installDefault(): string
    {
        $value = $this->config->get('currency.base');

        return is_string($value) && $value !== '' ? $value : Currency::Eur->value;
    }

    // For Blade, which cannot inject the service. Domain code injects it and
    // calls code() or forUser() directly.
    public static function value(): string
    {
        return Container::getInstance()->make(self::class)->code();
    }
}
