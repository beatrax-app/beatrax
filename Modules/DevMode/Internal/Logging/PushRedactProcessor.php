<?php

declare(strict_types=1);

namespace Modules\DevMode\Internal\Logging;

use Illuminate\Container\Container;
use Illuminate\Log\Logger;
use Monolog\Handler\ProcessableHandlerInterface;
use Monolog\Logger as MonologLogger;

/**
 * Laravel-style "tap class" — registered into the `tap` array of a
 * logging channel inside config/logging.php. Laravel resolves the
 * tap class on every channel boot and invokes `__invoke($logger)`
 * so the channel's Monolog handlers can be decorated AFTER they are
 * constructed by the channel driver.
 *
 * This tap resolves {@see RedactSecretsProcessor} from the container
 * and pushes it onto every handler of the tapped channel so every
 * record that flows through the channel passes through the OAuth
 * scrub-set + Bearer + JWT scrub BEFORE the formatter writes the
 * line to disk.
 *
 * Container resolution (rather than `new RedactSecretsProcessor`)
 * keeps the binding's constructor dependencies invisible to this
 * tap class: a future change to the processor's DI chain requires
 * no edit here or in config/logging.php.
 *
 * Laravel instantiates tap classes with `new $tap()` (per
 * Illuminate\\Log\\LogManager::tap), so the constructor MUST accept
 * no required arguments. Container access goes through
 * Container::getInstance()->make(...) rather than the app() global
 * helper to honour the project's DI-only rule.
 */
final class PushRedactProcessor
{
    public function __invoke(Logger $logger): void
    {
        /** @var RedactSecretsProcessor $processor */
        $processor = Container::getInstance()->make(RedactSecretsProcessor::class);

        $underlying = $logger->getLogger();

        // Laravel's Illuminate\Log\Logger::getLogger() is typed as
        // `\Psr\Log\LoggerInterface` but in every Monolog-driven
        // channel (single, daily, stack, etc.) the concrete is a
        // `Monolog\Logger`. The instanceof check narrows the type for
        // Larastan and skips the rare non-Monolog channel (e.g. a
        // future custom PSR-3 logger that doesn't expose handlers)
        // without throwing.
        if (! $underlying instanceof MonologLogger) {
            return;
        }

        foreach ($underlying->getHandlers() as $handler) {
            // Only ProcessableHandlerInterface handlers accept processors;
            // most concrete Monolog handlers (StreamHandler, RotatingFileHandler,
            // SyslogHandler, ErrorLogHandler, NullHandler) implement it via
            // the ProcessableHandlerTrait. A handler that doesn't (e.g. a
            // bare custom adapter that only implements HandlerInterface) is
            // silently skipped rather than crashing channel boot.
            if ($handler instanceof ProcessableHandlerInterface) {
                $handler->pushProcessor($processor);
            }
        }
    }
}
