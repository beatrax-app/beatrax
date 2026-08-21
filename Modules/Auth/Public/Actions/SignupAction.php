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
use Modules\Auth\Internal\Recovery\RecoveryCodeGenerator;
use Modules\Auth\Internal\Support\Username;
use Modules\Auth\Models\UserRecoveryCode;
use Modules\Core\Models\User;
use Modules\Core\Public\Contracts\Clock;
use Modules\Core\Public\Enums\Locale;
use Modules\Core\Public\Events\UserInstalled;
use Modules\Core\Public\Services\SessionFactory;
use Modules\Core\Public\Services\UserCountry;
use Modules\Core\Public\Support\Lang;

final class SignupAction
{
    private const MINIMUM_PASSWORD_LENGTH = 12;

    private const RECOVERY_CODE_COUNT = 10;

    public function __construct(
        private readonly DatabaseManager $db,
        private readonly Hasher $hasher,
        private readonly AuthManager $auth,
        private readonly RecoveryCodeGenerator $codeGenerator,
        private readonly Clock $clock,
        private readonly SessionFactory $session,
        private readonly Dispatcher $events,
        private readonly Translator $translator,
        private readonly UserCountry $countries,
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

        if (strlen($password) < self::MINIMUM_PASSWORD_LENGTH) {
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

            // The count outside the transaction can predate a concurrent commit.
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

            $now = $this->clock->now();

            // A collision is astronomically rare, but the unique code_hash
            // index would reject the insert outright.
            $codesPlain = [];
            while (count($codesPlain) < self::RECOVERY_CODE_COUNT) {
                $code = $this->codeGenerator->generate();
                if (in_array($code, $codesPlain, true)) {
                    continue;
                }
                $codesPlain[] = $code;
            }

            foreach ($codesPlain as $plainCode) {
                UserRecoveryCode::query()->create([
                    'user_id' => $user->id,
                    'code_hash' => $this->hasher->make($plainCode),
                    'used_at' => null,
                    'created_at' => $now,
                ]);
            }

            return ['user' => $user, 'codesPlain' => $codesPlain];
        });

        // After commit and before auto-login, so the listener chain runs in
        // the same unauthenticated context as the console install path.
        $this->events->dispatch(new UserInstalled($result['user']->id, $seedsStarterData));

        // Through the same seam Settings writes, so the country-scoped
        // reference data a fresh install needs is seeded here too. An empty
        // code is the reader skipping the picker, and store() leaves it unset.
        $this->countries->store($result['user']->id, $countryCode);

        /** @var StatefulGuard $guard */
        $guard = $this->auth->guard();
        $guard->login($result['user']);

        ($this->session)()->put('auth.signup.recovery_codes_plain', $result['codesPlain']);

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
