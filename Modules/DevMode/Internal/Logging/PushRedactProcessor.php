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
