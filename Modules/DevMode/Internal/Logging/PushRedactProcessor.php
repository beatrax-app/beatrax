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

        // getLogger() is typed to the PSR interface, so the instanceof both
        // narrows it and skips any non-Monolog channel.
        if (! $underlying instanceof MonologLogger) {
            return;
        }

        foreach ($underlying->getHandlers() as $handler) {
            // A custom handler without ProcessableHandlerTrait is skipped
            // rather than crashing channel boot.
            if ($handler instanceof ProcessableHandlerInterface) {
                $handler->pushProcessor($processor);
            }
        }
    }
}
