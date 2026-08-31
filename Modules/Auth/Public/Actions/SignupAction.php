<?php

declare(strict_types=1);

namespace Modules\Auth\Public\Actions;

use Illuminate\Auth\AuthManager;
use Illuminate\Contracts\Auth\StatefulGuard;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Hashing\Hasher;
use Illuminate\Contracts\Translation\Translator;
use Illuminate\Database\DatabaseManager;
use Illuminate\Validation\ValidationException;
use Modules\Auth\Internal\Recovery\RecoveryCodeMinter;
use Modules\Auth\Public\Contracts\PasswordPolicy;
use Modules\Auth\Public\Recovery\PendingRecoveryCodes;
use Modules\Auth\Public\Support\Username;
use Modules\Core\Models\User;
use Modules\Core\Public\Enums\Locale;
use Modules\Core\Public\Events\UserInstalled;
use Modules\Core\Public\Services\SessionFactory;
use Modules\Core\Public\Services\UserCountry;
use Modules\Core\Public\Support\Lang;
use Modules\Core\Public\Support\SafeExceptionContext;
use Psr\Log\LoggerInterface;
use Throwable;

final readonly class SignupAction
{
    public function __construct(
        private DatabaseManager $db,
        private Hasher $hasher,
        private AuthManager $auth,
        private RecoveryCodeMinter $recoveryCodes,
        private SessionFactory $session,
        private Dispatcher $events,
        private Translator $translator,
        private UserCountry $countries,
        private LoggerInterface $log,
    ) {}

    /**
     * @return array{user: User, codesPlain: list<string>}
     */
    public function __invoke(
        string $usernameInput,
        string $password,
        bool $seedsStarterData = true,
        string $countryCode = '',
    ): array {
        $username = Username::normalize($usernameInput);

        if (! Username::isValid($username)) {
            throw ValidationException::withMessages([
                'username' => Lang::get('auth::signup.error_username_invalid'),
            ]);
        }

        if (strlen($password) < PasswordPolicy::MINIMUM_LENGTH) {
            throw ValidationException::withMessages([
                'password' => Lang::get('auth::signup.error_min_length'),
            ]);
        }

        /** @var array{user: User, codesPlain: non-empty-list<string>} $result */
        $result = $this->db->connection()->transaction(function () use ($username, $password): array {
            // A no-op UPDATE matches nothing but still takes the write lock,
            // so a concurrent signup blocks here instead of reading a stale
            // zero-count snapshot under SQLite WAL.
            $this->db->connection()->statement('UPDATE users SET id = id WHERE 0 = 1');

            if ($this->db->connection()->table('users')->count() > 0) {
                throw ValidationException::withMessages([
                    'signup' => Lang::get('auth::signup.error_closed'),
                ]);
            }

            $user = User::query()->create([
                'username' => $username,
                'password' => $this->hasher->make($password),
                'is_developer' => true,
                'force_password_change_at_next_login' => false,
                // SetLocale prefers the user's own setting, so a null here
                // fell back to the browser and flipped the post-signup
                // screens back to English.
                'locale' => $this->activeLocale(),
            ]);

            // create() does not read the row back, so a database default is
            // null on the returned instance, which the guard below holds. On
            // the persistent mobile runtime that null outlived the request.
            $user->refresh();

            return ['user' => $user, 'codesPlain' => $this->recoveryCodes->issueFor($user->id)];
        });

        // After commit and before auto-login, so the listener chain runs in
        // the same unauthenticated context as the console install path.
        $this->events->dispatch(new UserInstalled($result['user']->id, $seedsStarterData));

        // Through the same seam Settings writes, so the country-scoped
        // reference data a fresh install needs is seeded here too. An empty
        // code is the reader skipping the picker, and store() leaves it unset.

        // The country is asked of every joiner rather than synced, so a joiner
        // that did not record it here never learns it. Only the reference data
        // behind it rides on $seedsStarterData: those tables DO sync, and a row
        // this device seeds is one the peer's own can no longer land beside.
        try {
            $this->countries->store($result['user']->id, $countryCode, seedsCountryData: $seedsStarterData);
        } catch (Throwable $e) {
            // The user row is already committed and the recovery codes below
            // are the only way back into this account. A country the reader
            // can set again from Settings is never worth that screen.
            $this->log->error('SignupAction: the chosen country could not be stored', [
                ...SafeExceptionContext::describe($e),
                'userId' => $result['user']->id,
            ]);
        }

        /** @var StatefulGuard $guard */
        $guard = $this->auth->guard();
        $guard->login($result['user']);

        PendingRecoveryCodes::store(($this->session)(), $result['codesPlain']);

        return $result;
    }

    // Session first, translator only as fallback: the translator needs
    // SetLocale to have run on this request, and signup arrives over a
    // Livewire round trip, so it returned English for a user who picked Dutch.
    private function activeLocale(): ?string
    {
        $chosen = ($this->session)()->get('locale');
        $locale = is_string($chosen) && Locale::isSupported($chosen)
            ? $chosen
            : $this->translator->getLocale();

        return $locale === Locale::DEFAULT ? null : $locale;
    }
}
