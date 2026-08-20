<?php

declare(strict_types=1);

namespace Modules\DevMode\Internal\Logging;

use Illuminate\Container\Container;
use Illuminate\Log\Logger;
use Monolog\Handler\ProcessableHandlerInterface;
use Monolog\Logger as MonologLogger;

final class PushRedactProcessor
{
    public function __invoke(Logger $logger): void
    {
        /** @var RedactSecretsProcessor $processor */
        $processor = Container::getInstance()->make(RedactSecretsProcessor::class);

        $underlying = $logger->getLogger();

        // Illuminate\Log\Logger::getLogger() is typed as a generic PSR
        // logger interface, but every Monolog-driven channel (single,
        // daily, stack, etc.) is concretely a Monolog\Logger; the
        // instanceof check narrows the type and skips any other channel.
        if (! $underlying instanceof MonologLogger) {
            return;
        }

        foreach ($underlying->getHandlers() as $handler) {
            // Most concrete Monolog handlers implement
            // ProcessableHandlerInterface via ProcessableHandlerTrait; a
            // bare custom adapter that doesn't is silently skipped
            // rather than crashing channel boot.
            if ($handler instanceof ProcessableHandlerInterface) {
                $handler->pushProcessor($processor);
            }
        }
    }
}
