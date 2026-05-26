<?php

declare(strict_types=1);

namespace Modules\Desktop\Internal\Http\Livewire;

use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use Modules\Core\Public\Contracts\CurrentUser;
use Modules\Desktop\Internal\Native\WindowCloseBehavior;

/**
 * The D-08 first-close prompt — Quit vs Keep-in-tray.
 *
 * On the first window-close (close_behavior IS NULL), the user is
 * shown a `flux:modal` with two equally-prominent choices:
 *
 *   - "Quit beatrax" (destructive, rose-600, NOT default-focused)
 *   - "Keep running in the tray" (primary, emerald-600, default-focused)
 *
 * Plus a "Remember my choice" checkbox (checked by default). When the
 * user picks an action with `rememberChoice = true`, the choice
 * persists to `users.close_behavior` via `WindowCloseBehavior` and
 * future closes apply the choice without re-prompting.
 *
 * Keeping the app in the tray is the calmer default because it keeps
 * the D-05 bundled queue worker and the D-06 timer-based email scan
 * alive — closing entirely silently stops both. The visual hierarchy
 * (default-focused emerald button vs rose-styled secondary) reflects
 * this.
 *
 * Livewire constraint: a Component subclass cannot have a constructor
 * under `phpstan-strict-rules` — collaborators arrive as render() /
 * action-method parameters. The `CurrentUser` contract resolves the
 * authenticated user; the `WindowCloseBehavior` service owns the
 * persistence write (with its built-in allow-list guard).
 */
final class CloseWindowPrompt extends Component
{
    /** Verbatim UI-SPEC modal title (locked copy). */
    public const TITLE = 'Keep beatrax running?';

    /** Verbatim UI-SPEC modal body. */
    public const BODY = 'Closing the window can either quit beatrax completely or keep it running quietly in the menu bar so scheduled email scans continue.';

    /** Verbatim UI-SPEC "Quit beatrax" button label. */
    public const BUTTON_QUIT = 'Quit beatrax';

    /** Verbatim UI-SPEC "Keep running in the tray" button label. */
    public const BUTTON_KEEP_IN_TRAY = 'Keep running in the tray';

    /** Verbatim UI-SPEC "Remember my choice" checkbox label. */
    public const CHECKBOX_REMEMBER = 'Remember my choice';

    /** Modal name (the flux:modal `name=...` attribute the close-button toggle dispatches against). */
    public const MODAL_NAME = 'close-window-prompt';

    /**
     * Whether the user wants the choice persisted to
     * `users.close_behavior`. Default true — the partner expects "I
     * picked once, don't ask me again" to be the calm default.
     */
    public bool $rememberChoice = true;

    /**
     * Auto-opens the flux:modal as soon as the page renders.
     *
     * The close-prompt route is only ever navigated to by the
     * NativePHP-bundle close-intercept hook on a first close (when
     * `users.close_behavior IS NULL`). Without this dispatch the page
     * would render an invisible modal element — the dialog would not
     * appear and the user would face a blank screen. Dispatching
     * `modal-show` from `mount()` makes the dialog surface immediately
     * on navigation; Flux's modal listens for the event by `name`.
     */
    public function mount(): void
    {
        $this->dispatch('modal-show', name: self::MODAL_NAME);
    }

    /**
     * "Keep running in the tray" action. Persists `close_behavior='tray'`
     * when `rememberChoice` is true; the actual window-hide call lives
     * in `NativeAppServiceProvider` (the single facade-bearing file)
     * which subscribes to the dispatched event surface.
     */
    public function chooseKeepInTray(CurrentUser $currentUser, WindowCloseBehavior $behavior): void
    {
        if ($this->rememberChoice) {
            $behavior->persistChoice($currentUser->user(), WindowCloseBehavior::CHOICE_TRAY);
        }

        $this->dispatch('close-window-choice', choice: WindowCloseBehavior::CHOICE_TRAY);
        $this->dispatch('modal-close', name: self::MODAL_NAME);
    }

    /**
     * "Quit beatrax" action. Persists `close_behavior='quit'` when
     * `rememberChoice` is true; the actual `App::quit()` call lives in
     * `NativeAppServiceProvider` listening for the dispatched event.
     */
    public function chooseQuit(CurrentUser $currentUser, WindowCloseBehavior $behavior): void
    {
        if ($this->rememberChoice) {
            $behavior->persistChoice($currentUser->user(), WindowCloseBehavior::CHOICE_QUIT);
        }

        $this->dispatch('close-window-choice', choice: WindowCloseBehavior::CHOICE_QUIT);
        $this->dispatch('modal-close', name: self::MODAL_NAME);
    }

    public function render(ViewFactory $views): View
    {
        return $views->make('desktop::close-window-prompt', [
            'title' => self::TITLE,
            'body' => self::BODY,
            'buttonQuit' => self::BUTTON_QUIT,
            'buttonKeepInTray' => self::BUTTON_KEEP_IN_TRAY,
            'checkboxRemember' => self::CHECKBOX_REMEMBER,
            'modalName' => self::MODAL_NAME,
        ]);
    }
}
