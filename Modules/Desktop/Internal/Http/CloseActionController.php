<?php

declare(strict_types=1);

namespace Modules\Desktop\Internal\Http;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Modules\Desktop\Internal\Listeners\ApplyCloseWindowChoice;
use Modules\Desktop\Internal\Native\WindowCloseBehavior;
use Psr\Log\LoggerInterface;

// Re-validates the choice against WindowCloseBehavior::CHOICE_* before
// any facade call — a forged POST cannot reach App::quit() or
// Window::current()->hide() with anything other than the two
// sanctioned strings.
final class CloseActionController
{
    private const ALLOWED = [
        WindowCloseBehavior::CHOICE_QUIT,
        WindowCloseBehavior::CHOICE_TRAY,
    ];

    public function __construct(
        private readonly ApplyCloseWindowChoice $applyChoice,
        private readonly LoggerInterface $logger,
    ) {}

    public function __invoke(Request $request): Response
    {
        $choice = $request->input('choice');
        if (! is_string($choice) || ! in_array($choice, self::ALLOWED, true)) {
            // A rejected close-action payload would otherwise be silent
            // — the JS hook ignores the response, so the user sees no
            // signal. Logging the rejection gives a future debugging
            // session a starting point.
            $this->logger->warning(
                'desktop.close-action received an off-allow-list choice; ignoring.',
                ['choice' => is_string($choice) ? $choice : gettype($choice)],
            );

            return new Response('', 422);
        }

        $this->applyChoice->apply($choice);

        return new Response('', 204);
    }
}
