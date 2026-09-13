<?php

declare(strict_types=1);

namespace Modules\Notifications\Internal\Support;

use Modules\Core\Models\User;
use Modules\Core\Public\Support\SafeExceptionContext;
use Psr\Log\LoggerInterface;
use Throwable;

// Every scheduled notification pass walks every user, and a throw partway
// through used to end the walk: the readers after the one it hit got nothing,
// hour after hour, with no row anywhere saying so. One reader's failure is
// theirs alone, so it is caught here and the walk carries on past them.
final readonly class PerUserPass
{
    public function __construct(
        private LoggerInterface $log,
    ) {}

    /**
     * @param  callable(User): void  $handle
     * @return int the readers the pass could not finish for
     */
    public function each(string $pass, callable $handle): int
    {
        $failed = 0;

        User::query()->lazyById(100)->each(function (User $user) use ($pass, $handle, &$failed): void {
            $failed += $this->one($pass, $handle, $user);
        });

        return $failed;
    }

    /**
     * @param  callable(User): void  $handle
     */
    private function one(string $pass, callable $handle, User $user): int
    {
        try {
            $handle($user);
        } catch (Throwable $e) {
            $this->log->warning(
                $pass.': the pass could not finish for one reader; the readers after them still ran.',
                ['user_id' => $user->id] + SafeExceptionContext::describe($e),
            );

            return 1;
        }

        return 0;
    }
}
