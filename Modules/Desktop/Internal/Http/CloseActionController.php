<?php

declare(strict_types=1);

namespace Modules\Desktop\Internal\Http;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Modules\Desktop\Internal\Listeners\ApplyCloseWindowChoice;
use Modules\Desktop\Internal\Native\WindowCloseBehavior;

/**
 * Applies a user-selected close-window choice to the native shell
 * (D-08, JS glue deferred from plan 15-03).
 *
 * The `CloseWindowPrompt` Livewire component dispatches a browser
 * event with the chosen action; the in-layout JS hook
 * (resources/views/layouts/app.blade.php) POSTs the choice here. The
 * controller's only job is allow-list-validation + dispatching to the
 * native facade listener.
 *
 * Allow-list defence-in-depth: the choice is re-validated against
 * `WindowCloseBehavior::CHOICE_*` constants before any facade call —
 * a forged POST cannot reach `App::quit()` or `Window::current()->hide()`
 * with anything other than the sanctioned strings (T-15-22 in the
 * plan 15-03 threat register).
 */
final class CloseActionController
{
    /** Allow-listed choice strings. */
    private const ALLOWED = [
        WindowCloseBehavior::CHOICE_QUIT,
        WindowCloseBehavior::CHOICE_TRAY,
    ];

    public function __construct(
        private readonly ApplyCloseWindowChoice $applyChoice,
    ) {}

    public function __invoke(Request $request): Response
    {
        $choice = $request->input('choice');
        if (! is_string($choice) || ! in_array($choice, self::ALLOWED, true)) {
            return new Response('', 422);
        }

        $this->applyChoice->apply($choice);

        return new Response('', 204);
    }
}
