<?php

declare(strict_types=1);

namespace Modules\Auth\Internal\Console;

use Illuminate\Console\Command;
use Illuminate\Contracts\Hashing\Hasher;
use Illuminate\Database\DatabaseManager;
use Modules\Core\Models\User;

/**
 * Interactive CLI fallback for resetting a user's password.
 *
 * A local-only install has no SMTP and no other operator-driven way back
 * in if every recovery code is spent: `beatrax:reset-password <username>`
 * is that path. It prompts for the new password twice through hidden
 * input, validates the two entries match and meet the twelve-character
 * minimum, writes the new hash, and sets
 * `force_password_change_at_next_login = true` so the user is asked to
 * choose their own password the first time they sign in.
 *
 * The command refuses to run non-interactively: there is no
 * `--password` option, and a non-interactive invocation exits with a
 * failure code without touching the password. That keeps a scripted
 * reset on an unattended machine from silently rewriting a password.
 */
class ResetPasswordCommand extends Command
{
    private const MINIMUM_PASSWORD_LENGTH = 12;

    /** @var string */
    protected $signature = 'beatrax:reset-password {username : Username of the account to reset}';

    /** @var string */
    protected $description = 'Interactively reset a user password. Refuses non-interactive use.';

    public function __construct(
        private readonly DatabaseManager $db,
        private readonly Hasher $hasher,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        if (! $this->input->isInteractive()) {
            $this->error('beatrax:reset-password must be run interactively; there is no password flag.');

            return self::FAILURE;
        }

        // The required `username` argument is narrowed to a string by
        // Larastan against the typed signature, so no is_string() guard
        // is needed here.
        $username = strtolower(trim($this->argument('username')));

        $user = User::query()->where('username', $username)->first();

        if (! $user instanceof User) {
            $this->error('No user with that username.');

            return self::FAILURE;
        }

        $passwordInput = $this->secret('New password');
        $confirmInput = $this->secret('Confirm new password');
        $password = is_string($passwordInput) ? $passwordInput : '';
        $confirm = is_string($confirmInput) ? $confirmInput : '';

        if ($password !== $confirm) {
            $this->error('The two passwords do not match.');

            return self::FAILURE;
        }

        if (strlen($password) < self::MINIMUM_PASSWORD_LENGTH) {
            $this->error('Use at least 12 characters.');

            return self::FAILURE;
        }

        $this->db->connection()->table('users')
            ->where('id', $user->id)
            ->update([
                'password' => $this->hasher->make($password),
                'force_password_change_at_next_login' => true,
            ]);

        $this->info("Password updated for {$user->username}.");

        return self::SUCCESS;
    }
}
