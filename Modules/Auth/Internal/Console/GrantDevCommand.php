<?php

declare(strict_types=1);

namespace Modules\Auth\Internal\Console;

use Illuminate\Console\Command;
use Modules\Auth\Public\Support\Username;
use Modules\Core\Models\User;

// The Dev Console gates the equivalent action behind a triple-confirm modal.
// There is deliberately no such gate at the CLI.
class GrantDevCommand extends Command
{
    /** @var string */
    protected $signature = 'beatrax:grant-dev {username : Username to promote to developer}';

    /** @var string */
    protected $description = 'Grant developer access (is_developer=true) to a user.';

    public function handle(): int
    {
        // Larastan narrows the required `username` argument to string from the
        // typed signature, so no is_string() guard is needed.
        $username = Username::normalize($this->argument('username'));
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
