<?php

declare(strict_types=1);

namespace Modules\Auth\Internal\Console;

use Illuminate\Console\Command;
use Modules\Core\Models\User;

// The Dev Console wraps this runner's destructive equivalent in a
// triple-gate modal (typed-app-name confirmation + advanced-toggle +
// dev-mode-on). At the CLI there is no triple gate — the operator is
// expected to know what they are doing.
class GrantDevCommand extends Command
{
    /** @var string */
    protected $signature = 'beatrax:grant-dev {username : Username to promote to developer}';

    /** @var string */
    protected $description = 'Grant developer access (is_developer=true) to a user.';

    public function handle(): int
    {
        // The required `username` argument is narrowed to a string by
        // Larastan against the typed signature (mirrors the
        // ResetPasswordCommand carve-out), so no is_string() guard
        // is needed here.
        $username = strtolower(trim($this->argument('username')));
        if ($username === '') {
            $this->error('Username is required.');

            return self::FAILURE;
        }

        /** @var User|null $user */
        $user = User::query()->where('username', $username)->first();

        if (! $user instanceof User) {
            $this->error("User not found: {$username}");

            return self::FAILURE;
        }

        return $this->grant($user, $username);
    }

    private function grant(User $user, string $username): int
    {
        if ($user->is_developer === true) {
            $this->info("Already a developer: {$username}");

            return self::SUCCESS;
        }

        $user->fill(['is_developer' => true])->save();

        $this->info("Granted developer to {$username}");

        return self::SUCCESS;
    }
}
