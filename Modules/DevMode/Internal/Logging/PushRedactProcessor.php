<?php

declare(strict_types=1);

namespace Modules\DevMode\Internal\Logging;

use Illuminate\Container\Container;
use Illuminate\Log\Logger;
use Monolog\Handler\ProcessableHandlerInterface;
use Monolog\Logger as MonologLogger;

/**
 * Laravel-style "tap class" — registered into the `tap` array of a
 * logging channel inside `config/logging.php`. Laravel resolves the
 * tap class on every channel boot and invokes `__invoke($logger)` so
 * the channel's Monolog handlers can be decorated AFTER they are
 * constructed by the channel driver.
 *
 * Our job here: resolve {@see RedactSecretsProcessor} from the
 * container and push it onto every handler of the tapped channel so
 * every record that flows through the channel passes through the
 * Bearer + JWT scrub BEFORE it reaches the formatter.
 *
 * The container-resolve step is the load-bearing detail: in 16-05,
 * `RedactSecretsProcessor`'s constructor gains `OAuthScrubSet`.
 * Because this tap class never `new`s the processor directly, the
 * upgrade requires no edit to THIS file OR to `config/logging.php`.
 * Both stay stable across the 16-04b baseline → 16-05 full-scrub-set
 * upgrade.
 *
 * Laravel instantiates tap classes with `new $tap()` (per the
 * framework's `Illuminate\Log\LogManager::tap()` source), so the
 * constructor MUST accept no required arguments. Container access
 * here goes through `Container::getInstance()->make(...)` rather than
 * the `app()` global helper to stay larastan-strict-rules clean
 * (the project bans the `app()` helper).
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
