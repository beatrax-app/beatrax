<?php

declare(strict_types=1);

namespace Modules\Auth\Internal\Console;

use Illuminate\Console\Command;
use Modules\Core\Models\User;

/**
 * CLI grant of the `is_developer` flag for an existing user.
 *
 * Phase 12's first-signup ceremony auto-promotes the first account to
 * developer (D-04), so the second device user starts with
 * `is_developer = false`. This command is the operator path to flip
 * that flag for an existing username — pair it with
 * `beatrax:reset-password` when standing up a new partner account.
 *
 * The runner is whitelisted as DESTRUCTIVE in the Dev Console and
 * the Dev Console wraps every destructive run in the triple-gate
 * modal (typed-app-name confirmation + advanced-toggle +
 * dev-mode-on). At the CLI there is no triple gate; the operator
 * is expected to know what they are doing.
 *
 * Idempotent: running against a user who is already a developer
 * reports "Already a developer" and exits SUCCESS.
 */
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

        if ($user->is_developer === true) {
            $this->info("Already a developer: {$username}");

            return self::SUCCESS;
        }

        $user->fill(['is_developer' => true])->save();

        $this->info("Granted developer to {$username}");

        return self::SUCCESS;
    }
}
